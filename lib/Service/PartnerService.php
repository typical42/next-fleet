<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The owner's partner list ([ADR 0013](docs/adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)):
 * the owner reads, renames, merges and deletes; whoever may log on one of the owner's vehicles reads
 * a name. A trip's text never changes here, only its link.
 *
 * @psalm-import-type NextFleetPartner from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetPartnerTrips from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetPartnerGone from \OCA\NextFleet\ResponseDefinitions
 */
class PartnerService {
	use TTransactional;

	/** The refusal the sheet words as "Merge instead" (RefusedException). */
	public const NAME_TAKEN = 'name_taken';
	/** The record a merge goes into is not on the list, most likely merged or deleted meanwhile. */
	public const TARGET_GONE = 'target_gone';

	/** The CSV of a partner's trips, one line per trip. */
	private const HEADER = ['vehicle_uuid', 'plate', 'trip_uuid', 'started', 'started_offset_min', 'ended', 'ended_offset_min', 'from', 'to', 'purpose', 'partner', 'category', 'distance', 'odo_unit'];

	public function __construct(
		private PartnerMapper $partners,
		private TripMapper $trips,
		private VehicleMapper $vehicles,
		private VehicleService $fleet,
		private TripService $tripService,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The caller's own list, by name. A driver's is their own too, whatever they log on: the names
	 * of another owner's list reach the sheet through the prefill.
	 *
	 * @return list<NextFleetPartner>
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId): array {
		return array_map(static fn (Partner $partner): array => self::wire($partner, true), $this->partners->findOf($userId));
	}

	/**
	 * One record: whole to the owner, its name to whoever may log on one of the owner's vehicles.
	 *
	 * @return NextFleetPartner
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	public function show(string $userId, string $uuid): array {
		$partner = $this->partners->findByUuid($uuid);
		if ($partner->getUserId() === $userId) {
			return self::wire($partner, true);
		}
		if (!in_array($partner->getUserId(), $this->ownersLoggedFor($userId), true)) {
			throw new AccessDeniedException();
		}

		return self::wire($partner, false);
	}

	/**
	 * The owners whose lists the caller reads names from: whoever owns a vehicle they may log on.
	 * A name is a person's, and only a trip needs it; a viewer reads the trip's own text.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	public function ownersLoggedFor(string $userId): array {
		return array_values(array_unique(array_map(static fn (Vehicle $vehicle): string => $vehicle->getUserId(), self::loggable($this->fleet->list($userId)))));
	}

	/**
	 * @param list<Vehicle> $vehicles as VehicleService::list() answers them, each with its `may`
	 * @return list<Vehicle> those the caller may log on
	 */
	public static function loggable(array $vehicles): array {
		return array_values(array_filter($vehicles, static fn (Vehicle $vehicle): bool => in_array(VehicleAccess::LOG, $vehicle->getMay(), true)));
	}

	/**
	 * Renames the record and sets its address and note. The whole record, as a trip's edit is: a
	 * field left out is one emptied. A name the list holds already is refused: two records of one
	 * name are what a merge is for. Under the owner's hold, as PartnerLinks adds a name.
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetPartner
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws StaleUpdateException
	 * @throws RefusedException if another record has the name
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $uuid, int $expectedUpdatedAt, array $fields): array {
		$name = Partner::nameOf((string)Field::read('name', 'text', Partner::NAME_LENGTH, $fields['name'] ?? null))
			?? throw new \InvalidArgumentException('name is a field every partner carries');
		$address = Field::read('address', 'text', Field::TEXT, $fields['address'] ?? null);
		$note = Field::read('note', 'text', Field::TEXT, $fields['note'] ?? null);

		return $this->atomicRetry(function () use ($userId, $uuid, $expectedUpdatedAt, $name, $address, $note): array {
			$partner = $this->owned($userId, $uuid);
			$this->vehicles->holdOwner($userId);
			$taken = $this->partners->findByName($userId, $name);
			if ($taken !== null && $taken->getId() !== $partner->getId()) {
				throw new RefusedException('another partner is called ' . $name, self::NAME_TAKEN);
			}

			$partner->setName($name);
			$partner->setAddress(is_string($address) ? $address : null);
			$partner->setNote(is_string($note) ? $note : null);

			return self::wire($this->partners->updateChecked($partner, $expectedUpdatedAt), true);
		}, $this->db);
	}

	/**
	 * Merges the record into another of the list: every trip that links it links the other, the
	 * texts stay, and the record is gone. Answers with the record merged into.
	 *
	 * @return NextFleetPartner
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws StaleUpdateException
	 * @throws RefusedException TARGET_GONE if `into` is not on the list
	 * @throws \InvalidArgumentException if `into` is not another record's uuid
	 * @throws \OCP\DB\Exception
	 */
	public function merge(string $userId, string $uuid, int $expectedUpdatedAt, mixed $into): array {
		return $this->atomicRetry(function () use ($userId, $uuid, $expectedUpdatedAt, $into): array {
			$partner = $this->current($this->owned($userId, $uuid), $expectedUpdatedAt);
			$this->hold($userId, $partner);
			// Under the holds, as a delete of it holds them too.
			$target = $this->target($userId, $partner, $into);
			$this->relinkAll($userId, $this->trips->findAnyLinking((int)$partner->getId()), $target);
			$this->erase($partner, $expectedUpdatedAt);

			return self::wire($target, true);
		}, $this->db);
	}

	/**
	 * Deletes the record, which is how a private person's erasure is met: its name, address and
	 * note go, and no trip names it any more. The texts stay, as the logbook is kept for ten years
	 * (docs/legal.md). The row stays as a tombstone for sync, and each trip keeps its id as a mark
	 * (Trip::linkPartner()).
	 *
	 * @return NextFleetPartnerGone
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $uuid, int $expectedUpdatedAt): array {
		return $this->atomicRetry(function () use ($userId, $uuid, $expectedUpdatedAt): array {
			$partner = $this->current($this->owned($userId, $uuid), $expectedUpdatedAt);
			$this->hold($userId, $partner);
			// Read while the record is live, so each trip's change says which record it lost.
			$linked = $this->trips->findAnyLinking((int)$partner->getId());
			$gone = $this->erase($partner, $expectedUpdatedAt);
			$this->relinkAll($userId, $linked, $gone);

			return ['uuid' => $gone->getUuid(), 'deleted_at' => (int)$gone->getDeletedAt()];
		}, $this->db);
	}

	/**
	 * The record's live trips on the owner's vehicles that set off in a period by their own clock,
	 * as the Fahrtenbuch dates them, and the distance in each unit. The owner's alone, as the list
	 * is.
	 *
	 * @param array<string, mixed> $fields `from` and `to`, calendar days, `to` the day after the last; neither for all of it
	 * @return NextFleetPartnerTrips
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws \InvalidArgumentException if the period is not one
	 * @throws \OCP\DB\Exception
	 */
	public function trips(string $userId, string $uuid, array $fields): array {
		[$partner, $rows] = $this->tripsOf($userId, $uuid, $fields);

		$distance = [];
		foreach ($rows as [$vehicle, $trip]) {
			$per = $vehicle->getOdoUnit();
			$distance[$per] ??= ['per' => $per, 'trips' => 0, 'distance' => 0];
			$distance[$per]['trips']++;
			$distance[$per]['distance'] += (int)$trip->kilometres();
		}
		ksort($distance);

		return [
			'partner' => self::wire($partner, true),
			// Each row's distance as the sum counts it, so a client does not repeat the rule.
			'trips' => array_map(static fn (array $row): array => ['vehicle_uuid' => $row[0]->getUuid(), 'distance' => $row[1]->kilometres(), 'trip' => $row[1]->jsonSerialize()], $rows),
			'distance' => array_values($distance),
		];
	}

	/**
	 * trips() as a CSV file, one line per trip.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{name: string, body: string}
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws \InvalidArgumentException if the period is not one
	 * @throws \OCP\DB\Exception
	 */
	public function csv(string $userId, string $uuid, array $fields): array {
		[$partner, $rows] = $this->tripsOf($userId, $uuid, $fields);
		$lines = array_map(static fn (array $row): array => [
			$row[0]->getUuid(),
			$row[0]->getPlate(),
			$row[1]->getUuid(),
			LocalYear::wallClock($row[1]->getStartedAt(), $row[1]->getStartedAtOff()),
			$row[1]->getStartedAtOff(),
			LocalYear::wallClock($row[1]->getEndedAt(), $row[1]->getEndedAtOff()),
			$row[1]->getEndedAtOff(),
			$row[1]->getFromLabel(),
			$row[1]->getToLabel(),
			$row[1]->getPurpose(),
			$row[1]->getPartner(),
			$row[1]->getCategory(),
			$row[1]->kilometres(),
			$row[0]->getOdoUnit(),
		], $rows);

		// docs/security.md#what-is-logged, as every CSV export.
		$this->logger->info('CSV export', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'partner' => $uuid,
			'rows' => count($lines),
		]);

		return ['name' => 'partner-' . $partner->getUuid() . '.csv', 'body' => Csv::of(self::HEADER, $lines)];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{Partner, list<array{Vehicle, Trip}>} the trips in the order they happened
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function tripsOf(string $userId, string $uuid, array $fields): array {
		[$from, $to] = self::period($fields);
		$partner = $this->owned($userId, $uuid);
		$vehicles = [];
		foreach ($this->fleet->list($userId) as $vehicle) {
			if ($vehicle->getUserId() === $userId) {
				$vehicles[(int)$vehicle->getId()] = $vehicle;
			}
		}

		// Widened by the offsets, then cut by each trip's own day.
		[$start, $end] = LocalYear::around($from, $to);
		$rows = [];
		foreach ($this->trips->findLinkingStartedBetween(array_keys($vehicles), (int)$partner->getId(), $start, $end) as $trip) {
			$day = $trip->getStartedAt() + $trip->getStartedAtOff() * 60;
			if (($from === null || $day >= $from) && ($to === null || $day < $to)) {
				$rows[] = [$vehicles[$trip->getVehicleId()], $trip];
			}
		}

		return [$partner, $rows];
	}

	/**
	 * The period's two days as the UTC midnights a wall clock is compared with, or nulls for all of
	 * it.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{?int, ?int}
	 * @throws \InvalidArgumentException
	 */
	private static function period(array $fields): array {
		$from = $fields['from'] ?? null;
		$to = $fields['to'] ?? null;
		if (($from === null || $from === '') && ($to === null || $to === '')) {
			return [null, null];
		}
		$from = Field::day('from', $from)->getTimestamp();
		$to = Field::day('to', $to)->getTimestamp();
		if ($from >= $to) {
			throw new \InvalidArgumentException('from and to are a period, the one before the other');
		}

		return [$from, $to];
	}

	/**
	 * A live record of the caller's own list.
	 *
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	private function owned(string $userId, string $uuid): Partner {
		$partner = $this->partners->findByUuid($uuid);
		if ($partner->getUserId() !== $userId) {
			throw new AccessDeniedException();
		}

		return $partner;
	}

	/**
	 * The record, when it is the one the client read: refused before a merge or a delete moves a
	 * trip, not after. erase() checks again, under the holds.
	 *
	 * @throws StaleUpdateException
	 */
	private function current(Partner $partner, int $expectedUpdatedAt): Partner {
		if ($partner->getUpdatedAt() !== $expectedUpdatedAt) {
			throw new StaleUpdateException('partner ' . $partner->getUuid() . ' is not the row that was read');
		}

		return $partner;
	}

	/**
	 * The other record a merge goes into, of the same list.
	 *
	 * @throws RefusedException TARGET_GONE if it is not on the list
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function target(string $userId, Partner $partner, mixed $into): Partner {
		if (!is_string($into) || $into === $partner->getUuid()) {
			throw new \InvalidArgumentException('into is another partner of the list');
		}
		try {
			$target = $this->partners->findByUuid($into);
		} catch (DoesNotExistException) {
			throw new RefusedException('into is not on the list', self::TARGET_GONE);
		}
		// One answer for a record of another list, so it tells nobody which uuids exist.
		if ($target->getUserId() !== $userId) {
			throw new RefusedException('into is not on the list', self::TARGET_GONE);
		}

		return $target;
	}

	/**
	 * Holds every vehicle the owner has, and any other whose trips link the record, in id order:
	 * the owner's lowest first, as PartnerLinks takes it for a new name, so a trip written meanwhile
	 * waits and then finds the record as this leaves it.
	 *
	 * The set is read again under the holds until it stands: a transfer in, a vehicle the owner
	 * adds, or a trip that comes to link the record may commit while the holds are taken, and
	 * relinkAll() must not move a trip on a vehicle nobody held. A late id is held out of order;
	 * a deadlock that makes is broken by the database, and atomicRetry() runs the write again.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function hold(string $userId, Partner $partner): void {
		$held = [];
		while (($new = array_diff($this->vehiclesOf($userId, $partner), $held)) !== []) {
			sort($new);
			foreach ($new as $id) {
				$this->vehicles->hold($id);
				$held[] = $id;
			}
		}
	}

	/**
	 * @return list<int> the ids hold() takes
	 * @throws \OCP\DB\Exception
	 */
	private function vehiclesOf(string $userId, Partner $partner): array {
		$ids = array_map(static fn (Vehicle $vehicle): int => (int)$vehicle->getId(), $this->vehicles->findAnyOwnedBy($userId));
		foreach ($this->trips->findAnyLinking((int)$partner->getId()) as $trip) {
			$ids[] = $trip->getVehicleId();
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Moves each trip's link to `$to`, through TripService so each leaves its audit row. Under
	 * hold()'s holds; the trips are read under them.
	 *
	 * @param list<Trip> $trips
	 * @throws \OCP\DB\Exception
	 */
	private function relinkAll(string $userId, array $trips, Partner $to): void {
		$vehicles = [];
		foreach ($trips as $trip) {
			$vehicles[$trip->getVehicleId()] ??= $this->vehicles->findAnyById($trip->getVehicleId());
			$this->tripService->relink($userId, $vehicles[$trip->getVehicleId()], $trip, $to);
		}
	}

	/**
	 * Takes the record off the list and with it the name, the address and the note.
	 *
	 * @throws StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function erase(Partner $partner, int $expectedUpdatedAt): Partner {
		$partner->setName(Partner::ERASED);
		$partner->setAddress(null);
		$partner->setNote(null);

		return $this->partners->softDelete($partner, $expectedUpdatedAt);
	}

	/**
	 * The record as an answer carries it; the address and the note to the owner alone.
	 *
	 * @return NextFleetPartner
	 */
	public static function wire(Partner $partner, bool $whole): array {
		return [
			'uuid' => $partner->getUuid(),
			'user_id' => $partner->getUserId(),
			'name' => $partner->getName(),
			'address' => $whole ? $partner->getAddress() : null,
			'note' => $whole ? $partner->getNote() : null,
			'created_at' => $partner->getCreatedAt(),
			'updated_at' => $partner->getUpdatedAt(),
		];
	}
}
