<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
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
	 * An Odometer Entry is its own Reading and carries nothing beyond the number (CONTEXT.md).
	 * The other source types name tables that arrive with M2.
	 */
	private const MANUAL = 'manual';

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

		$reading = new OdoReading();
		$reading->setVehicleId((int)$vehicle->getId());
		$reading->setCreatedBy($userId);
		$reading->setReadAt($readAt);
		$reading->setReadAtOff($this->offset('read_at_off', $fields['read_at_off'] ?? null));
		$reading->setValue($value);
		$reading->setKind(self::READING);
		$reading->setOrigin($origin);
		$reading->setFlagged(false);
		$reading->setSourceType(self::MANUAL);

		$written = $this->readings->insert($reading);

		return $this->restate($vehicle, $written->getUuid());
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
	 * Reads the vehicle's whole odometer back, decides every flag from scratch and caches the
	 * newest value on the vehicle. Nothing is ever incremented in place, so two drivers logging
	 * at once cannot corrupt a running total (rule 2).
	 *
	 * The whole chain, not a window around the new row: a bounded pass has to know how far a
	 * contradiction can reach backwards, and getting that wrong is silent. One indexed query
	 * per entry is what a vehicle's lifetime of readings costs.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function restate(Vehicle $vehicle, string $uuid): OdoReading {
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

		foreach ($readings as $reading) {
			if ($reading->getUuid() === $uuid) {
				return $reading;
			}
		}

		throw new \RuntimeException('the reading just written is not among the vehicle\'s');
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

		$base = $this->readings->findNewestAtOrBefore($vehicleId, $readAt);
		if ($base === null) {
			// Adding a distance to nothing would invent a counter that starts at the distance.
			throw new \InvalidArgumentException('distance has no earlier reading to count from');
		}

		return [$base->getValue() + $this->count('distance', $distance), self::DERIVED];
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
