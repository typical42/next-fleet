<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\ConsumptionService;
use OCA\NextFleet\Service\CostService;
use OCA\NextFleet\Service\OdometerService;
use PHPUnit\Framework\TestCase;

/**
 * Cost per 100 km over a period (docs/architecture.md#numbers-consumption-cost-emissions). The
 * mappers are stores: the period filter is the mapper's, so each store holds only in-period rows.
 */
class CostServiceTest extends TestCase {
	private const VEHICLE_ID = 7;

	/** @var list<Energy> */
	private array $fills = [];
	/** @var list<Maintenance> */
	private array $records = [];
	/** @var list<Expense> */
	private array $expenses = [];
	/** @var list<OdoReading> */
	private array $readingRows = [];

	protected function setUp(): void {
		$this->fills = [];
		$this->records = [];
		$this->expenses = [];
		$this->readingRows = [];
	}

	public function testEveryCostInThePeriodOverTheDistanceDrivenInIt(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(8000, 1900);
		$this->maintain(12000, 1900);
		$this->spend(5000, null);

		$this->assertSame([
			'currency' => 'EUR',
			'net' => false,
			'per' => 'km',
			'distance' => 1000,
			'total' => 25000,
			'energy' => 8000,
			'value' => 2500.0,
			'energy_value' => 800.0,
			'tco' => null,
			'incomplete' => false,
			'unstated' => false,
		], $this->of($this->vehicle()));
	}

	public function testTcoAddsTheDepreciationPerHundredKilometresOverTheHoldingPeriod(): void {
		$this->reading(-500, 5000);
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->reading(1500, 15000);
		$this->fill(8000, 1900);
		$this->maintain(12000, 1900);
		$this->spend(5000, null);

		$cost = $this->of($this->vehicle(purchase: 3000000, residual: 1000000));

		$this->assertSame(2500.0, $cost['value']);
		$this->assertSame(22500.0, $cost['tco']);
	}

	public function testTcoHidesWhenPurchaseOrResidualIsEmpty(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(8000, 1900);

		$this->assertNull($this->of($this->vehicle(purchase: 3000000))['tco']);
		$this->assertNull($this->of($this->vehicle(residual: 1000000))['tco']);
		$this->assertSame(200800.0, $this->of($this->vehicle(purchase: 3000000, residual: 1000000))['tco']);
	}

	public function testAReclaimerSeesEachRowNetOfItsOwnRate(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(11900, 1900);
		$this->maintain(10700, 700);
		$this->spend(5000, 0);

		$cost = $this->of($this->vehicle(), net: true);

		$this->assertTrue($cost['net']);
		$this->assertSame(25000, $cost['total']);
		$this->assertSame(10000, $cost['energy']);
		$this->assertSame(2500.0, $cost['value']);
		$this->assertFalse($cost['unstated']);
	}

	public function testUnderNetARowWithoutAStatedRateCountsGrossAndTheFigureSaysSo(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(11900, 1900);
		$this->spend(5000, null);

		$cost = $this->of($this->vehicle(), net: true);

		$this->assertSame(15000, $cost['total']);
		$this->assertTrue($cost['unstated']);
	}

	public function testAFillUpWithoutAPriceMakesTheFigureIncomplete(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(8000, 1900);
		$this->fill(null, 1900);

		$cost = $this->of($this->vehicle());

		$this->assertSame(8000, $cost['energy']);
		$this->assertTrue($cost['incomplete']);
	}

	public function testAMaintenanceRecordWithoutACostLeavesTheFigureComplete(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->maintain(null, null);

		$cost = $this->of($this->vehicle(), net: true);

		$this->assertFalse($cost['incomplete']);
		$this->assertFalse($cost['unstated']);
	}

	public function testWithoutADistanceInThePeriodTheCostIsATotalForThePeriod(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000, flagged: true);
		$this->fill(8000, 1900);
		$this->spend(5000, null);

		$cost = $this->of($this->vehicle());

		$this->assertNull($cost['distance']);
		$this->assertSame(13000, $cost['total']);
		$this->assertSame(8000, $cost['energy']);
		$this->assertNull($cost['value']);
		$this->assertNull($cost['energy_value']);
	}

	public function testAPeriodWithoutRowsHasNoCostRatherThanZero(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);

		$cost = $this->of($this->vehicle(purchase: 3000000, residual: 1000000));

		$this->assertSame(1000, $cost['distance']);
		$this->assertNull($cost['total']);
		$this->assertNull($cost['energy']);
		$this->assertNull($cost['value']);
		$this->assertNull($cost['energy_value']);
		$this->assertNull($cost['tco']);
	}

	public function testARecordWithoutACostStillMakesThePeriodCostZero(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->maintain(null, null);

		$cost = $this->of($this->vehicle());

		$this->assertSame(0, $cost['total']);
		$this->assertSame(0, $cost['energy']);
		$this->assertSame(0.0, $cost['value']);
	}

	public function testAnHourCountedVehicleCostsPerHour(): void {
		$this->reading(100, 1000);
		$this->reading(900, 1040);
		$this->fill(8000, null);

		$cost = $this->of($this->vehicle(odoUnit: 'h'));

		$this->assertSame('h', $cost['per']);
		$this->assertSame(200.0, $cost['value']);
	}

	public function testATwoCounterVehicleCostsPerHundredKilometres(): void {
		$this->reading(100, 10000);
		$this->reading(900, 10500);
		$this->fill(8000, null);

		$cost = $this->of($this->vehicle(secondUnit: 'h'));

		$this->assertSame('km', $cost['per']);
		$this->assertSame(1600.0, $cost['value']);
	}

	public function testAVehicleWithoutACurrencyHasNoCostFigures(): void {
		$this->reading(100, 10000);
		$this->reading(900, 11000);
		$this->fill(8000, 1900);

		$cost = $this->of($this->vehicle(currency: null));

		$this->assertNull($cost['currency']);
		$this->assertNull($cost['total']);
		$this->assertNull($cost['energy']);
		$this->assertNull($cost['value']);
		$this->assertNull($cost['energy_value']);
	}

	/** @return array<string, mixed> */
	private function of(Vehicle $vehicle, bool $net = false): array {
		return $this->service()->of($vehicle, 0, 1000, $net);
	}

	private function service(): CostService {
		$energy = $this->createMock(EnergyMapper::class);
		$energy->method('findBetween')->willReturnCallback(fn (): array => $this->fills);
		$maintenance = $this->createMock(MaintenanceMapper::class);
		$maintenance->method('findBetween')->willReturnCallback(fn (): array => $this->records);
		$expenses = $this->createMock(ExpenseMapper::class);
		$expenses->method('findBetween')->willReturnCallback(fn (): array => $this->expenses);
		$readings = $this->createMock(OdoReadingMapper::class);
		$readings->method('findChain')->willReturnCallback(fn (): array => $this->readingRows);

		return new CostService(new ConsumptionService($energy, $readings), $energy, $maintenance, $expenses);
	}

	private function vehicle(?string $currency = 'EUR', string $odoUnit = 'km', ?string $secondUnit = null, ?int $purchase = null, ?int $residual = null): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'currency' => $currency,
			'odo_unit' => $odoUnit,
			'second_unit' => $secondUnit,
			'purchase_price' => $purchase,
			'residual_est' => $residual,
		]);
	}

	private function reading(int $at, int $value, bool $flagged = false): void {
		$this->readingRows[] = OdoReading::fromRow([
			'id' => count($this->readingRows) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'read_at' => $at,
			'read_at_off' => 120,
			'value' => $value,
			'origin' => OdometerService::OBSERVED,
			'flagged' => $flagged,
			'source_type' => OdoReading::MANUAL,
			'counter' => OdoReading::MAIN,
		]);
	}

	private function fill(?int $total, ?int $vatRate): void {
		$this->fills[] = Energy::fromRow([
			'id' => count($this->fills) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'filled_at' => 500,
			'filled_at_off' => 120,
			'energy' => 'petrol',
			'amount' => 40000,
			'total' => $total,
			'vat_rate' => $vatRate,
		]);
	}

	private function maintain(?int $cost, ?int $vatRate): void {
		$this->records[] = Maintenance::fromRow([
			'id' => count($this->records) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'done_at' => 500,
			'done_at_off' => 120,
			'title' => 'Oil change',
			'cost' => $cost,
			'vat_rate' => $vatRate,
		]);
	}

	private function spend(int $amount, ?int $vatRate): void {
		$this->expenses[] = Expense::fromRow([
			'id' => count($this->expenses) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'spent_at' => 500,
			'spent_at_off' => 120,
			'amount' => $amount,
			'vat_rate' => $vatRate,
		]);
	}
}
