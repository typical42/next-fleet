<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\EnergyService;
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
	private MaintenanceService $maintenance;
	private KpiService $kpis;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->energy = $container->get(EnergyService::class);
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
		$tables = ['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by'];
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
