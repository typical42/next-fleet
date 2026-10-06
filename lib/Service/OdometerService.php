<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\RefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * The odometer rules of docs/architecture.md#odometer-rules, in the one place that writes a
 * Reading.
 *
 * @psalm-type Drift = array{counter: OdoReading::MAIN|OdoReading::SECOND, table: string, row: string, column: string, before: int|bool|null, after: int|bool|null}
 */
class OdometerService {
	use TTransactional;

	public const OBSERVED = 'observed';
	public const DERIVED = 'derived';

	/** Why reset() refuses: the Reading asks no question it could answer. */
	public const NOT_IN_QUESTION = 'not_in_question';

	/**
	 * The chains a batch() has written to and not settled yet; null outside one.
	 *
	 * @var array<OdoReading::MAIN|OdoReading::SECOND, true>|null
	 */
	private ?array $unsettled = null;

	public function __construct(
		private OdoReadingMapper $readings,
		private VehicleMapper $vehicles,
		private VehicleService $fleet,
		private IDBConnection $db,
	) {
	}

	/**
	 * Writes one Reading and re-states the vehicle's odometer around it.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->readings,
			// An Odometer Entry's, not a Reading another Entry wrote.
			static fn (OdoReading $row): bool => $row->getVehicleId() === (int)$vehicle->getId() && $row->getSourceType() === OdoReading::MANUAL,
			static fn (OdoReading $row): OdoReading => $row,
		);

		// Retried for the reason TripService::record() gives. The replay builds a fresh row.
		return $once->run(fn (): OdoReading => $this->atomicRetry(function () use ($vehicle, $userId, $fields, $once): OdoReading {
			// Held before the distance is counted from the chain, as settle() asks.
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();

			return $this->add($vehicle, $userId, $fields, $once);
		}, $this->db));
	}

	/**
	 * What record() writes, for a caller that has reached the vehicle and holds it, as
	 * EnergyService::add() is.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<OdoReading>|null $once the client's uuid for the row, if it sent one
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function add(Vehicle $vehicle, string $userId, array $fields, ?Once $once = null): OdoReading {
		$readAt = Field::count('read_at', $fields['read_at'] ?? null, Field::MOMENT);
		$readAtOff = Field::offset('read_at_off', $fields['read_at_off'] ?? null);
		$counter = self::counterOf($vehicle, $fields['counter'] ?? null);
		[$value, $origin] = $this->valueOf((int)$vehicle->getId(), $counter, $readAt, $fields);

		return $this->write($vehicle, $userId, $readAt, $readAtOff, $value, $origin, $counter, OdoReading::MANUAL, null, $once);
	}

	/**
	 * Runs many add() and followEntry() writes on one vehicle and settles each chain they touched
	 * once, after the last (docs/architecture.md#import). The Readings handed back meanwhile are as
	 * inserted, their flags not yet decided; update() and restore() cannot run inside, since they
	 * pick their row out of a settled chain.
	 *
	 * Inside the transaction that holds the vehicle, so nobody reads a chain left unsettled. A
	 * write that throws settles nothing; the rollback takes it.
	 *
	 * @template T
	 * @param \Closure(): T $writes
	 * @return T
	 * @throws \OCP\DB\Exception
	 */
	public function batch(Vehicle $vehicle, \Closure $writes): mixed {
		$this->unsettled = [];
		try {
			$done = $writes();
			// Filled by the writes, which Psalm does not follow into the closure.
			/** @var array<OdoReading::MAIN|OdoReading::SECOND, true> $unsettled */
			$unsettled = $this->unsettled;
			$chains = array_keys($unsettled);
		} finally {
			$this->unsettled = null;
		}
		foreach ($chains as $counter) {
			$this->settle($vehicle, $counter);
		}

		return $done;
	}

	/**
	 * Corrects one Odometer Entry in place: the number somebody read, the moment and the counter.
	 * A number, not a distance - what is corrected is what the dashboard said, so the row becomes
	 * Observed. Every chain the Reading was on or is now on is settled again.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this Odometer Entry
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it is no Odometer Entry on this vehicle
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if it has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $readingUuid, int $expectedUpdatedAt, array $fields): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		$readAt = Field::count('read_at', $fields['read_at'] ?? null, Field::MOMENT);
		$readAtOff = Field::offset('read_at_off', $fields['read_at_off'] ?? null);
		$value = Field::count('value', $fields['value'] ?? null, Field::COUNTER);
		$counter = self::counterOf($vehicle, $fields['counter'] ?? null);

		// Looked up inside the transaction for the reason TripService::delete() gives.
		return $this->atomicRetry(function () use ($userId, $vehicle, $readingUuid, $expectedUpdatedAt, $readAt, $readAtOff, $value, $counter): OdoReading {
			$this->vehicles->hold((int)$vehicle->getId());
			$reading = self::entry($this->readings->findOnVehicle((int)$vehicle->getId(), $readingUuid));
			$this->fleet->change($userId, VehicleAccess::EDIT, $vehicle, $reading->getCreatedBy());
			$was = $reading->getCounter();

			self::renumber($reading, $value, $counter);
			$reading->setReadAt($readAt);
			$reading->setReadAtOff($readAtOff);
			$reading->setOrigin(self::OBSERVED);
			$reading->setCounter($counter);
			$this->readings->updateChecked($reading, $expectedUpdatedAt);

			if ($was !== $counter) {
				$this->settle($vehicle, $was);
			}

			return $this->restate($vehicle, $counter, $reading->getUuid());
		}, $this->db);
	}

	/**
	 * Soft-deletes one Odometer Entry, and answers with the token the undo is checked against. No
	 * audit row: Logbook Mode covers trips only (docs/features.md#logbook-mode).
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete this Odometer Entry
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it is no Odometer Entry on this vehicle
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if it has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $readingUuid, int $expectedUpdatedAt): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $readingUuid, $expectedUpdatedAt): OdoReading {
			$this->vehicles->hold((int)$vehicle->getId());
			$reading = self::entry($this->readings->findOnVehicle((int)$vehicle->getId(), $readingUuid));
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $reading->getCreatedBy());

			return $this->remove($vehicle, $reading, $expectedUpdatedAt);
		}, $this->db);
	}

	/**
	 * What delete() writes, for a caller that holds the vehicle and found the Odometer Entry on it,
	 * as add() is. Inside a batch() too: unlike restore(), it picks nothing out of the chain.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the Reading is no Odometer Entry
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if it has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function remove(Vehicle $vehicle, OdoReading $reading, int $expectedUpdatedAt): OdoReading {
		$deleted = $this->readings->softDelete(self::entry($reading), $expectedUpdatedAt);
		$this->settle($vehicle, $deleted->getCounter());

		return $deleted;
	}

	/**
	 * Undo, on the token the delete answered with (TripService::restore()).
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete this Odometer Entry
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it is no Odometer Entry on this vehicle
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if it has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $readingUuid, int $expectedUpdatedAt): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $readingUuid, $expectedUpdatedAt): OdoReading {
			$this->vehicles->hold((int)$vehicle->getId());
			$reading = self::entry($this->readings->findAnyOnVehicle((int)$vehicle->getId(), $readingUuid));
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $reading->getCreatedBy());
			$back = $this->readings->restoreChecked($reading, $expectedUpdatedAt);

			return $this->restate($vehicle, $back->getCounter(), $back->getUuid());
		}, $this->db);
	}

	/**
	 * The answer "the counter was replaced" to a Reading the chain questions (rule 3): it becomes a
	 * `reset`, stands, and starts a new segment. Any Entry's Reading, by whoever may change that
	 * Entry. Only one somebody read: a derived Reading in question is the app's arithmetic,
	 * contradicted, and no counter was swapped under it.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit the Entry
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if no live Reading on this vehicle has the uuid
	 * @throws RefusedException `not_in_question` when the Reading asks no such question
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if it has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function reset(string $userId, string $vehicleUuid, string $readingUuid, int $expectedUpdatedAt): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $readingUuid, $expectedUpdatedAt): OdoReading {
			$this->vehicles->hold((int)$vehicle->getId());
			$reading = $this->readings->findOnVehicle((int)$vehicle->getId(), $readingUuid);
			$this->fleet->change($userId, VehicleAccess::EDIT, $vehicle, $reading->getCreatedBy());
			if (!$reading->getFlagged() || $reading->getOrigin() !== self::OBSERVED) {
				throw new RefusedException('only a reading in question that somebody read can be a reset', self::NOT_IN_QUESTION);
			}

			$reading->setKind(OdoReading::RESET);
			$this->readings->updateChecked($reading, $expectedUpdatedAt);

			return $this->restate($vehicle, $reading->getCounter(), $reading->getUuid());
		}, $this->db);
	}

	/**
	 * The Reading, where it is an Odometer Entry. One a trip, a fill-up or a maintenance record
	 * wrote belongs to that Entry and moves with it (rule 5); editing it here would leave the two
	 * saying different things. Not found rather than refused, as a Reading on another vehicle is.
	 *
	 * @throws DoesNotExistException
	 */
	private static function entry(OdoReading $reading): OdoReading {
		if ($reading->getSourceType() !== OdoReading::MANUAL) {
			throw new DoesNotExistException('reading ' . $reading->getUuid() . ' is not an Odometer Entry');
		}

		return $reading;
	}

	/**
	 * Which chain an Odometer Entry reads (rule 4): `main` unless the request names the engine
	 * hours of a vehicle that counts them. A trip never asks - it is on `main` by definition.
	 *
	 * @return OdoReading::MAIN|OdoReading::SECOND
	 * @throws \InvalidArgumentException
	 */
	private static function counterOf(Vehicle $vehicle, mixed $counter): string {
		if ($counter === null || $counter === '') {
			return OdoReading::MAIN;
		}
		$counters = $vehicle->getSecondUnit() === null
			? [OdoReading::MAIN]
			: [OdoReading::MAIN, OdoReading::SECOND];

		return Field::word('counter', $counter, $counters) === OdoReading::SECOND
			? OdoReading::SECOND
			: OdoReading::MAIN;
	}

	/**
	 * The one Reading a Trip writes, at the moment it ended, on the main chain (rules 4 and 5).
	 * `start_odo` is a claim about the counter and never a Reading.
	 *
	 * The vehicle is passed in, not looked up: the caller reached it through
	 * `VehicleService::reach`, and a second gate here would be a second place to forget one.
	 *
	 * @throws \InvalidArgumentException if the trip ended on neither a counter nor a distance
	 * @throws \OCP\DB\Exception
	 */
	public function fromTrip(Vehicle $vehicle, Trip $trip): OdoReading {
		[$value, $origin] = $this->counted($vehicle, $trip, null);

		return $this->write(
			$vehicle,
			$trip->getCreatedBy(),
			$trip->getEndedAt(),
			$trip->getEndedAtOff(),
			$value,
			$origin,
			OdoReading::MAIN,
			OdoReading::TRIP,
			(int)$trip->getId(),
		);
	}

	/**
	 * The Readings of a fill-up or a maintenance record, brought in line with it as it now stands:
	 * one Observed Reading per counter it states, none without, and none while it is deleted
	 * (rule 5). Called after every write to the Entry - its creation, an edit, a delete and its
	 * undo. A Reading keeps its row through all of it; only a counter the Entry never stated gets
	 * a new one.
	 *
	 * The caller has reached and held the vehicle, as for fromTrip().
	 *
	 * @param OdoReading::ENERGY|OdoReading::MAINTENANCE $sourceType
	 * @param bool $fresh the Entry was inserted just now, so it has no Reading to look for
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if a Reading is not as it was read
	 * @throws \OCP\DB\Exception
	 */
	public function followEntry(
		Vehicle $vehicle,
		string $userId,
		string $sourceType,
		int $sourceId,
		int $readAt,
		int $readAtOff,
		?int $odo,
		?int $secondOdo,
		bool $deleted,
		bool $fresh = false,
	): void {
		$written = $fresh ? [] : $this->readings->findAnyForSource((int)$vehicle->getId(), $sourceType, $sourceId);

		foreach ([OdoReading::MAIN => $odo, OdoReading::SECOND => $secondOdo] as $counter => $value) {
			$own = array_values(array_filter(
				$written,
				static fn (OdoReading $reading): bool => $reading->getCounter() === $counter,
			));
			$newest = $own === [] ? null : end($own);
			$wanted = $deleted ? null : $value;

			if ($newest === null) {
				if ($wanted !== null) {
					$this->write($vehicle, $userId, $readAt, $readAtOff, $wanted, self::OBSERVED, $counter, $sourceType, $sourceId);
				}
				continue;
			}

			$live = $newest->getDeletedAt() === null;
			if ($wanted === null) {
				if ($live) {
					$this->readings->softDelete($newest, $newest->getUpdatedAt());
					$this->settle($vehicle, $counter);
				}
				continue;
			}

			if (!$live) {
				$this->readings->restoreChecked($newest, $newest->getUpdatedAt());
			}
			if ([$newest->getValue(), $newest->getReadAt(), $newest->getReadAtOff()] !== [$wanted, $readAt, $readAtOff]) {
				self::renumber($newest, $wanted);
				$newest->setReadAt($readAt);
				$newest->setReadAtOff($readAtOff);
				$this->readings->updateChecked($newest, $newest->getUpdatedAt());
			} elseif ($live) {
				continue;
			}
			$this->settle($vehicle, $counter);
		}
	}

	/**
	 * The counters a fill-up or a maintenance record was posted with, `odo` and `second_odo`, each
	 * null when not given - what followEntry() takes.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{?int, ?int}
	 * @throws \InvalidArgumentException if a counter is not one, or names a chain the vehicle lacks
	 */
	public static function entryCounters(Vehicle $vehicle, array $fields): array {
		$odo = Field::read('odo', 'count', Field::COUNTER, $fields['odo'] ?? null);
		$secondOdo = Field::read('second_odo', 'count', Field::COUNTER, $fields['second_odo'] ?? null);
		// Refused as an Odometer Entry naming the hour counter is (counterOf()): the number would
		// be a Reading on a chain the vehicle does not have.
		if ($secondOdo !== null && $vehicle->getSecondUnit() === null) {
			throw new \InvalidArgumentException('second_odo is for a vehicle that counts engine hours');
		}

		return [is_int($odo) ? $odo : null, is_int($secondOdo) ? $secondOdo : null];
	}

	/**
	 * What a trip's Reading holds, and where the number came from.
	 *
	 * @param ?int $own the trip's own Reading, which a distance never counts from
	 * @return array{int, string}
	 * @throws \InvalidArgumentException if the trip ended on neither a counter nor a distance
	 * @throws \OCP\DB\Exception
	 */
	private function counted(Vehicle $vehicle, Trip $trip, ?int $own): array {
		$endOdo = $trip->getEndOdo();
		$distance = $trip->getDistance();
		if ($endOdo !== null) {
			return [$endOdo, self::OBSERVED];
		}
		if ($distance !== null) {
			// Counted from where the vehicle stood when the journey began, and not from
			// `start_odo`: that one is the driver's claim, and rule 6 counts from a Reading.
			return [$this->derive((int)$vehicle->getId(), OdoReading::MAIN, $trip->getStartedAt(), $distance, $own), self::DERIVED];
		}

		throw new \InvalidArgumentException('a trip writes its Reading off a counter or a distance');
	}

	/**
	 * An edited trip's Reading, restated only as far as the edit reached (rules 5 and 6). Its date
	 * follows the journey's end; its number is counted again only when what it was counted from
	 * changed, since a recount would move it off an Entry typed in since.
	 *
	 * The Reading keeps its row; the checked write is against the Reading as read here, for the
	 * reason followTrip() gives.
	 *
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Reading is not as it was read
	 * @throws \OCP\DB\Exception
	 */
	public function followEdit(Vehicle $vehicle, Trip $was, Trip $trip): void {
		$recount = self::basis($was) !== self::basis($trip);
		if (!$recount && [$was->getEndedAt(), $was->getEndedAtOff()] === [$trip->getEndedAt(), $trip->getEndedAtOff()]) {
			return;
		}

		$reading = $this->readings->findAnyForTrip((int)$vehicle->getId(), (int)$trip->getId());
		if ($reading === null) {
			throw new \RuntimeException('the trip has no Reading of its own to follow it');
		}

		$reading->setReadAt($trip->getEndedAt());
		$reading->setReadAtOff($trip->getEndedAtOff());
		if ($recount) {
			[$value, $origin] = $this->counted($vehicle, $trip, (int)$reading->getId());
			self::renumber($reading, $value);
			$reading->setOrigin($origin);
		}
		$this->readings->updateChecked($reading, $reading->getUpdatedAt());

		$this->settle($vehicle, OdoReading::MAIN);
	}

	/**
	 * A new number on a Reading, or the same one on the other counter. An answered reset was the
	 * answer for the number it had on its counter, so either change asks the question afresh
	 * (rule 3).
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND|null $counter null where the counter cannot change
	 */
	private static function renumber(OdoReading $reading, int $value, ?string $counter = null): void {
		if ($reading->getValue() !== $value || ($counter !== null && $reading->getCounter() !== $counter)) {
			$reading->setKind(OdoReading::READING);
		}
		$reading->setValue($value);
	}

	/**
	 * What a trip's number is counted from (rules 5 and 6): the counter it ended on, or the
	 * distance and the moment it began.
	 *
	 * @return list<int|null>
	 */
	private static function basis(Trip $trip): array {
		return [$trip->getEndOdo(), $trip->getDistance(), $trip->getStartedAt()];
	}

	/**
	 * The insert every Reading goes through, and the restating that follows it. Where the numbers
	 * came from is the caller's to say.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @param Once<OdoReading>|null $once an Odometer Entry's client uuid
	 * @throws \OCP\DB\Exception
	 */
	private function write(
		Vehicle $vehicle,
		string $userId,
		int $readAt,
		int $readAtOff,
		int $value,
		string $origin,
		string $counter,
		string $sourceType,
		?int $sourceId,
		?Once $once = null,
	): OdoReading {
		$reading = new OdoReading();
		$once?->stamp($reading);
		$reading->setVehicleId((int)$vehicle->getId());
		$reading->setCreatedBy($userId);
		$reading->setReadAt($readAt);
		$reading->setReadAtOff($readAtOff);
		$reading->setValue($value);
		// A client never picks the word: `reset` is the answer reset() writes.
		$reading->setKind(OdoReading::READING);
		$reading->setOrigin($origin);
		$reading->setFlagged(false);
		$reading->setSourceType($sourceType);
		$reading->setSourceId($sourceId);
		$reading->setCounter($counter);

		$written = $this->readings->insert($reading);
		if ($this->unsettled !== null) {
			$this->unsettled[$counter] = true;

			return $written;
		}

		return $this->restate($vehicle, $counter, $written->getUuid());
	}

	/**
	 * The Reading a trip left on the counter follows that trip into the trash and back out of it
	 * (rule 5), deleted exactly when its trip is.
	 *
	 * The checked write is against the Reading as just read, because nothing else writes a
	 * Reading's `deleted_at`: the token a client holds is the trip's, and the caller has already
	 * spent it on the trip itself.
	 *
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Reading is not as it was read
	 * @throws \OCP\DB\Exception
	 */
	public function followTrip(Vehicle $vehicle, Trip $trip): void {
		$reading = $this->readings->findAnyForTrip((int)$vehicle->getId(), (int)$trip->getId());
		if ($reading === null) {
			throw new \RuntimeException('the trip has no Reading of its own to follow it');
		}

		if ($trip->getDeletedAt() === null) {
			$this->readings->restoreChecked($reading, $reading->getUpdatedAt());
		} else {
			$this->readings->softDelete($reading, $reading->getUpdatedAt());
		}

		$this->settle($vehicle, OdoReading::MAIN);
	}

	/**
	 * One vehicle's odometer, oldest first - the order the timeline reads in (docs/ui.md) - each
	 * Reading naming the Entry that wrote it.
	 *
	 * @return list<OdoReading>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$readings = $this->readings->findAllForVehicle((int)$vehicle->getId());
		$this->readings->nameSources($readings);

		return $readings;
	}

	/**
	 * The chain as it now stands, and the row the caller just wrote picked back out of it.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @throws \OCP\DB\Exception
	 */
	private function restate(Vehicle $vehicle, string $counter, string $uuid): OdoReading {
		foreach ($this->settle($vehicle, $counter) as $reading) {
			if ($reading->getUuid() === $uuid) {
				return $reading;
			}
		}

		throw new \RuntimeException('the reading just written is not among the vehicle\'s');
	}

	/**
	 * Reads one counter's whole chain back, decides every flag from scratch and caches the newest
	 * value on the vehicle: `odo_value` for the main chain, `second_value` for the hours. Only the
	 * chain the write touched - the other holds no Reading that could flag against it (rule 4).
	 * The caller holds VehicleMapper::hold() from before it read or wrote anything (rule 2).
	 *
	 * The whole chain, not a window around the row that changed: a bounded pass has to know how
	 * far a contradiction can reach backwards, and getting that wrong is silent. So a Reading
	 * leaving the chain settles it as one arriving does - a flag it put on its neighbours goes
	 * with it.
	 *
	 * Inside a batch() it only notes the chain, which the batch settles at its end.
	 *
	 * The flags and the cache are settled, never a value: a derived row whose base was voided
	 * keeps the number it was counted with, since rule 6 corrects no derived value and a
	 * recomputed one would be a counter nobody ever read.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<OdoReading> the chain the caller's write left behind
	 * @throws \OCP\DB\Exception
	 */
	private function settle(Vehicle $vehicle, string $counter): array {
		if ($this->unsettled !== null) {
			$this->unsettled[$counter] = true;

			return [];
		}
		['readings' => $readings, 'flags' => $flags, 'newest' => $newest] = $this->settled((int)$vehicle->getId(), $counter);

		foreach ($flags as $index => $flagged) {
			if ($readings[$index]->getFlagged() !== $flagged) {
				$this->readings->flag($readings[$index], $flagged);
			}
		}

		if ($counter === OdoReading::MAIN) {
			$this->vehicles->cacheOdoValue((int)$vehicle->getId(), $newest);
		} else {
			$this->vehicles->cacheSecondValue((int)$vehicle->getId(), $newest);
		}

		return $readings;
	}

	/**
	 * Settles both chains of one vehicle, live or deleted, as a write's settle() would, and answers
	 * with what that changed. For `occ nextfleet:recompute`: every write already settles, so drift
	 * means a row was written behind the services' back. No reach: the caller is the admin.
	 *
	 * @param bool $dryRun answer what would change, and write nothing
	 * @return list<Drift>
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle's row is gone
	 * @throws \OCP\DB\Exception
	 */
	public function recompute(Vehicle $vehicle, bool $dryRun): array {
		if ($dryRun) {
			return $this->drift($vehicle);
		}

		return $this->atomicRetry(function () use ($vehicle): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$drift = $this->drift($vehicle);
			$counters = [];
			foreach ($drift as $change) {
				$counters[$change['counter']] = true;
			}
			foreach (array_keys($counters) as $counter) {
				$this->settle($vehicle, $counter);
			}

			return $drift;
		}, $this->db);
	}

	/**
	 * Each flag and cache that settling the vehicle's chains would change, written nowhere: the
	 * stored value against what settle() would leave. `occ nextfleet:check` reports it.
	 *
	 * @return list<Drift>
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle's row is gone
	 * @throws \OCP\DB\Exception
	 */
	public function drift(Vehicle $vehicle): array {
		// The caches as they stand now, not as the caller read them: a check over every vehicle
		// reads the list long before it reaches the last one, and a write in between moves them.
		$vehicle = $this->vehicles->findAnyById((int)$vehicle->getId());
		$drift = [];
		foreach ([OdoReading::MAIN => 'odo_value', OdoReading::SECOND => 'second_value'] as $counter => $column) {
			['readings' => $readings, 'flags' => $flags, 'newest' => $newest] = $this->settled((int)$vehicle->getId(), $counter);
			foreach ($flags as $index => $flagged) {
				if ($readings[$index]->getFlagged() !== $flagged) {
					$drift[] = ['counter' => $counter, 'table' => 'fleet_odo_readings', 'row' => $readings[$index]->getUuid(), 'column' => 'flagged', 'before' => !$flagged, 'after' => $flagged];
				}
			}
			$cached = $counter === OdoReading::MAIN ? $vehicle->getOdoValue() : $vehicle->getSecondValue();
			if ($cached !== $newest) {
				$drift[] = ['counter' => $counter, 'table' => 'fleet_vehicles', 'row' => $vehicle->getUuid(), 'column' => $column, 'before' => $cached, 'after' => $newest];
			}
		}

		return $drift;
	}

	/**
	 * What settle() leaves on one chain, written nowhere: the chain, the flag each of its Readings
	 * should carry, and the value the vehicle should cache.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return array{readings: list<OdoReading>, flags: list<bool>, newest: ?int}
	 * @throws \OCP\DB\Exception
	 */
	private function settled(int $vehicleId, string $counter): array {
		$readings = $this->readings->findChain($vehicleId, $counter);

		return [
			'readings' => $readings,
			'flags' => $this->flags($readings),
			'newest' => $readings === [] ? null : end($readings)->getValue(),
		];
	}

	/**
	 * What the Reading holds, and where the number came from: the counter as somebody read it,
	 * or the newest reading before this moment plus the distance driven since (rule 6). Which of
	 * the two it is stays the service's to say - `origin` is not a field a client fills in.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @param array<string, mixed> $fields
	 * @return array{int, string}
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function valueOf(int $vehicleId, string $counter, int $readAt, array $fields): array {
		$distance = $fields['distance'] ?? null;
		$given = $fields['value'] ?? null;
		if ($distance === null || $distance === '') {
			return [Field::count('value', $given, Field::COUNTER), self::OBSERVED];
		}
		if ($given !== null && $given !== '') {
			// The sheet toggles between the two (docs/ui.md). Preferring either would throw away
			// a number the other one contradicts, which is the opposite of what rule 6 asks.
			throw new \InvalidArgumentException('a reading is a value or a distance, not both');
		}

		return [$this->derive($vehicleId, $counter, $readAt, Field::count('distance', $distance, Field::COUNTER)), self::DERIVED];
	}

	/**
	 * Rule 6's arithmetic, in the one place that does it: the newest reading on the same counter at
	 * or before the moment the distance started running, plus the distance. An Odometer Entry
	 * counts from the moment it was read at, a Trip from the moment it set off.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @param ?int $own the Reading being restated, if any: an edited trip moved past its old end
	 *                  would otherwise find its own Reading before its new start
	 * @throws \InvalidArgumentException if the chain has no reading that early
	 * @throws \OCP\DB\Exception
	 */
	private function derive(int $vehicleId, string $counter, int $from, int $distance, ?int $own = null): int {
		$base = $this->readings->findNewestAtOrBefore($vehicleId, $counter, $from, $own);
		if ($base === null) {
			// Adding a distance to nothing would invent a counter that starts at the distance.
			throw new \InvalidArgumentException('distance has no earlier reading to count from');
		}

		return $base->getValue() + $distance;
	}

	/**
	 * Which of these readings contradict the one before them (rule 3). Decided over the whole
	 * chain and not against the newest row, because a Reading entered late lands between two
	 * that were already there.
	 *
	 * @param list<OdoReading> $readings
	 * @return list<bool>
	 */
	private function flags(array $readings): array {
		$flags = array_fill(0, count($readings), false);

		for ($i = 1; $i < count($readings); $i++) {
			// An answered reset is a new counter: nothing before it can contradict it.
			if ($readings[$i]->getKind() === OdoReading::RESET
				|| $readings[$i]->getValue() >= $readings[$i - 1]->getValue()) {
				continue;
			}
			if ($readings[$i]->getOrigin() !== self::OBSERVED) {
				$flags[$i] = true;
				continue;
			}

			// A number somebody read beats the ones the app computed (rule 6), so the flag goes
			// on every derived row it contradicts, back to the last row it does not.
			for ($j = $i - 1;
				$j >= 0
				&& $readings[$j]->getOrigin() === self::DERIVED
				&& $readings[$j]->getValue() > $readings[$i]->getValue();
				$j--) {
				$flags[$j] = true;
			}

			// Discrediting those says nothing about the reading itself: it is in question when
			// it is still below the last row that stands, which is where the walk stopped.
			$flags[$i] = $j >= 0 && $readings[$j]->getValue() > $readings[$i]->getValue();
		}

		return $flags;
	}
}
