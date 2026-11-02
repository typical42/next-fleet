<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The Statistics screen's figures: one vehicle or every one the reader sees, over a range the
 * reader picks, cut into months or years at the reader's midnight (docs/api.md#statistics).
 *
 * @psalm-import-type NextFleetCost from \OCA\NextFleet\ResponseDefinitions as Cost
 * @psalm-import-type NextFleetCostSum from \OCA\NextFleet\ResponseDefinitions as CostSum
 * @psalm-import-type NextFleetCostSeries from \OCA\NextFleet\ResponseDefinitions as CostSeries
 * @psalm-import-type NextFleetConsumptionLine from \OCA\NextFleet\ResponseDefinitions as ConsumptionLine
 * @psalm-import-type NextFleetDistanceSeries from \OCA\NextFleet\ResponseDefinitions as DistanceSeries
 * @psalm-import-type NextFleetDriverSeries from \OCA\NextFleet\ResponseDefinitions as DriverSeries
 * @psalm-import-type NextFleetTripDistance from \OCA\NextFleet\ResponseDefinitions as TripDistance
 * @psalm-import-type NextFleetDistanceCost from \OCA\NextFleet\ResponseDefinitions as DistanceCost
 * @psalm-import-type NextFleetPerDistance from \OCA\NextFleet\ResponseDefinitions as PerDistance
 * @psalm-import-type NextFleetStatistics from \OCA\NextFleet\ResponseDefinitions as Statistics
 */
class StatisticsService {
	/** Months as buckets up to this many, years beyond. */
	public const MONTHS = 24;
	private const YEARS = 100;

	public function __construct(
		private VehicleService $fleet,
		private CostService $cost,
		private ConsumptionService $consumption,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
		private TripMapper $trips,
		private VehicleAccess $access,
		private EnteredBy $names,
		private EmissionService $emissions,
		private PreferencesService $preferences,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string, mixed> $fields `vehicle`, a uuid, or none for all; `from` and `to`, days
	 *                                     as YYYY-MM-DD, `to` the day after the last; `tz`, an IANA
	 *                                     zone; `net` for "I reclaim VAT"
	 * @return Statistics
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see the vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the range, the zone or the vehicle is not one
	 * @throws \OCP\DB\Exception
	 */
	public function of(string $userId, array $fields): array {
		$zone = Field::zone('tz', $fields['tz'] ?? null);
		$net = Field::read('net', 'flag', null, $fields['net'] ?? null) === true;
		$uuid = self::given($fields, 'vehicle');
		$vehicles = $uuid === null
			? $this->fleet->list($userId)
			: [$this->fleet->reach($userId, VehicleAccess::VIEW, Field::text('vehicle', $uuid, 64))];

		[$from, $to, $all] = $this->range($vehicles, $fields, $zone);
		// All of it is years side by side however short: that is the year-over-year comparison.
		$bucket = !$all && count(self::cut($from, $to, 'month')) <= self::MONTHS ? 'month' : 'year';
		$periods = self::cut($from, $to, $bucket);

		$costs = $this->priced($vehicles, $periods, $from->getTimestamp(), $to->getTimestamp(), $net);
		// Nobody drove a Reconciliation Trip: it is the counter's word for distance no trip claims.
		$trips = array_map(fn (Vehicle $vehicle): array => array_values(array_filter(
			$this->trips->findBetween((int)$vehicle->getId(), $from->getTimestamp(), $to->getTimestamp()),
			static fn (Trip $trip): bool => !$trip->getReconciled(),
		)), $vehicles);

		return [
			'from' => $from->getTimestamp(),
			'to' => $to->getTimestamp(),
			'bucket' => $bucket,
			'buckets' => array_map(static fn (array $period): array => ['from' => $period[0], 'to' => $period[1]], $periods),
			'vehicles' => array_map(fn (Vehicle $vehicle): array => [
				'uuid' => $vehicle->getUuid(),
				'costs' => CostsShown::all($vehicle),
				'drivers' => $this->access->mayCompareDrivers($userId, $vehicle),
			], $vehicles),
			'costs' => self::costs($costs),
			'per_distance' => self::perDistance($vehicles, $costs),
			'consumption' => $this->consumption($vehicles, $from->getTimestamp(), $to->getTimestamp(), $uuid !== null),
			'distance' => self::distance($vehicles, $trips, $periods),
			'by_driver' => $this->byDriver($userId, $vehicles, $trips),
			// At the reader's own grid factor, as KpiService::year() reads it.
			'co2' => $uuid === null
				? null
				: $this->emissions->of($vehicles[0], $from->getTimestamp(), $to->getTimestamp(), $this->preferences->gridFactor($userId)),
		];
	}

	/**
	 * Each period's cost of every vehicle whose costs the reader sees, the range's last: a vehicle
	 * without a currency has no sum to add.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param non-empty-list<array{int, int}> $periods
	 * @return array<int, non-empty-list<Cost>> by the vehicle's index in `$vehicles`
	 * @throws \OCP\DB\Exception
	 */
	private function priced(array $vehicles, array $periods, int $from, int $to, bool $net): array {
		$costs = [];
		foreach ($vehicles as $i => $vehicle) {
			if ($vehicle->getCurrency() !== null && CostsShown::all($vehicle)) {
				/** @var non-empty-list<Cost> one per period, as asked */
				$costs[$i] = $this->cost->periods($vehicle, [...$periods, [$from, $to]], $net);
			}
		}

		return $costs;
	}

	/**
	 * Each currency's sums: nothing is added up across currencies.
	 *
	 * @param array<int, non-empty-list<Cost>> $priced as priced() reads them
	 * @return list<CostSeries> by currency code
	 */
	private static function costs(array $priced): array {
		/** @var array<string, list<list<Cost>>> $byCurrency each period's costs, the range's last */
		$byCurrency = [];
		foreach ($priced as $costs) {
			foreach ($costs as $i => $cost) {
				$byCurrency[(string)$cost['currency']][$i][] = $cost;
			}
		}
		ksort($byCurrency);

		$series = [];
		foreach ($byCurrency as $currency => $costs) {
			$sums = array_map(self::sum(...), $costs);
			$range = array_pop($sums);
			$series[] = ['currency' => $currency, 'buckets' => $sums, 'range' => $range];
		}

		return $series;
	}

	/**
	 * @param list<Cost> $costs one period's, a vehicle each
	 * @return CostSum
	 */
	private static function sum(array $costs): array {
		$recorded = false;
		$energy = 0;
		$maintenance = 0;
		$byCategory = [];
		$incomplete = false;
		$unstated = false;
		foreach ($costs as $cost) {
			$incomplete = $incomplete || $cost['incomplete'];
			$unstated = $unstated || $cost['unstated'];
			if ($cost['total'] === null) {
				continue;
			}
			$recorded = true;
			$energy += (int)$cost['energy'];
			$maintenance += (int)$cost['maintenance'];
			foreach ($cost['expenses'] ?? [] as $item) {
				$key = $item['category'] ?? '';
				$byCategory[$key] = ($byCategory[$key] ?? 0) + $item['total'];
			}
		}

		return [
			'total' => $recorded ? $energy + $maintenance + array_sum($byCategory) : null,
			'energy' => $recorded ? $energy : null,
			'maintenance' => $recorded ? $maintenance : null,
			'expenses' => $recorded ? CostService::itemised($byCategory) : null,
			'incomplete' => $incomplete,
			'unstated' => $unstated,
		];
	}

	/**
	 * Cost per 100 km, or per hour, in one series per currency and unit, and each vehicle's over
	 * the range beside it.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param array<int, non-empty-list<Cost>> $priced as priced() reads them
	 * @return list<PerDistance> by currency code, then unit
	 */
	private static function perDistance(array $vehicles, array $priced): array {
		/** @var array<string, array{currency: string, per: string, periods: list<list<Cost>>, vehicles: list<array{uuid: string, range: DistanceCost}>}> $series */
		$series = [];
		foreach ($priced as $i => $costs) {
			$range = $costs[count($costs) - 1];
			$key = $range['currency'] . ' ' . (string)$range['per'];
			$series[$key] ??= ['currency' => (string)$range['currency'], 'per' => (string)$range['per'], 'periods' => [], 'vehicles' => []];
			foreach ($costs as $j => $cost) {
				$series[$key]['periods'][$j][] = $cost;
			}
			$series[$key]['vehicles'][] = ['uuid' => $vehicles[$i]->getUuid(), 'range' => self::rate([$range], (string)$range['per'])];
		}
		ksort($series);

		$answer = [];
		foreach ($series as $one) {
			$rates = array_map(static fn (array $costs): array => self::rate($costs, $one['per']), $one['periods']);
			$range = array_pop($rates);
			$answer[] = ['currency' => $one['currency'], 'per' => $one['per'], 'buckets' => $rates, 'range' => $range, 'vehicles' => $one['vehicles']];
		}

		return $answer;
	}

	/**
	 * Each vehicle's consumption per energy over the segments that close in the range, summed as
	 * ConsumptionService sums a period. Measured on the main counter; an energy that closed no
	 * segment in the range has no line.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param bool $segments whether each line carries its segments, which only one vehicle's draws
	 * @return list<ConsumptionLine> by vehicle, then the energy's first segment in the range
	 * @throws \OCP\DB\Exception
	 */
	private function consumption(array $vehicles, int $from, int $to, bool $segments): array {
		$lines = [];
		foreach ($vehicles as $vehicle) {
			$all = $this->consumption->of($vehicle);
			foreach (ConsumptionService::summed($all, $from, $to, ConsumptionService::per($vehicle))['period'] as $period) {
				$lines[] = [
					'uuid' => $vehicle->getUuid(),
					'energy' => $period['energy'],
					'per' => $period['per'],
					'amount' => $period['amount'],
					'distance' => $period['distance'],
					'value' => $period['value'],
					'segments' => $segments
						? array_values(array_filter($all, static fn (array $one): bool => $one['energy'] === $period['energy'] && $one['filled_at'] >= $from && $one['filled_at'] < $to))
						: null,
				];
			}
		}

		return $lines;
	}

	/**
	 * The distance driven by trip category in one series per unit, each trip in the bucket it set
	 * off in. A trip without kilometres (Trip::kilometres()) counts as a trip and adds no distance.
	 * A unit no trip in the range was driven in has no series.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param list<list<Trip>> $trips each vehicle's in the range, by its index in `$vehicles`
	 * @param non-empty-list<array{int, int}> $periods
	 * @return list<DistanceSeries> by unit
	 */
	private static function distance(array $vehicles, array $trips, array $periods): array {
		$none = ['trips' => 0, Trip::BUSINESS => 0, Trip::PRIVATE => 0, Trip::COMMUTE => 0];
		/** @var array<string, non-empty-list<TripDistance>> $byUnit each period's, the range's last */
		$byUnit = [];
		foreach ($trips as $i => $driven) {
			foreach ($driven as $trip) {
				$per = $vehicles[$i]->getOdoUnit();
				$byUnit[$per] ??= array_fill(0, count($periods) + 1, $none);
				foreach ([self::periodOf($periods, $trip->getStartedAt()), count($periods)] as $j) {
					$byUnit[$per][$j]['trips']++;
					$byUnit[$per][$j][$trip->getCategory()] += (int)$trip->kilometres();
				}
			}
		}
		ksort($byUnit);

		$series = [];
		foreach ($byUnit as $per => $sums) {
			$range = array_pop($sums);
			$series[] = ['per' => $per, 'buckets' => $sums, 'range' => $range];
		}

		return $series;
	}

	/**
	 * Each driver's trips, distance and business distance over the range, in one series per unit,
	 * by who entered the trip: there is no driver column (EnteredBy). Every driver's on a vehicle
	 * the reader may compare them on, else the reader's own (VehicleAccess::mayCompareDrivers()).
	 *
	 * @param list<Vehicle> $vehicles
	 * @param list<list<Trip>> $trips each vehicle's in the range, by its index in `$vehicles`
	 * @return list<DriverSeries> by unit, each by name
	 */
	private function byDriver(string $userId, array $vehicles, array $trips): array {
		/** @var array<string, array<string, array{trips: int, distance: int, business: int}>> $byUnit */
		$byUnit = [];
		foreach ($trips as $i => $driven) {
			$every = $this->access->mayCompareDrivers($userId, $vehicles[$i]);
			$per = $vehicles[$i]->getOdoUnit();
			foreach ($driven as $trip) {
				$driver = $trip->getCreatedBy();
				if (!$every && $driver !== $userId) {
					continue;
				}
				$byUnit[$per][$driver] ??= ['trips' => 0, 'distance' => 0, 'business' => 0];
				$kilometres = (int)$trip->kilometres();
				$byUnit[$per][$driver]['trips']++;
				$byUnit[$per][$driver]['distance'] += $kilometres;
				if ($trip->getCategory() === Trip::BUSINESS) {
					$byUnit[$per][$driver]['business'] += $kilometres;
				}
			}
		}
		ksort($byUnit);

		$series = [];
		foreach ($byUnit as $per => $sums) {
			$drivers = [];
			foreach ($sums as $driver => $sum) {
				$drivers[] = ['user_id' => (string)$driver, 'name' => $this->names->name((string)$driver)] + $sum;
			}
			usort($drivers, static fn (array $a, array $b): int => [$a['name'], $a['user_id']] <=> [$b['name'], $b['user_id']]);
			$series[] = ['per' => (string)$per, 'drivers' => $drivers];
		}

		return $series;
	}

	/**
	 * The index of the period `$at` falls in.
	 *
	 * @param non-empty-list<array{int, int}> $periods in order, each ending where the next begins
	 */
	private static function periodOf(array $periods, int $at): int {
		foreach ($periods as $i => [, $end]) {
			if ($at < $end) {
				return $i;
			}
		}

		return count($periods) - 1;
	}

	/**
	 * One period's costs and distances added up, and the rate over the vehicles that have both:
	 * one that cost without moving, or moved without a cost, would bend it.
	 *
	 * @param list<Cost> $costs one period's, a vehicle each, all in one currency and in `$per`
	 * @return DistanceCost
	 */
	private static function rate(array $costs, string $per): array {
		$total = null;
		$distance = null;
		$ratedCents = 0;
		$ratedDistance = 0;
		foreach ($costs as $cost) {
			if ($cost['total'] !== null) {
				$total = (int)$total + $cost['total'];
			}
			if ($cost['distance'] !== null) {
				$distance = (int)$distance + $cost['distance'];
			}
			if ($cost['total'] !== null && $cost['distance'] !== null) {
				$ratedCents += $cost['total'];
				$ratedDistance += $cost['distance'];
			}
		}

		return [
			'total' => $total,
			'distance' => $distance,
			'value' => $ratedDistance === 0 ? null : CostService::value($ratedCents, $ratedDistance, $per),
		];
	}

	/**
	 * The range's two midnights and whether it is all of it. All of it runs from the first
	 * January of the earliest entry on any of the vehicles to the end of the reader's year, or is
	 * the reader's year alone when there is none.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param array<string, mixed> $fields
	 * @return array{\DateTimeImmutable, \DateTimeImmutable, bool}
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function range(array $vehicles, array $fields, \DateTimeZone $zone): array {
		$fromDay = self::given($fields, 'from');
		$toDay = self::given($fields, 'to');
		if ($fromDay === null && $toDay === null) {
			$now = (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone);
			$first = $this->firstAt(array_map(static fn (Vehicle $vehicle): int => (int)$vehicle->getId(), $vehicles));
			$start = $first === null ? $now : (new \DateTimeImmutable('@' . $first))->setTimezone($zone);
			$from = self::january((int)$start->format('Y'), $zone);
			$to = self::january((int)$now->format('Y') + 1, $zone);
		} else {
			$from = self::day('from', $fromDay, $zone);
			$to = self::day('to', $toDay, $zone);
		}
		if ($from >= $to) {
			throw new \InvalidArgumentException('from and to are a range, the one before the other');
		}
		// A bound on the work one read asks for, well beyond any vehicle's life.
		if ($from->add(new \DateInterval('P' . self::YEARS . 'Y')) < $to) {
			throw new \InvalidArgumentException('A range is ' . self::YEARS . ' years at most');
		}

		return [$from, $to, $fromDay === null];
	}

	/**
	 * @param list<int> $vehicleIds
	 * @throws \OCP\DB\Exception
	 */
	private function firstAt(array $vehicleIds): ?int {
		$firsts = array_filter([
			$this->energy->firstAmong($vehicleIds),
			$this->maintenance->firstAmong($vehicleIds),
			$this->expenses->firstAmong($vehicleIds),
			$this->trips->firstAmong($vehicleIds),
		], is_int(...));

		return $firsts === [] ? null : min($firsts);
	}

	/**
	 * The calendar months or years the range touches, the first and the last cut to it.
	 *
	 * @param 'month'|'year' $bucket
	 * @return non-empty-list<array{int, int}>
	 */
	private static function cut(\DateTimeImmutable $from, \DateTimeImmutable $to, string $bucket): array {
		$periods = [];
		$start = $from;
		while ($start < $to) {
			$next = $bucket === 'month'
				? $start->modify('first day of next month')->setTime(0, 0)
				: self::january((int)$start->format('Y') + 1, $from->getTimezone());
			$end = min($next, $to);
			$periods[] = [$start->getTimestamp(), $end->getTimestamp()];
			$start = $end;
		}
		/** @var non-empty-list<array{int, int}> $periods from < to, so the loop ran */
		return $periods;
	}

	private static function january(int $year, \DateTimeZone $zone): \DateTimeImmutable {
		return new \DateTimeImmutable(sprintf('%04d-01-01', $year), $zone);
	}

	/**
	 * A query field, null when it is absent or empty. Not Field::read(): nothing here is stored,
	 * and each field is checked by what it names.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function given(array $fields, string $name): mixed {
		$value = $fields[$name] ?? null;

		return $value === '' ? null : $value;
	}

	/** @throws \InvalidArgumentException if it is not a day, or only one end is named */
	private static function day(string $column, mixed $value, \DateTimeZone $zone): \DateTimeImmutable {
		if ($value === null) {
			throw new \InvalidArgumentException('from and to are a range, or neither for all of it');
		}
		$day = Field::day($column, $value);

		return new \DateTimeImmutable($day->format('Y-m-d'), $zone);
	}
}
