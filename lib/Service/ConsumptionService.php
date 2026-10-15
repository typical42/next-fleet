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
	 * watt-hours, `distance` the counter's advance, and `value` litres or kWh per 100 km, or per
	 * hour when the counter counts hours. A two-counter vehicle is measured against its kilometres
	 * by default: the hours are work beside the driving, not instead of it. Against its engine
	 * hours (`SECOND`), a segment needs hours at both ends, as on the main counter.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<Segment>
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle, string $counter = OdoReading::MAIN): array {
		return $this->chained($vehicle, $this->energy->findAllForVehicle((int)$vehicle->getId()), $counter);
	}

	/**
	 * of() on every counter the vehicle has, its fill-ups read once for both: the history grows
	 * with the vehicle's age, the Readings per counter do not repeat.
	 *
	 * @return array<OdoReading::MAIN|OdoReading::SECOND, list<Segment>> `SECOND` only on a vehicle
	 *                                                                   that counts engine hours
	 * @throws \OCP\DB\Exception
	 */
	public function ofEachCounter(Vehicle $vehicle): array {
		$fills = $this->energy->findAllForVehicle((int)$vehicle->getId());
		$chains = [OdoReading::MAIN => $this->chained($vehicle, $fills, OdoReading::MAIN)];
		if ($vehicle->getSecondUnit() !== null) {
			$chains[OdoReading::SECOND] = $this->chained($vehicle, $fills, OdoReading::SECOND);
		}

		return $chains;
	}

	/**
	 * @param list<Energy> $fills the vehicle's fill-ups, oldest first
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<Segment>
	 * @throws \OCP\DB\Exception
	 */
	private function chained(Vehicle $vehicle, array $fills, string $counter): array {
		$vehicleId = (int)$vehicle->getId();
		$counted = [];
		foreach ($this->readings->findOfSourceType($vehicleId, OdoReading::ENERGY, $counter) as $reading) {
			$counted[(int)$reading->getSourceId()] = $reading;
		}

		$chains = [];
		foreach ($fills as $fill) {
			$chains[$fill->getEnergy()][] = $fill;
		}

		$resets = $this->readings->findResets($vehicleId, $counter, PHP_INT_MIN, PHP_INT_MAX);
		$per = self::per($vehicle, $counter);
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
	 * Per counter the vehicle has, keyed as ofEachCounter(): each energy's consumption over the
	 * segments that close in `[from, to)` (`period`), and the energies that closed a segment at
	 * any time (`measured`). The period sums amounts over distances, so a long segment weighs more
	 * than a short one - averaging the segments' values would not. An energy never measured has a
	 * reason the screen can name; a period that closed no segment has none.
	 *
	 * @return array<OdoReading::MAIN|OdoReading::SECOND, array{period: list<Period>, measured: list<string>}>
	 * @throws \OCP\DB\Exception
	 */
	public function measuredOnEachCounter(Vehicle $vehicle, int $from, int $to): array {
		$measured = [];
		foreach ($this->ofEachCounter($vehicle) as $counter => $segments) {
			$measured[$counter] = self::summed($segments, $from, $to, self::per($vehicle, $counter));
		}

		return $measured;
	}

	/**
	 * @param list<Segment> $segments
	 * @return array{period: list<Period>, measured: list<string>}
	 */
	private static function summed(array $segments, int $from, int $to, string $per): array {
		$sums = [];
		$measured = [];
		foreach ($segments as $segment) {
			$measured[$segment['energy']] = true;
			if ($segment['filled_at'] < $from || $segment['filled_at'] >= $to) {
				continue;
			}
			$sum = $sums[$segment['energy']] ?? ['amount' => 0, 'distance' => 0];
			$sums[$segment['energy']] = [
				'amount' => $sum['amount'] + $segment['amount'],
				'distance' => $sum['distance'] + $segment['distance'],
			];
		}

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

		return ['period' => $periods, 'measured' => array_map(strval(...), array_keys($measured))];
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
	 * without an observed and unflagged Reading, a counter replaced in between (rule 3) or one that
	 * ran backwards yields no number: a gap must produce no number rather than a wrong one.
	 *
	 * @param list<Energy> $chain in time order
	 * @param array<int, OdoReading> $counted each fill-up's live Reading on the counter measured, by its id
	 * @param list<OdoReading> $resets that counter's answered resets
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
				// A full tank at the opening reading measures nothing; closing on it would drop its
				// fuel, so the segment runs on to the next full tank.
				if ($distance === 0) {
					continue;
				}
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

	/**
	 * The unit a counter counts. The second counter only ever counts hours
	 * (VehicleService::SECOND_UNITS).
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 */
	public static function per(Vehicle $vehicle, string $counter = OdoReading::MAIN): string {
		return $counter === OdoReading::SECOND || $vehicle->getOdoUnit() === 'h' ? 'h' : 'km';
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
