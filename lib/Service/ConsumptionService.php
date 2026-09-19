<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;

/**
 * Consumption, full tank to full tank (docs/architecture.md#numbers-consumption-cost-emissions).
 * Each energy is a chain of its own, so a plug-in hybrid gets two figures and never a blended one.
 *
 * @psalm-type Segment = array{energy: string, closes: string, filled_at: int, amount: int, distance: int, per: string, value: float}
 * @psalm-type Rolling = array{amount: int, distance: int, per: string, value: float}
 * @psalm-type Period = array{energy: string, amount: int, distance: int, per: string, value: float}
 */
class ConsumptionService {
	public function __construct(
		private EnergyMapper $energy,
		private OdoReadingMapper $readings,
	) {
	}

	/**
	 * Every segment one vehicle's fill-ups close, oldest first. `amount` is millilitres or
	 * watt-hours, `distance` the main counter's advance, and `value` litres or kWh per 100 km, or
	 * per hour when the main counter counts hours. A two-counter vehicle is measured against its
	 * kilometres: the hours are work beside the driving, not instead of it.
	 *
	 * @return list<Segment>
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		$fills = $this->energy->findAllForVehicle($vehicleId);
		$ids = array_map(static fn (Energy $fill): int => (int)$fill->getId(), $fills);

		$counted = [];
		foreach ($this->readings->findForSources($vehicleId, OdoReading::ENERGY, $ids) as $reading) {
			if ($reading->getCounter() === OdoReading::MAIN) {
				$counted[(int)$reading->getSourceId()] = $reading;
			}
		}

		$chains = [];
		foreach ($fills as $fill) {
			$chains[$fill->getEnergy()][] = $fill;
		}

		$per = self::per($vehicle);
		$segments = [];
		foreach ($chains as $chain) {
			array_push($segments, ...self::segments($chain, $counted, $per));
		}
		usort($segments, static fn (array $a, array $b): int => $a['filled_at'] <=> $b['filled_at']);

		return $segments;
	}

	/**
	 * Electricity as drawn from the wall: every charge in `[from, to)` over the distance driven in
	 * it, charged or not. No charge ever fills a battery "full", so the segment rule rarely fires
	 * for electricity; this figure always can, and is approximate because it includes charging
	 * losses and the kilometres a hybrid drove on its other energy.
	 *
	 * @return Rolling|null null without a charge or a distance in the period
	 * @throws \OCP\DB\Exception
	 */
	public function wallSide(Vehicle $vehicle, int $from, int $to): ?array {
		$amount = 0;
		foreach ($this->energy->findBetween((int)$vehicle->getId(), $from, $to) as $fill) {
			if ($fill->getEnergy() === 'electric') {
				$amount += $fill->getAmount();
			}
		}
		$distance = $this->distance($vehicle, $from, $to);
		if ($amount === 0 || $distance === null) {
			return null;
		}

		$per = self::per($vehicle);

		return ['amount' => $amount, 'distance' => $distance, 'per' => $per, 'value' => self::value($amount, $distance, $per)];
	}

	/**
	 * Each energy's consumption over the segments that close in `[from, to)`: their amounts over
	 * their distances, so a long segment weighs more than a short one - averaging the segments'
	 * values would not.
	 *
	 * @return list<Period>
	 * @throws \OCP\DB\Exception
	 */
	public function period(Vehicle $vehicle, int $from, int $to): array {
		$sums = [];
		foreach ($this->of($vehicle) as $segment) {
			if ($segment['filled_at'] < $from || $segment['filled_at'] >= $to) {
				continue;
			}
			$sum = $sums[$segment['energy']] ?? ['amount' => 0, 'distance' => 0];
			$sums[$segment['energy']] = [
				'amount' => $sum['amount'] + $segment['amount'],
				'distance' => $sum['distance'] + $segment['distance'],
			];
		}

		$per = self::per($vehicle);
		$periods = [];
		foreach ($sums as $energy => $sum) {
			$periods[] = [
				'energy' => (string)$energy,
				'amount' => $sum['amount'],
				'distance' => $sum['distance'],
				'per' => $per,
				'value' => self::value($sum['amount'], $sum['distance'], $per),
			];
		}

		return $periods;
	}

	/**
	 * How far a counter moved in `[from, to)`: its newest Reading there minus its oldest, leaving
	 * out any the chain questions. Null when fewer than two Readings say it moved.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function distance(Vehicle $vehicle, int $from, int $to, string $counter = OdoReading::MAIN): ?int {
		$values = [];
		foreach ($this->readings->findChain((int)$vehicle->getId(), $counter) as $reading) {
			if (!$reading->getFlagged() && $reading->getReadAt() >= $from && $reading->getReadAt() < $to) {
				$values[] = $reading->getValue();
			}
		}
		$distance = $values === [] ? 0 : end($values) - $values[0];

		return $distance > 0 ? $distance : null;
	}

	/**
	 * One energy's segments. A segment opens at a full fill-up and closes at the next one; whatever
	 * went in after the opening, partials included, is what the distance used. It yields no number
	 * when a fill-up in it says one before it went unrecorded, or when either end lacks a Reading
	 * that was read and not questioned - a gap must produce no number rather than a wrong one.
	 *
	 * @param list<Energy> $chain in time order
	 * @param array<int, OdoReading> $counted each fill-up's live main-counter Reading, by its id
	 * @return list<Segment>
	 */
	private static function segments(array $chain, array $counted, string $per): array {
		$segments = [];
		$open = null;
		$amount = 0;
		$missed = false;
		foreach ($chain as $fill) {
			$amount += $fill->getAmount();
			$missed = $missed || $fill->getMissedPrevious();
			if (!$fill->getFullTank()) {
				continue;
			}

			$close = self::trusted($counted[(int)$fill->getId()] ?? null);
			if ($open !== null && $close !== null && !$missed) {
				$distance = $close->getValue() - $open->getValue();
				if ($distance > 0) {
					$segments[] = [
						'energy' => $fill->getEnergy(),
						'closes' => $fill->getUuid(),
						'filled_at' => $fill->getFilledAt(),
						'amount' => $amount,
						'distance' => $distance,
						'per' => $per,
						'value' => self::value($amount, $distance, $per),
					];
				}
			}

			$open = $close;
			$amount = 0;
			$missed = false;
		}

		return $segments;
	}

	/** A two-counter vehicle is measured against its kilometres (of()). */
	public static function per(Vehicle $vehicle): string {
		return $vehicle->getOdoUnit() === 'h' ? 'h' : 'km';
	}

	/** Millilitres or watt-hours to litres or kWh, per 100 km or per hour. */
	private static function value(int $amount, int $distance, string $per): float {
		return $amount / ($per === 'h' ? 1000.0 : 10.0) / $distance;
	}

	/** The Reading when a segment may be measured from it (docs/architecture.md rule 6). */
	private static function trusted(?OdoReading $reading): ?OdoReading {
		return $reading !== null && $reading->getOrigin() === OdometerService::OBSERVED && !$reading->getFlagged()
			? $reading
			: null;
	}
}
