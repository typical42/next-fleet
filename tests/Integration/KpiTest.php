<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The vehicle header's figures for one period, read against the real database.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class KpiTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private EnergyService $energy;
	private ExpenseService $expenses;
	private MaintenanceService $maintenance;
	private KpiService $kpis;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->energy = $container->get(EnergyService::class);
		$this->expenses = $container->get(ExpenseService::class);
		$this->maintenance = $container->get(MaintenanceService::class);
		$this->kpis = $container->get(KpiService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = ['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_expenses' => 'created_by'];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * A truck that also counts engine hours: consumption and cost against its kilometres, and the
	 * hours it ran in the period beside them.
	 */
	public function testATwoCounterTruckStatesConsumptionCostAndHoursForThePeriod(): void {
		$uuid = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'energy_types' => ['diesel'],
			'currency' => 'EUR',
		])->getUuid();
		$this->fill($uuid, 1750000000, 300000, 5100, 200000, 36000);
		$this->fill($uuid, 1750100000, 301000, 5140, 250000, 45000);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => 1750050000, 'done_at_off' => 120, 'title' => 'Oil change', 'cost' => 19000]);

		$kpis = $this->kpis->of(self::OWNER, $uuid, ['from' => 1749900000, 'to' => 1750200000, 'net' => 'false']);

		$this->assertSame([['energy' => 'diesel', 'amount' => 250000, 'distance' => 1000, 'per' => 'km', 'value' => 25.0]], $kpis['consumption']);
		$this->assertNull($kpis['wall_side']);
		$this->assertSame(100000, $kpis['cost']['total']);
		$this->assertSame(10000.0, $kpis['cost']['value']);
		$this->assertSame(40, $kpis['hours']);
	}

	/** A vehicle that counts no engine hours states none, rather than zero of them. */
	public function testAOneCounterVehicleStatesNoHours(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']])->getUuid();

		$kpis = $this->kpis->of(self::OWNER, $uuid, ['from' => 1749900000, 'to' => 1750200000]);

		$this->assertNull($kpis['hours']);
		$this->assertSame([], $kpis['consumption']);
	}

	/** A period that ends before it starts is not one this route hands out. */
	public function testAPeriodMustRunForwards(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();

		$this->expectException(\InvalidArgumentException::class);
		$this->kpis->of(self::OWNER, $uuid, ['from' => 1750200000, 'to' => 1750200000]);
	}

	/**
	 * The Costs screen's year: twelve months cut at midnight where the person is, each with its
	 * three bands and its categories, and the header's figures for the whole year.
	 */
	public function testAYearOfCostsIsTwelveLocalMonthsAndTheYearsFigures(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel'], 'currency' => 'EUR'])->getUuid();
		// 31 January 23:30 UTC is 1 February in Berlin.
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1738366200, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => 10000]);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => 1739000000, 'done_at_off' => 60, 'title' => 'Oil change', 'cost' => 19000, 'odo' => 10400]);
		$this->expenses->record(self::OWNER, $uuid, ['spent_at' => 1741600000, 'spent_at_off' => 60, 'category' => 'insurance', 'amount' => 30000]);

		$year = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin', 'net' => 'false']);

		$this->assertCount(12, $year['months']);
		$this->assertSame(1735686000, $year['months'][0]['from']);
		$this->assertSame(1738364400, $year['months'][0]['to']);
		$this->assertSame($year['months'][0]['to'], $year['months'][1]['from']);
		$this->assertSame(1767222000, $year['months'][11]['to']);

		$this->assertNull($year['months'][0]['cost']['total'], 'a month with no rows is not a zero');
		$this->assertSame(6000, $year['months'][1]['cost']['energy']);
		$this->assertSame(19000, $year['months'][1]['cost']['maintenance']);
		$this->assertSame([], $year['months'][1]['cost']['expenses']);
		$this->assertSame([['category' => 'insurance', 'total' => 30000]], $year['months'][2]['cost']['expenses']);
		$this->assertSame(0, $year['months'][2]['cost']['energy']);

		$this->assertSame(55000, $year['year']['cost']['total']);
		$this->assertSame(400, $year['year']['cost']['distance']);
		$this->assertNull($year['year']['hours']);
	}

	/** @dataProvider notAYear */
	public function testAYearNeedsFourDigitsAndAZone(string $year, string $zone): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();

		$this->expectException(\InvalidArgumentException::class);
		$this->kpis->year(self::OWNER, $uuid, $year, ['tz' => $zone]);
	}

	/** @return iterable<string, array{string, string}> */
	public static function notAYear(): iterable {
		yield 'a year with a fraction' => ['2025.5', 'Europe/Berlin'];
		yield 'five digits' => ['20250', 'Europe/Berlin'];
		yield 'no zone' => ['2025', ''];
		yield 'a zone that is not one' => ['2025', 'Europe/Atlantis'];
	}

	private function fill(string $uuid, int $at, int $odo, int $hours, int $amount, int $total): void {
		$this->energy->record(self::OWNER, $uuid, [
			'filled_at' => $at,
			'filled_at_off' => 120,
			'energy' => 'diesel',
			'amount' => $amount,
			'total' => $total,
			'full_tank' => true,
			'odo' => $odo,
			'second_odo' => $hours,
		]);
	}
}
