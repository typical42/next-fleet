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
use OCA\NextFleet\Db\Vehicle;

/**
 * What a vehicle cost in a period (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @psalm-type Cost = array{currency: ?string, net: bool, per: ?string, distance: ?int, total: ?int, energy: ?int, value: ?float, energy_value: ?float, tco: ?float, incomplete: bool, unstated: bool}
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
		$vehicleId = (int)$vehicle->getId();
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
		$fills = $this->energy->findBetween($vehicleId, $from, $to);
		foreach ($fills as $fill) {
			$incomplete = $incomplete || $fill->getTotal() === null;
			$energy += $count($fill->getTotal(), $fill->getVatRate());
		}
		$total = $energy;
		$records = $this->maintenance->findBetween($vehicleId, $from, $to);
		foreach ($records as $record) {
			$total += $count($record->getCost(), $record->getVatRate());
		}
		$expenses = $this->expenses->findBetween($vehicleId, $from, $to);
		foreach ($expenses as $expense) {
			$total += $count($expense->getAmount(), $expense->getVatRate());
		}
		$distance = $this->consumption->distance($vehicle, $from, $to);
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
			'value' => $value,
			'energy_value' => $priced ? self::value($energy, $distance, $per) : null,
			'tco' => $value === null ? null : $this->tco($vehicle, $value, $per),
			'incomplete' => $incomplete,
			'unstated' => $unstated,
		];
	}

	/**
	 * Cost per 100 km plus what the vehicle loses in value, spread over every kilometre it has run
	 * since it was first read, not over the period: depreciation belongs to the whole holding.
	 * Purchase and residual count as entered, since neither carries a VAT rate.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function tco(Vehicle $vehicle, float $value, string $per): ?float {
		$purchase = $vehicle->getPurchasePrice();
		$residual = $vehicle->getResidualEst();
		if ($purchase === null || $residual === null) {
			return null;
		}
		$held = $this->consumption->distance($vehicle, PHP_INT_MIN, PHP_INT_MAX);

		return $held === null ? null : $value + (float)self::value($purchase - $residual, $held, $per);
	}

	/** Cents per 100 km or per hour; none without a distance, when the period total stands alone. */
	private static function value(int $cents, ?int $distance, string $per): ?float {
		return $distance === null ? null : $cents * ($per === 'h' ? 1.0 : 100.0) / $distance;
	}
}
