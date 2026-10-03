<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Import\CsvReader;
use OCA\NextFleet\Import\Duplicates;
use OCA\NextFleet\Import\IImporter;
use OCA\NextFleet\Import\Importers;
use OCA\NextFleet\Import\Proposal;
use OCA\NextFleet\Import\Proposals;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\Files\File;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Another tool's export, read from the caller's own Files into the entries it would become
 * (docs/architecture.md#import). Stateless: the preview stores nothing, and the import reads the
 * file again, checked against the etag the preview answered.
 *
 * @psalm-import-type NextFleetImportPreview from \OCA\NextFleet\ResponseDefinitions as Preview
 * @psalm-import-type NextFleetImportResult from \OCA\NextFleet\ResponseDefinitions as Result
 * @psalm-import-type NextFleetImportUndone from \OCA\NextFleet\ResponseDefinitions as Undone
 */
class ImportService {
	use TTransactional;

	/** What a preview shows of the rows; the counts cover them all. */
	public const SAMPLE = 50;

	public function __construct(
		private VehicleService $fleet,
		private Importers $importers,
		private OwnFiles $files,
		private EnergyMapper $energyRows,
		private MaintenanceMapper $maintenanceRows,
		private ExpenseMapper $expenseRows,
		private OdoReadingMapper $readingRows,
		private EnergyService $energy,
		private MaintenanceService $maintenance,
		private ExpenseService $expenses,
		private OdometerService $odometer,
		private VehicleMapper $vehicles,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What importing the file would do, row by row, and what is still to be answered first.
	 * Importing is `edit`: it writes many entries at once, other people's history among them.
	 *
	 * @param array<string, mixed> $fields `file_id`, `importer`, `record_type`, `units`, `tz`, and
	 *                                     the answers `date_order`, `energy`, `category_map`,
	 *                                     `include_duplicates`
	 * @return Preview
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws DoesNotExistException if the vehicle, or the file in the user's own Files, is not there
	 * @throws ImportRefusedException if the file is not one an import reads
	 * @throws \OCP\Lock\LockedException while somebody writes the file
	 * @throws \InvalidArgumentException if a field or an answer is not one the request takes
	 * @throws \OCP\DB\Exception
	 */
	public function preview(string $userId, string $vehicleUuid, array $fields): array {
		[$vehicle, $importer, $recordType, $file, $includeDuplicates] = $this->target($userId, $vehicleUuid, $fields);
		[$columns, $proposals] = $this->propose($vehicle, $importer, $recordType, $file, $fields);
		$all = $this->marked($vehicle, $proposals->all);
		[$counts, $reasons] = self::counted($all, $includeDuplicates);
		$open = $proposals->open;

		// Lists, not maps: PHP turns a header or a reason such as `2024` into an integer key, and
		// an empty map would leave as a JSON list. Hence the casts back to string, too.
		return [
			'importer' => $importer->key(),
			'record_type' => $recordType,
			'columns' => [
				'placed' => array_map(
					static fn (string|int $header, string $field): array => ['header' => (string)$header, 'field' => $field],
					array_keys($columns['placed']),
					array_values($columns['placed']),
				),
				'ignored' => $columns['ignored'],
			],
			'questions' => array_map(
				static fn (string $name, array $choices): array => ['name' => $name, 'choices' => $choices],
				array_keys($open),
				array_values($open),
			),
			'categories' => $proposals->categories,
			'category_defaults' => array_map(
				static fn (string|int $text, string $meaning): array => ['text' => (string)$text, 'meaning' => $meaning],
				array_keys($proposals->defaults),
				array_values($proposals->defaults),
			),
			'counts' => $counts,
			'reasons' => array_map(
				static fn (string|int $reason, int $count): array => ['reason' => (string)$reason, 'count' => $count],
				array_keys($reasons),
				array_values($reasons),
			),
			'proposals' => array_map(
				static fn (Proposal $proposal): array => $proposal->wire(),
				array_slice($all, 0, self::SAMPLE),
			),
			'etag' => $file->getEtag(),
		];
	}

	/**
	 * Writes what the preview with the same answers counted as created (docs/architecture.md#import).
	 *
	 * @param array<string, mixed> $fields the preview's, and the `etag` it answered
	 * @return Result
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws DoesNotExistException if the vehicle, or the file in the user's own Files, is not there
	 * @throws FileChangedException if the file is not the one the preview read
	 * @throws ImportRefusedException if the file is not one an import reads
	 * @throws \OCP\Lock\LockedException while somebody writes the file
	 * @throws \InvalidArgumentException if a field or an answer is not one the request takes, a
	 *                                   question is still open, or an entry service refuses a row
	 * @throws \OCP\DB\Exception
	 */
	public function import(string $userId, string $vehicleUuid, array $fields): array {
		[$vehicle, $importer, $recordType, $file, $includeDuplicates] = $this->target($userId, $vehicleUuid, $fields);
		$etag = $fields['etag'] ?? null;
		if (!is_string($etag) || $etag === '') {
			throw new \InvalidArgumentException('etag is the one the preview answered');
		}
		if ($etag !== $file->getEtag()) {
			throw new FileChangedException('The file changed since the preview');
		}
		[, $proposals] = $this->propose($vehicle, $importer, $recordType, $file, $fields);
		if ($proposals->open !== []) {
			throw new \InvalidArgumentException('Still to be answered: ' . implode(', ', array_keys($proposals->open)));
		}

		// Marked under the hold, so a row somebody entered since the preview is a duplicate here.
		[$all, $created] = $this->atomicRetry(function () use ($userId, $vehicle, $proposals, $includeDuplicates): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$all = $this->marked($vehicle, $proposals->all);
			$created = $this->odometer->batch($vehicle, fn (): array => array_map(
				fn (Proposal $proposal): array => ['type' => $proposal->kind, 'uuid' => $this->write($vehicle, $userId, $proposal)],
				array_values(array_filter($all, static fn (Proposal $proposal): bool => $proposal->creates($includeDuplicates))),
			));

			return [$all, $created];
		}, $this->db);

		// docs/security.md#what-is-logged
		$this->logger->info('Import', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => $vehicleUuid,
			'importer' => $importer->key(),
			'record_type' => $recordType,
			'rows' => count($created),
		]);

		return ['counts' => self::counted($all, $includeDuplicates)[0], 'created' => $created];
	}

	/**
	 * Takes back what one import created, named by the list its answer carried: all of it or
	 * nothing, each entry as its delete route would (docs/architecture.md#import).
	 *
	 * @param array<string, mixed> $fields `created`, the import's answer's list
	 * @return Undone
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws DoesNotExistException if the vehicle is not there
	 * @throws ImportChangedException if an entry is not one the import left live
	 * @throws \InvalidArgumentException if `created` is not such a list
	 * @throws \OCP\DB\Exception
	 */
	public function undo(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$created = self::created($fields['created'] ?? null);

		return $this->atomicRetry(function () use ($userId, $vehicle, $created): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$this->odometer->batch($vehicle, function () use ($userId, $vehicle, $created): void {
				foreach ($created as ['type' => $kind, 'uuid' => $uuid]) {
					$this->remove($vehicle, $userId, $kind, $uuid);
				}
			});

			return ['undone' => count($created)];
		}, $this->db);
	}

	/**
	 * The list an undo names, each entry once.
	 *
	 * @return list<array{type: value-of<Proposal::KINDS>, uuid: string}>
	 * @throws \InvalidArgumentException
	 */
	private static function created(mixed $created): array {
		if (!is_array($created) || !array_is_list($created) || $created === [] || count($created) > CsvReader::MAX_ROWS) {
			throw new \InvalidArgumentException('created is the list an import answered');
		}
		$seen = [];
		foreach ($created as $entry) {
			$kind = is_array($entry) ? ($entry['type'] ?? null) : null;
			$uuid = is_array($entry) ? ($entry['uuid'] ?? null) : null;
			if (!in_array($kind, Proposal::KINDS, true) || !is_string($uuid) || $uuid === '') {
				throw new \InvalidArgumentException('created holds {type, uuid} as an import answered them');
			}
			if (isset($seen[$uuid])) {
				throw new \InvalidArgumentException('created names ' . $uuid . ' twice');
			}
			$seen[$uuid] = true;
		}

		/** @var list<array{type: value-of<Proposal::KINDS>, uuid: string}> */
		return $created;
	}

	/**
	 * One entry the import created, deleted through its own service. The caller holds the vehicle.
	 *
	 * @param value-of<Proposal::KINDS> $kind
	 * @throws ImportChangedException
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	private function remove(Vehicle $vehicle, string $userId, string $kind, string $uuid): void {
		try {
			$entry = $this->rows($kind)->findOnVehicle((int)$vehicle->getId(), $uuid);
		} catch (DoesNotExistException) {
			$entry = null;
		}
		// Somebody else's entry is not this import's, whatever the caller's role would allow, and a
		// Reading a fill-up wrote goes with its fill-up. One answer for every case, so a uuid from
		// another vehicle tells nothing about it.
		if ($entry === null || $entry->getCreatedBy() !== $userId
			|| ($entry instanceof OdoReading && $entry->getSourceType() !== OdoReading::MANUAL)) {
			throw new ImportChangedException($kind . ' ' . $uuid . ' is not a live entry of the caller\'s on this vehicle');
		}
		$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $entry->getCreatedBy());
		$token = $entry->getUpdatedAt();
		match (true) {
			$entry instanceof Energy => $this->energy->remove($vehicle, $userId, $entry, $token),
			$entry instanceof Maintenance => $this->maintenance->remove($vehicle, $userId, $entry, $token),
			$entry instanceof Expense => $this->expenses->remove($entry, $token),
			$entry instanceof OdoReading => $this->odometer->remove($vehicle, $entry, $token),
		};
	}

	/** The mapper of one kind of entry, a Proposal's. */
	private function rows(string $kind): EnergyMapper|MaintenanceMapper|ExpenseMapper|OdoReadingMapper {
		return match ($kind) {
			Proposal::ENERGY => $this->energyRows,
			Proposal::MAINTENANCE => $this->maintenanceRows,
			Proposal::EXPENSE => $this->expenseRows,
			Proposal::ODOMETER => $this->readingRows,
		};
	}

	/**
	 * The vehicle, the importer, the record type and the file a request names, each checked, and
	 * whether duplicates are to be created.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{Vehicle, IImporter, string, File, bool}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws DoesNotExistException
	 * @throws ImportRefusedException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function target(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		if ($vehicle->getLifecycle() === Vehicle::DISPOSED) {
			throw new \InvalidArgumentException('A disposed vehicle takes no import');
		}
		$importer = $this->importers->get($fields['importer'] ?? null);
		$recordType = Field::word('record_type', $fields['record_type'] ?? null, $importer->recordTypes());
		$includeDuplicates = Field::flag('include_duplicates', $fields['include_duplicates'] ?? false);
		$file = $this->files->find($userId, OwnFiles::id($fields['file_id'] ?? null));
		// Before a byte is read; the reader counts them too, for a file that grows meanwhile.
		if ($file->getSize() > CsvReader::MAX_BYTES) {
			throw new ImportRefusedException('too_large');
		}

		return [$vehicle, $importer, $recordType, $file, $includeDuplicates];
	}

	/**
	 * The file read into proposals, with the user's answers and what the vehicle says without
	 * being asked.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{array{placed: array<string, string>, ignored: list<string>}, Proposals}
	 * @throws ImportRefusedException
	 * @throws \OCP\Lock\LockedException
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	private function propose(Vehicle $vehicle, IImporter $importer, string $recordType, File $file, array $fields): array {
		$answers = [
			'units' => $fields['units'] ?? null,
			'date_order' => $fields['date_order'] ?? null,
			'energy' => $fields['energy'] ?? null,
			'category_map' => $fields['category_map'] ?? [],
			'tz' => $fields['tz'] ?? null,
			'currency' => $vehicle->getCurrency(),
			'energies' => $vehicle->getEnergyTypes() ?? [],
		];
		$stream = $this->files->open($file);
		try {
			$reader = CsvReader::open($stream, $importer->escape());

			return [
				$importer->columns($recordType, $reader->header),
				$importer->propose($recordType, $reader->header, $reader->rows(), $answers),
			];
		} finally {
			fclose($stream);
		}
	}

	/**
	 * Every row by outcome, `creates` what an import with these answers writes, and how many rows
	 * each reason left out.
	 *
	 * @param list<Proposal> $all
	 * @return array{array{new: int, duplicate: int, unreadable: int, creates: int}, array<string, int>}
	 */
	private static function counted(array $all, bool $includeDuplicates): array {
		$counts = ['new' => 0, 'duplicate' => 0, 'unreadable' => 0, 'creates' => 0];
		$reasons = [];
		foreach ($all as $proposal) {
			match ($proposal->outcome) {
				null => $counts['new']++,
				Proposal::DUPLICATE => $counts['duplicate']++,
				default => $counts['unreadable']++,
			};
			if ($proposal->creates($includeDuplicates)) {
				$counts['creates']++;
			}
			if ($proposal->reason !== null) {
				$reasons[$proposal->reason] = ($reasons[$proposal->reason] ?? 0) + 1;
			}
		}

		return [$counts, $reasons];
	}

	/**
	 * One proposal through its entry service, as the entry sheet would send it.
	 *
	 * @return string the entry's uuid
	 * @throws \InvalidArgumentException naming the row, if the service refuses it
	 * @throws \OCP\DB\Exception
	 */
	private function write(Vehicle $vehicle, string $userId, Proposal $proposal): string {
		try {
			return match ($proposal->kind) {
				Proposal::ENERGY => $this->energy->add($vehicle, $userId, $proposal->fields)['uuid'],
				Proposal::MAINTENANCE => $this->maintenance->add($vehicle, $userId, $proposal->fields)['uuid'],
				Proposal::EXPENSE => $this->expenses->add($vehicle, $userId, $proposal->fields)['uuid'],
				Proposal::ODOMETER => $this->odometer->add($vehicle, $userId, $proposal->fields)->getUuid(),
			};
		} catch (\InvalidArgumentException $e) {
			// An importer that hands a service what it refuses is a bug of the importer's; the
			// whole import stops rather than leave a row out that the preview counted in.
			throw new \InvalidArgumentException('Row ' . $proposal->row . ': ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Each proposal as the vehicle would take it: a fill-up of an energy it does not take is
	 * unreadable, since the fill-up's own rule would refuse it, and a row already there is a
	 * duplicate.
	 *
	 * @param list<Proposal> $proposals
	 * @return list<Proposal>
	 * @throws \OCP\DB\Exception
	 */
	private function marked(Vehicle $vehicle, array $proposals): array {
		$energies = $vehicle->getEnergyTypes() ?? [];
		$proposals = array_map(
			static fn (Proposal $proposal): Proposal => $proposal->kind === Proposal::ENERGY && $proposal->outcome !== Proposal::UNREADABLE
				&& !in_array($proposal->fields['energy'] ?? null, $energies, true)
				? new Proposal($proposal->kind, [], $proposal->row, Proposal::UNREADABLE, 'energy')
				: $proposal,
			$proposals,
		);

		$vehicleId = (int)$vehicle->getId();
		$live = [];
		foreach (Duplicates::span($proposals) as $kind => [$from, $to]) {
			$live = [...$live, ...$this->rows($kind)->findBetween($vehicleId, $from, $to)];
		}
		$duplicates = Duplicates::of($live);

		return array_map($duplicates->mark(...), $proposals);
	}
}
