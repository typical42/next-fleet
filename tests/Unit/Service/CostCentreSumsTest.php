<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\CostCentreSums;
use PHPUnit\Framework\TestCase;

/**
 * The Statistics screen's "By cost centre" (docs/api.md#statistics): costs and distance over the
 * range, a row per cost centre and one for the entries that name none.
 */
class CostCentreSumsTest extends TestCase {
	private const SALES = 'uuid-sales';
	private const SERVICE = 'uuid-service';

	/**
	 * Each cost centre adds up what names it, in each currency and unit any row has, so the table
	 * has no gaps. VAT comes off where the reader reclaims it; an entry without a rate counts gross.
	 */
	public function testEachCostCentreAddsUpItsCostsAndItsDistance(): void {
		$car = $this->vehicle(1, 'EUR');
		$entries = [0 => [
			$this->fill(11900, 1900, self::SALES),
			$this->record(5950, 1900, self::SERVICE),
			$this->expense(1000, null, self::SALES),
			$this->expense(2380, 1900, null),
		]];
		$trips = [[
			$this->trip(120, self::SALES),
			$this->trip(30, self::SERVICE),
			$this->trip(null, null),
		]];

		$gross = CostCentreSums::of([$car], $entries, $trips, $this->centres(), false);
		$this->assertSame([
			['uuid' => self::SALES, 'code' => '4711', 'name' => 'Vertrieb', 'costs' => [['currency' => 'EUR', 'total' => 12900]], 'distance' => [['per' => 'km', 'trips' => 1, 'distance' => 120]]],
			['uuid' => self::SERVICE, 'code' => '4712', 'name' => 'Service', 'costs' => [['currency' => 'EUR', 'total' => 5950]], 'distance' => [['per' => 'km', 'trips' => 1, 'distance' => 30]]],
			['uuid' => null, 'code' => null, 'name' => null, 'costs' => [['currency' => 'EUR', 'total' => 2380]], 'distance' => [['per' => 'km', 'trips' => 1, 'distance' => 0]]],
		], $gross);

		$net = CostCentreSums::of([$car], $entries, $trips, $this->centres(), true);
		$this->assertSame([11000, 5000, 2000], array_map(static fn (array $row): int => $row['costs'][0]['total'], $net));
	}

	/**
	 * A vehicle whose costs are kept from the reader, or that has no currency, adds no cost; its
	 * trips count all the same, since every reader sees the logbook. Currencies never add up.
	 */
	public function testOnlyCostsTheReaderSeesCountAndDistanceAlways(): void {
		$shared = $this->vehicle(1, 'EUR');
		$kept = $this->vehicle(2, 'EUR', ['view', 'log']);
		$swiss = $this->vehicle(3, 'CHF');
		$bare = $this->vehicle(4, null);
		$hours = $this->vehicle(5, 'EUR', odoUnit: 'h');
		$entries = [
			0 => [$this->fill(1000, null, self::SALES)],
			1 => [$this->fill(9000, null, self::SALES), $this->expense(500, null, self::SALES)],
			2 => [$this->record(700, null, self::SALES)],
			3 => [$this->record(300, null, self::SALES)],
		];
		$trips = [[], [$this->trip(50, self::SALES)], [], [$this->trip(20, self::SALES)], [$this->trip(3, self::SALES)]];

		$this->assertSame([[
			'uuid' => self::SALES, 'code' => '4711', 'name' => 'Vertrieb',
			'costs' => [['currency' => 'CHF', 'total' => 700], ['currency' => 'EUR', 'total' => 1000]],
			'distance' => [['per' => 'h', 'trips' => 1, 'distance' => 3], ['per' => 'km', 'trips' => 2, 'distance' => 70]],
		]], CostCentreSums::of([$shared, $kept, $swiss, $bare, $hours], $entries, $trips, $this->centres(), false));
	}

	/**
	 * By code and name as the list reads them, numbers in their order; the entries that name none
	 * last. A cost centre nothing in the range names has no row, and nothing at all has none.
	 */
	public function testTheRowsGoByCodeAndNameAndNoneLast(): void {
		$centres = [
			'a' => $this->centre('a', '10', 'Zentrale'),
			'b' => $this->centre('b', '9', 'Werkstatt'),
			'c' => $this->centre('c', null, 'Ausbildung'),
			'd' => $this->centre('d', '11', 'Unused'),
		];
		$trips = [[$this->trip(1, null), $this->trip(1, 'a'), $this->trip(1, 'b'), $this->trip(1, 'c')]];

		$rows = CostCentreSums::of([$this->vehicle(1, 'EUR')], [], $trips, $centres, false);

		$this->assertSame(['b', 'a', 'c', null], array_column($rows, 'uuid'));
		$this->assertSame([], $rows[0]['costs'], 'no currency had a cost');
		$this->assertSame([], CostCentreSums::of([$this->vehicle(1, 'EUR')], [], [[]], $centres, false));
	}

	/** @param list<string> $may */
	private function vehicle(int $id, ?string $currency, array $may = ['view', 'log', 'costs'], string $odoUnit = 'km'): Vehicle {
		$vehicle = Vehicle::fromRow(['id' => $id, 'currency' => $currency, 'odo_unit' => $odoUnit]);
		$vehicle->setMay($may);

		return $vehicle;
	}

	/** @return array<string, CostCentre> */
	private function centres(): array {
		return [
			self::SALES => $this->centre(self::SALES, '4711', 'Vertrieb'),
			self::SERVICE => $this->centre(self::SERVICE, '4712', 'Service'),
		];
	}

	private function centre(string $uuid, ?string $code, string $name): CostCentre {
		return CostCentre::fromRow(['uuid' => $uuid, 'code' => $code, 'name' => $name, 'user_id' => 'owner']);
	}

	private function fill(?int $total, ?int $vatRate, ?string $centre): Energy {
		$fill = Energy::fromRow(['filled_at' => 500, 'energy' => 'diesel', 'amount' => 40000, 'total' => $total, 'vat_rate' => $vatRate]);
		$fill->setCostCentreUuid($centre);

		return $fill;
	}

	private function record(?int $cost, ?int $vatRate, ?string $centre): Maintenance {
		$record = Maintenance::fromRow(['done_at' => 500, 'title' => 'Oil change', 'cost' => $cost, 'vat_rate' => $vatRate]);
		$record->setCostCentreUuid($centre);

		return $record;
	}

	private function expense(int $amount, ?int $vatRate, ?string $centre): Expense {
		$expense = Expense::fromRow(['spent_at' => 500, 'amount' => $amount, 'vat_rate' => $vatRate]);
		$expense->setCostCentreUuid($centre);

		return $expense;
	}

	private function trip(?int $distance, ?string $centre): Trip {
		$trip = Trip::fromRow(['started_at' => 500, 'distance' => $distance, 'category' => Trip::BUSINESS]);
		$trip->setCostCentreUuid($centre);

		return $trip;
	}
}
