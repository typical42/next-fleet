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

/**
 * The odometer rules of docs/architecture.md#odometer-rules, in the one place that writes a
 * Reading.
 */
class OdometerService {
	public const OBSERVED = 'observed';
	public const DERIVED = 'derived';

	/**
	 * What M1 writes. `reset` and `correction` are the answers to the follow-up question a
	 * flagged row asks, and only an answered `reset` starts a new segment (rule 3) - so they
	 * arrive with the timeline that asks it, not with a client that may pick a word.
	 */
	private const READING = 'reading';

	public function __construct(
		private OdoReadingMapper $readings,
		private VehicleMapper $vehicles,
		private VehicleService $fleet,
	) {
	}

	/**
	 * Writes one Reading and re-states the vehicle's odometer around it.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): OdoReading {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$readAt = $this->count('read_at', $fields['read_at'] ?? null);
		[$value, $origin] = $this->valueOf((int)$vehicle->getId(), $readAt, $fields);

		return $this->write(
			$vehicle,
			$userId,
			$readAt,
			$this->offset('read_at_off', $fields['read_at_off'] ?? null),
			$value,
			$origin,
			OdoReading::MANUAL,
			null,
		);
	}

	/**
	 * The one Reading a Trip writes, at the moment it ended (rule 5). `start_odo` is a claim about
	 * the counter and never a Reading - comparing the two is what gap detection is made of.
	 *
	 * The vehicle is passed in rather than looked up: the caller has already reached it through
	 * `VehicleService::reach`, and a second gate here would be a second place to forget one.
	 *
	 * @throws \InvalidArgumentException if the trip ended on neither a counter nor a distance
	 * @throws \OCP\DB\Exception
	 */
	public function fromTrip(Vehicle $vehicle, Trip $trip): OdoReading {
		$endOdo = $trip->getEndOdo();
		$distance = $trip->getDistance();
		if ($endOdo !== null) {
			[$value, $origin] = [$endOdo, self::OBSERVED];
		} elseif ($distance !== null) {
			// Counted from where the vehicle stood when the journey began, and not from
			// `start_odo`: that one is the driver's claim, and rule 6 counts from a Reading.
			[$value, $origin] = [
				$this->derive((int)$vehicle->getId(), $trip->getStartedAt(), $distance),
				self::DERIVED,
			];
		} else {
			throw new \InvalidArgumentException('a trip writes its Reading off a counter or a distance');
		}

		return $this->write(
			$vehicle,
			$trip->getCreatedBy(),
			$trip->getEndedAt(),
			$trip->getEndedAtOff(),
			$value,
			$origin,
			OdoReading::TRIP,
			(int)$trip->getId(),
		);
	}

	/**
	 * The insert every Reading goes through, and the restating that follows it. What differs
	 * between an Odometer Entry and an Entry with content of its own is only where the numbers
	 * came from, which is the caller's to say.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function write(
		Vehicle $vehicle,
		string $userId,
		int $readAt,
		int $readAtOff,
		int $value,
		string $origin,
		string $sourceType,
		?int $sourceId,
	): OdoReading {
		$reading = new OdoReading();
		$reading->setVehicleId((int)$vehicle->getId());
		$reading->setCreatedBy($userId);
		$reading->setReadAt($readAt);
		$reading->setReadAtOff($readAtOff);
		$reading->setValue($value);
		$reading->setKind(self::READING);
		$reading->setOrigin($origin);
		$reading->setFlagged(false);
		$reading->setSourceType($sourceType);
		$reading->setSourceId($sourceId);

		$written = $this->readings->insert($reading);

		return $this->restate($vehicle, $written->getUuid());
	}

	/**
	 * The Reading a trip left on the counter follows that trip into the trash and back out of it.
	 * The journey and the number it left behind are one fact (rule 5), so a Reading standing on a
	 * voided trip would hold the vehicle's kilometres at a journey nobody claims any more - and on
	 * a row the timeline no longer shows, which leaves nothing on screen to explain the figure.
	 *
	 * Which way it goes is the trip's to say: the Reading is stamped exactly as its trip is. The
	 * checked write is against the Reading as this method just read it, because nothing else
	 * writes a Reading's `deleted_at` - the concurrency token a client holds is the trip's, and
	 * the caller has already spent it on the trip itself.
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

		$this->settle($vehicle);
	}

	/**
	 * One vehicle's odometer, oldest first - the order the timeline reads in (docs/ui.md).
	 *
	 * @return list<OdoReading>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);

		return $this->readings->findAllForVehicle((int)$vehicle->getId());
	}

	/**
	 * The chain as it now stands, and the row the caller just wrote picked back out of it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function restate(Vehicle $vehicle, string $uuid): OdoReading {
		foreach ($this->settle($vehicle) as $reading) {
			if ($reading->getUuid() === $uuid) {
				return $reading;
			}
		}

		throw new \RuntimeException('the reading just written is not among the vehicle\'s');
	}

	/**
	 * Reads the vehicle's whole odometer back, decides every flag from scratch and caches the
	 * newest value on the vehicle. Nothing is ever incremented in place, so two drivers logging
	 * at once cannot corrupt a running total (rule 2).
	 *
	 * The whole chain, not a window around the row that changed: a bounded pass has to know how
	 * far a contradiction can reach backwards, and getting that wrong is silent. That is also why
	 * a Reading leaving the chain settles it the same way one arriving does - a flag a row put on
	 * its neighbours goes with it. One indexed query per entry is what a vehicle's lifetime of
	 * readings costs.
	 *
	 * What is settled is the flags and the cache, never a value: a derived row whose base has just
	 * been voided keeps the number it was counted with, because rule 6 corrects no derived value
	 * and a recomputed one would be a counter nobody ever read. The kilometres that journey
	 * covered are a fact of their own.
	 *
	 * @return list<OdoReading> the chain the caller's write left behind
	 * @throws \OCP\DB\Exception
	 */
	private function settle(Vehicle $vehicle): array {
		$readings = $this->readings->findAllForVehicle((int)$vehicle->getId());

		foreach ($this->flags($readings) as $index => $flagged) {
			if ($readings[$index]->getFlagged() !== $flagged) {
				$this->readings->flag($readings[$index], $flagged);
			}
		}

		$this->vehicles->cacheOdoValue(
			(int)$vehicle->getId(),
			$readings === [] ? null : end($readings)->getValue(),
		);

		return $readings;
	}

	/**
	 * What the Reading holds, and where the number came from: the counter as somebody read it,
	 * or the newest reading before this moment plus the distance driven since (rule 6). Which of
	 * the two it is stays the service's to say - `origin` is not a field a client fills in.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{int, string}
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function valueOf(int $vehicleId, int $readAt, array $fields): array {
		$distance = $fields['distance'] ?? null;
		$given = $fields['value'] ?? null;
		if ($distance === null || $distance === '') {
			return [$this->count('value', $given), self::OBSERVED];
		}
		if ($given !== null && $given !== '') {
			// The sheet toggles between the two (docs/ui.md). Preferring either would throw away
			// a number the other one contradicts, which is the opposite of what rule 6 asks.
			throw new \InvalidArgumentException('a reading is a value or a distance, not both');
		}

		return [$this->derive($vehicleId, $readAt, $this->count('distance', $distance)), self::DERIVED];
	}

	/**
	 * Rule 6's arithmetic, in the one place that does it: the newest reading at or before the
	 * moment the distance started running, plus the distance. An Odometer Entry counts from the
	 * moment it was read at, a Trip from the moment it set off - the caller says which.
	 *
	 * @throws \InvalidArgumentException if the vehicle has no reading that early
	 * @throws \OCP\DB\Exception
	 */
	private function derive(int $vehicleId, int $from, int $distance): int {
		$base = $this->readings->findNewestAtOrBefore($vehicleId, $from);
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
			if ($readings[$i]->getValue() >= $readings[$i - 1]->getValue()) {
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

	/**
	 * The offset the moment was read at, in minutes (docs/architecture.md#time). Real ones run
	 * from -12:00 to +14:00, and a number outside that is a field that did not mean minutes.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function offset(string $field, mixed $value): int {
		$minutes = filter_var($value, FILTER_VALIDATE_INT);
		if ($minutes === false || $minutes < -720 || $minutes > 840) {
			throw new \InvalidArgumentException($field . ' is a UTC offset in minutes');
		}

		return $minutes;
	}

	/** @throws \InvalidArgumentException */
	private function count(string $field, mixed $value): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false || $number < 0) {
			throw new \InvalidArgumentException($field . ' is a whole number, never negative');
		}

		return $number;
	}
}
