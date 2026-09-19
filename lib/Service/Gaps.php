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
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;

/**
 * The Gaps in a vehicle's logbook (CONTEXT.md). A trip's `start_odo` is a claim about the counter,
 * and the last trip's Reading before it is what it is measured against
 * (docs/architecture.md#odometer-rules, rule 5). Computed on read for every vehicle, and said only
 * under Logbook Mode (docs/features.md#logbook-mode).
 */
class Gaps {
	public function __construct(
		private TripMapper $trips,
		private OdoReadingMapper $readings,
	) {
	}

	/**
	 * Every Gap, oldest first. Each names the trip whose claim opened it, the kilometres, and the two
	 * moments that bracket them: the Reading it was measured against, and the trip's start.
	 *
	 * @return list<array{trip: string, distance: int, from_at: int, from_at_off: int, to_at: int, to_at_off: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		// Both reads are ordered, so one walk over the chain serves every trip. Kilometres only:
		// engine hours never open or bracket a Gap (rule 4).
		$readings = $this->readings->findChain($vehicleId, OdoReading::MAIN);
		$next = 0;

		$gaps = [];
		foreach ($this->trips->findAllForVehicle($vehicleId) as $trip) {
			while ($next < count($readings) && $readings[$next]->getReadAt() <= $trip->getStartedAt()) {
				$next++;
			}

			$claim = $trip->getStartOdo();
			$base = $this->base($readings, $next, (int)$trip->getId());
			if ($claim === null || $base === null || $this->questioned($readings, $base, $next, $trip, $claim)) {
				continue;
			}

			$before = $readings[$base];
			if ($claim <= $before->getValue()) {
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
	 * What the claim is measured against, among the first `$count` Readings: the newest a trip wrote,
	 * or with none the newest of all. Never the trip's own - it sits at its end, which is among them
	 * only when the journey ended the moment it began.
	 *
	 * @param list<OdoReading> $readings
	 * @return ?int its index
	 */
	private function base(array $readings, int $count, int $tripId): ?int {
		$newest = null;
		for ($index = $count - 1; $index >= 0; $index--) {
			if (!self::wroteIt($readings[$index], null)) {
				$newest ??= $index;
			} elseif (!self::wroteIt($readings[$index], $tripId)) {
				return $index;
			}
		}

		return $newest;
	}

	/**
	 * Whether a Reading from the base up to the trip's start asks something the Gap cannot be counted
	 * past. One in question could be a cluster swap or a typo (rule 3), and one above the claim says
	 * the counter was already past where the driver says it stood. A Gap counted over either could be
	 * kilometres nobody drove, so the question is answered first.
	 *
	 * So is one at the base's own moment that reads another number - the trip's own included. A
	 * closing trip counts from the newest Reading at its start (rule 6), which is that one, and would
	 * miss the claim.
	 *
	 * @param list<OdoReading> $readings
	 */
	private function questioned(array $readings, int $base, int $count, Trip $trip, int $claim): bool {
		$from = $readings[$base];
		for ($index = $base; $index < $count; $index++) {
			$reading = $readings[$index];
			if ($reading->getReadAt() === $from->getReadAt() && $reading->getValue() !== $from->getValue()) {
				return true;
			}
			// The trip's own Reading stands above its claim whenever it drove anywhere.
			if (!self::wroteIt($reading, (int)$trip->getId()) && ($reading->getFlagged() || $reading->getValue() > $claim)) {
				return true;
			}
		}

		return false;
	}

	/** Whether a trip wrote the Reading - that trip, or with null any trip. */
	private static function wroteIt(OdoReading $reading, ?int $tripId): bool {
		return $reading->getSourceType() === OdoReading::TRIP
			&& ($tripId === null || $reading->getSourceId() === $tripId);
	}
}
