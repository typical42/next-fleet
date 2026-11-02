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

	/**
	 * A reader the costs are kept from gets the figures without a sum: every amount null, as a
	 * period with nothing recorded has it, so the shape stays what v1 promised. What the car
	 * used and how far it went are no costs.
	 */
	public function testAReaderWithoutCostsGetsTheFiguresWithoutTheMoney(): void {
		$service = $this->priced(['view', 'log']);

		$figures = $service->of('carol', '0195e2f1-0000-4000-8000-000000000001', ['from' => 0, 'to' => 1000]);
		$year = $service->year('carol', '0195e2f1-0000-4000-8000-000000000001', '2026', ['tz' => 'Europe/Berlin']);

		foreach ([$figures['cost'], $year['year']['cost'], ...array_column($year['months'], 'cost')] as $cost) {
			$this->assertSame(
				['total' => null, 'energy' => null, 'maintenance' => null, 'expenses' => null, 'value' => null, 'energy_value' => null,
					'value_per_hour' => null, 'energy_value_per_hour' => null, 'tco' => null, 'incomplete' => false, 'unstated' => false],
				array_diff_key($cost, array_flip(['currency', 'net', 'per', 'distance', 'hours'])),
			);
			$this->assertSame(['EUR', 1200], [$cost['currency'], $cost['distance']]);
		}
	}

	public function testAReaderWithCostsGetsTheMoney(): void {
		$figures = $this->priced(['view', 'log', 'costs'])->of('carol', '0195e2f1-0000-4000-8000-000000000001', ['from' => 0, 'to' => 1000]);

		$this->assertSame([9100, 7.58], [$figures['cost']['total'], $figures['cost']['value']]);
	}

	/** @param list<string> $may what the gate left on the vehicle */
	private function priced(array $may): KpiService {
		$vehicle = Vehicle::fromRow(['id' => 7, 'energy_types' => '["diesel"]', 'odo_unit' => 'km']);
		$vehicle->setMay($may);
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn($vehicle);
		$energy = $this->createMock(EnergyMapper::class);
		$energy->method('findAllForVehicle')->willReturn([]);
		$readings = $this->createMock(OdoReadingMapper::class);
		FakeChain::onto($readings, static fn (): array => []);
		$priced = [
			'currency' => 'EUR', 'net' => false, 'per' => 'km', 'distance' => 1200, 'hours' => null,
			'total' => 9100, 'energy' => 7500, 'maintenance' => 0, 'expenses' => [['category' => 'parking', 'total' => 1600]], 'value' => 7.58,
			'energy_value' => 6.25, 'value_per_hour' => null, 'energy_value_per_hour' => null, 'tco' => 12.5,
			'incomplete' => true, 'unstated' => true,
		];
		$cost = $this->createMock(CostService::class);
		$cost->method('of')->willReturn($priced);
		$cost->method('periods')->willReturn(array_fill(0, 13, $priced));

		return new KpiService(
			$fleet,
			new ConsumptionService($energy, $readings),
			$cost,
			$this->createMock(EmissionService::class),
			$this->createMock(PreferencesService::class),
		);
	}
}
