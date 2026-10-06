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
 * @psalm-import-type NextFleetSegment from \OCA\NextFleet\ResponseDefinitions as Segment
 * @psalm-import-type NextFleetRolling from \OCA\NextFleet\ResponseDefinitions as Rolling
 * @psalm-import-type NextFleetPeriod from \OCA\NextFleet\ResponseDefinitions as Period
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

		$counted = [];
		foreach ($this->readings->findOfSourceType($vehicleId, OdoReading::ENERGY, OdoReading::MAIN) as $reading) {
			$counted[(int)$reading->getSourceId()] = $reading;
		}

		$chains = [];
		foreach ($fills as $fill) {
			$chains[$fill->getEnergy()][] = $fill;
		}

		$resets = $this->readings->findResets($vehicleId, OdoReading::MAIN, PHP_INT_MIN, PHP_INT_MAX);
		$per = self::per($vehicle);
		$segments = [];
		foreach ($chains as $chain) {
			array_push($segments, ...self::segments($chain, $counted, $resets, $per));
		}
		usort($segments, static fn (array $a, array $b): int => $a['filled_at'] <=> $b['filled_at']);

		return $segments;
	}

	/**
	 * Electricity as drawn from the wall: every charge in `[from, to)` over the distance driven in
	 * it, charged or not. Approximate: it includes charging losses and the kilometres a hybrid drove
	 * on its other energy.
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
		if ($amount === 0) {
			return null;
		}
		$distance = $this->distance($vehicle, $from, $to);
		if ($distance === null) {
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
	 * How far a counter moved between `from` and `to`, segments summed
	 * (docs/architecture.md#numbers-consumption-cost-emissions, "A period's distance"). Null when
	 * no two Readings say it moved, or when a segment ran backwards.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @throws \OCP\DB\Exception
	 */
	public function distance(Vehicle $vehicle, int $from, int $to, string $counter = OdoReading::MAIN): ?int {
		return $this->distances($vehicle, [[$from, $to]], $counter)[0];
	}

	/**
	 * distance() for many periods at once, as the Costs screen's twelve months and their year ask:
	 * an edge two periods share is looked up once, and the resets once for all of them, so the
	 * cost is per edge rather than per period.
	 *
	 * @param list<array{int, int}> $periods each `[from, to]`
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<?int> in the periods' order
	 * @throws \OCP\DB\Exception
	 */
	public function distances(Vehicle $vehicle, array $periods, string $counter = OdoReading::MAIN): array {
		$vehicleId = (int)$vehicle->getId();
		/** @var array<int, ?OdoReading> $newest */
		$newest = [];
		$standing = function (int $at) use (&$newest, $vehicleId, $counter): ?OdoReading {
			if (!array_key_exists($at, $newest)) {
				$newest[$at] = $this->readings->findNewestStanding($vehicleId, $counter, $at);
			}
			return $newest[$at];
		};
		// Nothing stands at or before an edge only while nothing stands at all before it, so the
		// oldest Reading after any such edge is the vehicle's oldest.
		$oldest = false;

		$spans = [];
		foreach ($periods as $i => [$from, $to]) {
			$start = $standing($from);
			if ($start === null) {
				if ($oldest === false) {
					$oldest = $this->readings->findOldestStanding($vehicleId, $counter, PHP_INT_MIN);
				}
				$start = $oldest;
			}
			$end = $start === null ? null : $standing($to);
			if ($start !== null && $end !== null) {
				$spans[$i] = [$start, $end];
			}
		}
		if ($spans === []) {
			return array_fill(0, count($periods), null);
		}

		$resets = $this->readings->findResets(
			$vehicleId,
			$counter,
			min(array_map(static fn (array $span): int => $span[0]->getReadAt(), $spans)),
			max(array_map(static fn (array $span): int => $span[1]->getReadAt(), $spans)),
		);
		/** @var array<int, ?OdoReading> $before where the counter stood just before each reset */
		$before = [];
		$distances = [];
		foreach (array_keys($periods) as $i) {
			if (!isset($spans[$i])) {
				$distances[] = null;
				continue;
			}
			[$start, $end] = $spans[$i];
			$ends = [];
			foreach ($resets as $reset) {
				if ($reset->place() <= $start->place() || $reset->place() > $end->place()) {
					continue;
				}
				$id = (int)$reset->getId();
				if (!array_key_exists($id, $before)) {
					$before[$id] = $this->readings->findNewestStanding($vehicleId, $counter, $reset->getReadAt(), $id);
				}
				// Never null: the segment's start stands before the reset.
				$ends[] = [$start, $before[$id] ?? $start];
				$start = $reset;
			}
			$ends[] = [$start, $end];
			$distances[] = self::run($ends);
		}

		return $distances;
	}

	/**
	 * The segments' runs summed; null when one ran backwards or nothing moved.
	 *
	 * @param list<array{OdoReading, OdoReading}> $ends
	 */
	private static function run(array $ends): ?int {
		$distance = 0;
		foreach ($ends as [$opens, $closes]) {
			$run = $closes->getValue() - $opens->getValue();
			if ($run < 0) {
				return null;
			}
			$distance += $run;
		}

		return $distance > 0 ? $distance : null;
	}

	/**
	 * Whether the counter was replaced after `$open` and up to `$close`, the swap at `$close` itself
	 * included.
	 *
	 * @param list<OdoReading> $resets
	 */
	private static function replacedBetween(array $resets, OdoReading $open, OdoReading $close): bool {
		foreach ($resets as $reset) {
			if ($reset->place() > $open->place() && $reset->place() <= $close->place()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One energy's segments, full tank to full tank, partials summed in. A missed fill-up, an end
	 * without an observed and unflagged Reading, or a counter replaced in between (rule 3) yields
	 * no number: a gap must produce no number rather than a wrong one.
	 *
	 * @param list<Energy> $chain in time order
	 * @param array<int, OdoReading> $counted each fill-up's live main-counter Reading, by its id
	 * @param list<OdoReading> $resets the main counter's answered resets
	 * @return list<Segment>
	 */
	private static function segments(array $chain, array $counted, array $resets, string $per): array {
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
			if ($open !== null && $close !== null && !$missed && !self::replacedBetween($resets, $open, $close)) {
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

	/** The Reading when a segment may be measured from it (docs/architecture.md#odometer-rules, rule 6). */
	private static function trusted(?OdoReading $reading): ?OdoReading {
		return $reading !== null && $reading->getOrigin() === OdometerService::OBSERVED && !$reading->getFlagged()
			? $reading
			: null;
	}
}
