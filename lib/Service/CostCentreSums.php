<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;

/**
 * The Statistics screen's "By cost centre" (docs/api.md#statistics): over the range, what the
 * entries naming each cost centre cost and how far its trips went. A trip's costs are not split
 * by distance: a cost counts where its own entry names it.
 *
 * @psalm-import-type NextFleetCostCentreSum from \OCA\NextFleet\ResponseDefinitions as CentreSum
 */
final class CostCentreSums {
	/**
	 * @param list<Vehicle> $vehicles
	 * @param array<int, list<Energy|Maintenance|Expense>> $entries each vehicle's in the range, by
	 *                                                              its index in `$vehicles`
	 * @param list<list<Trip>> $trips each vehicle's driven in the range, by its index
	 * @param array<string, CostCentre> $centres the records the rows name, by uuid
	 * @param bool $net whether VAT comes off, as the reader's costs count it
	 * @return list<CentreSum> by code and name, the entries naming none last; a row has every
	 *                         currency and unit any row has, by code
	 */
	public static function of(array $vehicles, array $entries, array $trips, array $centres, bool $net): array {
		/** @var array<string, array<string, int>> $costs by cost centre ('' for none), then currency */
		$costs = [];
		foreach ($entries as $i => $rows) {
			$currency = $vehicles[$i]->getCurrency();
			// As the cost series: a vehicle whose costs are kept, or that has no currency, adds none.
			if ($currency === null || !$vehicles[$i]->showsCosts()) {
				continue;
			}
			foreach ($rows as $row) {
				$key = (string)$row->getCostCentreUuid();
				$costs[$key][$currency] = ($costs[$key][$currency] ?? 0) + CostService::amount($row, $net);
			}
		}
		/** @var array<string, array<string, array{int, int}>> $distance by cost centre, then unit: trips and distance */
		$distance = [];
		foreach ($trips as $i => $driven) {
			$per = $vehicles[$i]->getOdoUnit();
			foreach ($driven as $trip) {
				$key = (string)$trip->getCostCentreUuid();
				[$count, $moved] = $distance[$key][$per] ?? [0, 0];
				$distance[$key][$per] = [$count + 1, $moved + (int)$trip->kilometres()];
			}
		}

		$currencies = array_unique(array_merge([], ...array_map(array_keys(...), array_values($costs))));
		$units = array_unique(array_merge([], ...array_map(array_keys(...), array_values($distance))));
		sort($currencies);
		sort($units);
		$keys = array_map('strval', array_keys($costs + $distance));
		usort($keys, static fn (string $a, string $b): int => self::compare($centres[$a] ?? null, $centres[$b] ?? null) ?: strcmp($a, $b));

		return array_map(static function (string $key) use ($centres, $costs, $distance, $currencies, $units): array {
			$centre = $centres[$key] ?? null;

			return [
				'uuid' => $key === '' ? null : $key,
				'code' => $centre?->getCode(),
				'name' => $centre?->getName(),
				'costs' => array_map(static fn (string $currency): array => ['currency' => $currency, 'total' => $costs[$key][$currency] ?? 0], $currencies),
				'distance' => array_map(static fn (string $per): array => ['per' => $per, 'trips' => ($distance[$key][$per] ?? [0, 0])[0], 'distance' => ($distance[$key][$per] ?? [0, 0])[1]], $units),
			];
		}, $keys);
	}

	/** By code, numbers in their order, then those without one; then by name; none last. */
	private static function compare(?CostCentre $a, ?CostCentre $b): int {
		if ($a === null || $b === null) {
			return ($a === null) <=> ($b === null);
		}

		return (($a->getCode() === null) <=> ($b->getCode() === null))
			?: strnatcasecmp((string)$a->getCode(), (string)$b->getCode())
			?: strnatcasecmp($a->getName(), $b->getName());
	}
}
