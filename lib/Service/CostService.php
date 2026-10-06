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
	 * of() for many periods at once, as the Costs screen's months and their year ask: each table
	 * is read once over all of them, and the distances by ConsumptionService::distances().
	 *
	 * @param non-empty-list<array{int, int}> $periods each `[from, to]`, half-open
	 * @return list<Cost> in the periods' order
	 * @throws \OCP\DB\Exception
	 */
	public function periods(Vehicle $vehicle, array $periods, bool $net): array {
		$vehicleId = (int)$vehicle->getId();
		$from = min(array_column($periods, 0));
		$to = max(array_column($periods, 1));
		$fills = $this->energy->findBetween($vehicleId, $from, $to);
		$records = $this->maintenance->findBetween($vehicleId, $from, $to);
		$expenses = $this->expenses->findBetween($vehicleId, $from, $to);
		$distances = $this->consumption->distances($vehicle, $periods);
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
				$tco,
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
	 * @return Cost
	 * @throws \OCP\DB\Exception
	 */
	private function cost(Vehicle $vehicle, bool $net, array $fills, array $records, array $expenses, ?int $distance, \Closure $tco): array {
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
		// figure rather than 0 (docs/architecture.md#numbers-consumption-cost-emissions).
		$recorded = $fills !== [] || $records !== [] || $expenses !== [];
		$priced = $vehicle->getCurrency() !== null && $recorded;
		$value = $priced ? self::value($total, $distance, $per) : null;

		return [
			'currency' => $vehicle->getCurrency(),
			'net' => $net,
			'per' => $per,
			'distance' => $distance,
			'total' => $priced ? $total : null,
			'energy' => $priced ? $energy : null,
			'maintenance' => $priced ? $maintenance : null,
			'expenses' => $priced ? self::itemised($byCategory) : null,
			'value' => $value,
			'energy_value' => $priced ? self::value($energy, $distance, $per) : null,
			'tco' => $value === null ? null : $tco($value, $per),
			'incomplete' => $incomplete,
			'unstated' => $unstated,
		];
	}

	/**
	 * In the sheet's order, then any word it does not offer, then the uncategorised: they are not
	 * "other", they are unstated.
	 *
	 * @param array<string, int> $byCategory '' for no category
	 * @return list<Itemised>
	 */
	private static function itemised(array $byCategory): array {
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

	/** Cents per 100 km or per hour; none without a distance, when the period total stands alone. */
	private static function value(int $cents, ?int $distance, string $per): ?float {
		return $distance === null ? null : $cents * ($per === 'h' ? 1.0 : 100.0) / $distance;
	}
}
