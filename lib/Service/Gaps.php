<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;

/**
 * The Gaps in a vehicle's logbook (CONTEXT.md). A trip's `start_odo` is a claim about the counter,
 * and the Reading before the trip is what it is measured against (docs/architecture.md#odometer-rules,
 * rule 5). Computed on read for every vehicle, and said only under Logbook Mode
 * (docs/features.md#logbook-mode).
 */
class Gaps {
	public function __construct(
		private TripMapper $trips,
		private OdoReadingMapper $readings,
	) {
	}

	/**
	 * Every Gap, oldest first. Each names the trip whose claim opened it, the kilometres, and the two
	 * moments that bracket them: the Reading before, and the trip's start.
	 *
	 * @return list<array{trip: string, distance: int, from_at: int, from_at_off: int, to_at: int, to_at_off: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		// Both reads are ordered, so one walk over the chain serves every trip.
		$readings = $this->readings->findAllForVehicle($vehicleId);
		$next = 0;

		$gaps = [];
		foreach ($this->trips->findAllForVehicle($vehicleId) as $trip) {
			while ($next < count($readings) && $readings[$next]->getReadAt() <= $trip->getStartedAt()) {
				$next++;
			}

			$claim = $trip->getStartOdo();
			$before = $this->before($readings, $next, (int)$trip->getId());
			// A Reading in question could be a cluster swap or a typo (rule 3), and a gap counted
			// from a typo is kilometres nobody drove. The flag is the question to answer first.
			if ($claim === null || $before === null || $before->getFlagged() || $claim <= $before->getValue()) {
				continue;
			}

			$gaps[] = [
				'trip' => $trip->getUuid(),
				'distance' => $claim - $before->getValue(),
				'from_at' => $before->getReadAt(),
				'from_at_off' => $before->getReadAtOff(),
				'to_at' => $trip->getStartedAt(),
				'to_at_off' => $trip->getStartedAtOff(),
			];
		}

		return $gaps;
	}

	/**
	 * The newest of the first `$count` Readings that the trip did not write itself. Its own sits at
	 * its end, which is among them only when the journey ended the moment it began.
	 *
	 * @param list<OdoReading> $readings
	 */
	private function before(array $readings, int $count, int $tripId): ?OdoReading {
		for ($index = $count - 1; $index >= 0; $index--) {
			$reading = $readings[$index];
			if ($reading->getSourceType() !== OdoReading::TRIP || $reading->getSourceId() !== $tripId) {
				return $reading;
			}
		}

		return null;
	}
}
