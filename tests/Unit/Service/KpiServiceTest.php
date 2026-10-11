<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\ConsumptionService;
use OCA\NextFleet\Service\CostService;
use OCA\NextFleet\Service\EmissionService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\TestCase;

/**
 * What the figures are is tests/Integration/KpiTest.php's, over real SQL. This holds what a
 * header costs the database.
 */
class KpiServiceTest extends TestCase {
	/** The fill-up history grows with the vehicle's age, so one period reads it once for both counters. */
	public function testFiguresReadTheFillUpHistoryOnceForBothCounters(): void {
		$vehicle = Vehicle::fromRow(['id' => 7, 'energy_types' => '["diesel"]', 'odo_unit' => 'km', 'second_unit' => 'h']);
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn($vehicle);
		$energy = $this->createMock(EnergyMapper::class);
		$energy->expects($this->once())->method('findAllForVehicle')->willReturn([]);
		$energy->method('findBetween')->willReturn([]);
		$readings = $this->createMock(OdoReadingMapper::class);
		FakeChain::onto($readings, static fn (): array => []);
		$cost = $this->createMock(CostService::class);
		$cost->method('of')->willReturn([
			'currency' => null, 'net' => false, 'per' => null, 'distance' => null, 'hours' => null,
			'total' => null, 'energy' => null, 'maintenance' => null, 'expenses' => null, 'value' => null,
			'energy_value' => null, 'value_per_hour' => null, 'energy_value_per_hour' => null, 'tco' => null,
			'incomplete' => false, 'unstated' => false,
		]);
		$service = new KpiService(
			$fleet,
			new ConsumptionService($energy, $readings),
			$cost,
			$this->createMock(EmissionService::class),
			$this->createMock(PreferencesService::class),
		);

		$figures = $service->of('alice', '0195e2f1-0000-4000-8000-000000000001', ['from' => 0, 'to' => 1000]);

		$this->assertSame([], $figures['consumption_per_hour']);
	}
}
