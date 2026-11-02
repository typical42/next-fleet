<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;

/**
 * What a vehicle cost in a period (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @psalm-import-type NextFleetItemised from \OCA\NextFleet\ResponseDefinitions as Itemised
 * @psalm-import-type NextFleetCost from \OCA\NextFleet\ResponseDefinitions as Cost
 */
class CostService {
	public function __construct(
		private ConsumptionService $consumption,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
	) {
	}

	/**
	 * @return Cost
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle, int $from, int $to, bool $net): array {
		return $this->periods($vehicle, [[$from, $to]], $net)[0];
	}

	/**
	 * of() for many periods at once, as KpiService::year()'s months and their year ask: each table
	 * is read once over all of them, and the distances by ConsumptionService::distances().
	 *
	 * @param non-empty-list<array{int, int}> $periods each `[from, to]`, half-open
	 * @param bool $whole whether the last period is the others together, rated over them as the
	 *                    Statistics screen rates a range (rated())
	 * @return list<Cost> in the periods' order
	 * @throws \OCP\DB\Exception
	 */
	public function periods(Vehicle $vehicle, array $periods, bool $net, bool $whole = false): array {
		$vehicleId = (int)$vehicle->getId();
		$from = min(array_column($periods, 0));
		$to = max(array_column($periods, 1));
		$fills = $this->energy->findBetween($vehicleId, $from, $to);
		$records = $this->maintenance->findBetween($vehicleId, $from, $to);
		$expenses = $this->expenses->findBetween($vehicleId, $from, $to);
		$distances = $this->consumption->distances($vehicle, $periods);
		$hours = $vehicle->getSecondUnit() === null
			? array_fill(0, count($periods), null)
			: $this->consumption->distances($vehicle, $periods, OdoReading::SECOND);
		// Depreciation belongs to the whole holding, so its distance is read once for every period.
		$held = false;
		$tco = function (float $value, string $per) use ($vehicle, &$held): ?float {
			$purchase = $vehicle->getPurchasePrice();
			$residual = $vehicle->getResidualEst();
			if ($purchase === null || $residual === null) {
				return null;
			}
			if ($held === false) {
				$held = $this->consumption->distance($vehicle, PHP_INT_MIN, PHP_INT_MAX);
			}
			return $held === null ? null : $value + (float)self::value($purchase - $residual, $held, $per);
		};

		return $this->each($vehicle, $periods, $net, $fills, $records, $expenses, $distances, $hours, $tco, $whole);
	}

	/**
	 * periods() for the Statistics screen, whose periods may be many: no engine hours and no TCO,
	 * which it does not show, and the distances off one read of the counter
	 * (ConsumptionService::distancesAlong()). Its `hours` and `tco` are null.
	 *
	 * @param non-empty-list<array{int, int}> $periods each `[from, to]`, half-open
	 * @param list<Energy> $fills the vehicle's over the periods at least, read by the caller
	 * @return list<Cost> in the periods' order
	 * @throws \OCP\DB\Exception
	 */
	public function lean(Vehicle $vehicle, array $periods, bool $net, array $fills): array {
		$vehicleId = (int)$vehicle->getId();
		$from = min(array_column($periods, 0));
		$to = max(array_column($periods, 1));

		return $this->each(
			$vehicle,
			$periods,
			$net,
			$fills,
			$this->maintenance->findBetween($vehicleId, $from, $to),
			$this->expenses->findBetween($vehicleId, $from, $to),
			$this->consumption->distancesAlong($vehicle, $periods),
			array_fill(0, count($periods), null),
			static fn (): ?float => null,
		);
	}

	/**
	 * Each period's cost from rows read over all of them.
	 *
	 * @param non-empty-list<array{int, int}> $periods
	 * @param list<Energy> $fills
	 * @param list<Maintenance> $records
	 * @param list<Expense> $expenses
	 * @param list<?int> $distances by period
	 * @param list<?int> $hours by period
	 * @param \Closure(float, string): ?float $tco
	 * @param bool $whole as for periods()
	 * @return list<Cost>
	 * @throws \OCP\DB\Exception
	 */
	private function each(Vehicle $vehicle, array $periods, bool $net, array $fills, array $records, array $expenses, array $distances, array $hours, \Closure $tco, bool $whole = false): array {
		$costs = [];
		foreach ($periods as $i => [$start, $end]) {
			$within = static fn (int $at): bool => $at >= $start && $at < $end;
			$costs[] = $this->cost(
				$vehicle,
				$net,
				array_values(array_filter($fills, static fn (Energy $fill): bool => $within($fill->getFilledAt()))),
				array_values(array_filter($records, static fn (Maintenance $record): bool => $within($record->getDoneAt()))),
				array_values(array_filter($expenses, static fn (Expense $expense): bool => $within($expense->getSpentAt()))),
				$distances[$i],
				$hours[$i],
				$tco,
				$whole && $i === count($periods) - 1 ? $costs : null,
			);
		}

		return $costs;
	}

	/**
	 * One period's cost from its rows.
	 *
	 * @param list<Energy> $fills
	 * @param list<Maintenance> $records
	 * @param list<Expense> $expenses
	 * @param \Closure(float, string): ?float $tco
	 * @param list<Cost>|null $parts the periods it is made of, when it is rated over them (rated())
	 * @return Cost
	 * @throws \OCP\DB\Exception
	 */
	private function cost(Vehicle $vehicle, bool $net, array $fills, array $records, array $expenses, ?int $distance, ?int $hours, \Closure $tco, ?array $parts = null): array {
		$unstated = false;
		$count = static function (?int $gross, ?int $rate) use ($net, &$unstated): int {
			if ($gross === null || !$net) {
				return (int)$gross;
			}
			if ($rate === null) {
				$unstated = true;
				return $gross;
			}
			return (int)round($gross * 10000 / (10000 + $rate));
		};

		$energy = 0;
		$incomplete = false;
		foreach ($fills as $fill) {
			$incomplete = $incomplete || $fill->getTotal() === null;
			$energy += $count($fill->getTotal(), $fill->getVatRate());
		}
		$maintenance = 0;
		foreach ($records as $record) {
			$maintenance += $count($record->getCost(), $record->getVatRate());
		}
		$byCategory = [];
		foreach ($expenses as $expense) {
			$key = $expense->getCategory() ?? '';
			$byCategory[$key] = ($byCategory[$key] ?? 0) + $count($expense->getAmount(), $expense->getVatRate());
		}
		$total = $energy + $maintenance + array_sum($byCategory);
		$per = ConsumptionService::per($vehicle);
		// Money without a currency is a number nobody can read, and a period with no rows has no
		// figure rather than 0 (docs/architecture.md#numbers-consumption-cost-emissions). A whole
		// rated over its parts rates by rated(): one that moved and spent nothing rates 0.
		$recorded = $fills !== [] || $records !== [] || $expenses !== [];
		$priced = $vehicle->getCurrency() !== null && $recorded;
		$own = ['total' => $total, 'energy' => $energy, 'distance' => $distance, 'hours' => $hours];
		/**
		 * @param 'total'|'energy' $of
		 * @param 'distance'|'hours' $on
		 */
		$rate = static function (string $of, string $on, string $unit) use ($own, $priced, $parts, $vehicle): ?float {
			if ($parts === null) {
				return $priced ? self::value($own[$of], $own[$on], $unit) : null;
			}
			if ($vehicle->getCurrency() === null) {
				return null;
			}
			[$cents, $moved] = self::rated($parts, $of, $on);

			return $moved === 0 ? null : self::value($cents, $moved, $unit);
		};
		$value = $rate('total', 'distance', $per);

		return [
			'currency' => $vehicle->getCurrency(),
			'net' => $net,
			'per' => $per,
			'distance' => $distance,
			'hours' => $hours,
			'total' => $priced ? $total : null,
			'energy' => $priced ? $energy : null,
			'maintenance' => $priced ? $maintenance : null,
			'expenses' => $priced ? self::itemised($byCategory) : null,
			'value' => $value,
			'energy_value' => $rate('energy', 'distance', $per),
			'value_per_hour' => $rate('total', 'hours', 'h'),
			'energy_value_per_hour' => $rate('energy', 'hours', 'h'),
			'tco' => $value === null ? null : $tco($value, $per),
			'incomplete' => $incomplete,
			'unstated' => $unstated,
		];
	}

	/**
	 * A period's cost for a reader the costs are kept from (VehicleAccess::COSTS): every amount
	 * null, as in a period with nothing recorded, and nothing said about amounts they cannot see.
	 * What the period covered stays. Built whole rather than merged over, so a field added to
	 * the shape is one Psalm asks about here, not one handed out by default.
	 *
	 * @param Cost $cost
	 * @return Cost
	 */
	public static function withheld(array $cost): array {
		return [
			'currency' => $cost['currency'], 'net' => $cost['net'], 'per' => $cost['per'],
			'distance' => $cost['distance'], 'hours' => $cost['hours'],
			'total' => null, 'energy' => null, 'maintenance' => null, 'expenses' => null,
			'value' => null, 'energy_value' => null, 'value_per_hour' => null, 'energy_value_per_hour' => null,
			'tco' => null, 'incomplete' => false, 'unstated' => false,
		];
	}

	/**
	 * In the sheet's order, then any word it does not offer, then the uncategorised: they are not
	 * "other", they are unstated. Public for StatisticsService, which adds vehicles up by it.
	 *
	 * @param array<string, int> $byCategory '' for no category
	 * @return list<Itemised>
	 */
	public static function itemised(array $byCategory): array {
		$order = array_unique([...ExpenseService::CATEGORIES, ...array_map('strval', array_keys($byCategory))]);
		$items = [];
		foreach ($order as $category) {
			if (isset($byCategory[$category]) && $category !== '') {
				$items[] = ['category' => $category, 'total' => $byCategory[$category]];
			}
		}
		if (isset($byCategory[''])) {
			$items[] = ['category' => null, 'total' => $byCategory['']];
		}

		return $items;
	}

	/**
	 * What of some periods rates per distance: the costs of those with a distance, 0 where nothing
	 * was recorded, and those distances. A cost where the counter stood still is in no rate, so
	 * the periods' rate together lies between their own. Public for StatisticsService, which rates
	 * its buckets and its range by it.
	 *
	 * @param list<Cost> $periods
	 * @param 'total'|'energy' $of
	 * @param 'distance'|'hours' $on the counter it is rated against
	 * @return array{int, int} the cents and the distance
	 */
	public static function rated(array $periods, string $of = 'total', string $on = 'distance'): array {
		$cents = 0;
		$moved = 0;
		foreach ($periods as $period) {
			if ($period[$on] !== null) {
				$cents += (int)$period[$of];
				$moved += $period[$on];
			}
		}

		return [$cents, $moved];
	}

	/**
	 * Cents per 100 km or per hour; none without a distance, when the period total stands alone.
	 * Public for StatisticsService, which rates vehicles added up.
	 */
	public static function value(int $cents, ?int $distance, string $per): ?float {
		return $distance === null ? null : $cents * ($per === 'h' ? 1.0 : 100.0) / $distance;
	}
}
