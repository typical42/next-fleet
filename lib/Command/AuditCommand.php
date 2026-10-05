<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One vehicle's audit trail, its own rows and its trips'. Uids print as stored, pseudonyms
 * included: an admin already holds the database.
 */
class AuditCommand extends Command {
	public function __construct(
		private VehicleMapper $vehicles,
		private TripMapper $trips,
		private AuditMapper $audit,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:audit')
			->setDescription('Print a vehicle\'s audit trail, oldest first')
			->addArgument('vehicle', InputArgument::REQUIRED, 'the vehicle\'s uuid (nextfleet:vehicles)')
			->addOption('entry', null, InputOption::VALUE_REQUIRED, 'only the rows about this trip, or about the vehicle itself when given its uuid')
			->addOption('since', null, InputOption::VALUE_REQUIRED, 'only the rows written on or after this day, YYYY-MM-DD in UTC');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}
		$since = $input->getOption('since');
		$day = is_string($since) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $since, new \DateTimeZone('UTC')) : null;
		// The round trip refuses what createFromFormat() would roll over, such as 2026-02-31.
		if (is_string($since) && ($day === false || $day->format('Y-m-d') !== $since)) {
			Format::error($output, '--since takes a day as YYYY-MM-DD, not ' . $since);

			return self::INVALID;
		}
		$uuid = (string)$input->getArgument('vehicle');
		try {
			$vehicle = $this->vehicles->findAnyByUuid($uuid);
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has the uuid ' . $uuid);

			return self::INVALID;
		}
		$vehicleId = (int)$vehicle->getId();
		$entry = $input->getOption('entry');
		$only = null;
		if (is_string($entry)) {
			try {
				$only = $entry === $vehicle->getUuid()
					? [Audit::VEHICLE, $vehicleId]
					: [Audit::TRIP, (int)$this->trips->findAnyOnVehicle($vehicleId, $entry)->getId()];
			} catch (DoesNotExistException) {
				Format::error($output, 'Neither the vehicle nor any of its trips has the uuid ' . $entry);

				return self::INVALID;
			}
		}

		$from = $day instanceof \DateTimeImmutable ? $day->getTimestamp() : 0;
		$rows = $only === null
			? $this->audit->findForVehicle($vehicleId, $from)
			: array_values(array_filter($this->audit->findForEntity(...$only), static fn (Audit $row): bool => $row->getCreatedAt() >= $from));
		$tripIds = array_values(array_unique(array_map(
			static fn (Audit $row): int => $row->getEntityId(),
			array_filter($rows, static fn (Audit $row): bool => $row->getEntity() === Audit::TRIP),
		)));
		$trips = $this->trips->findAnyByIds($vehicleId, $tripIds);
		$uuidOf = static fn (Audit $row): ?string => $row->getEntity() === Audit::VEHICLE
			? $vehicle->getUuid()
			: ($trips[$row->getEntityId()] ?? null)?->getUuid();

		$listed = array_map(static fn (Audit $row): array => [
			'created_at' => Format::instant($row->getCreatedAt()),
			'created_by' => $row->getCreatedBy(),
			'entity' => $row->getEntity(),
			'uuid' => $uuidOf($row),
			'change' => is_string($row->getDiffJson()['change'] ?? null) ? $row->getDiffJson()['change'] : null,
			'fields' => self::fields($row),
		], $rows);
		if ($format->isJson()) {
			// An empty PHP array encodes as [], and `fields` is a map.
			$format->json($output, array_map(static fn (array $row): array => array_merge($row, ['fields' => (object)$row['fields']]), $listed));
		} else {
			$format->rows($output, array_map(static fn (array $row): array => array_merge($row, ['fields' => implode("\n", array_map(
				static fn (string $column, array $pair): string => $column . ': ' . self::value($pair['before']) . ' → ' . self::value($pair['after']),
				array_keys($row['fields']),
				$row['fields'],
			))]), $listed), 'No audit rows for this vehicle.');
		}

		return self::SUCCESS;
	}

	/**
	 * Each changed column, which the writing services store as `[before, after]`.
	 *
	 * @return array<string, array{before: mixed, after: mixed}>
	 */
	private static function fields(Audit $row): array {
		$fields = $row->getDiffJson()['fields'] ?? [];

		return is_array($fields) ? array_map(
			static fn (mixed $pair): array => is_array($pair) ? ['before' => $pair[0] ?? null, 'after' => $pair[1] ?? null] : ['before' => null, 'after' => $pair],
			$fields,
		) : [];
	}

	/** As JSON writes it, so the string "1" and the number 1 never look alike. */
	private static function value(mixed $value): string {
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
