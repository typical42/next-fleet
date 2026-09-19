<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\ConsumptionService;
use OCA\NextFleet\Service\OdometerService;
use PHPUnit\Framework\TestCase;

/**
 * Consumption between two full tanks (docs/architecture.md#numbers-consumption-cost-emissions).
 * The mappers are stores: what matters is the segments made of the rows, not the queries.
 */
class ConsumptionServiceTest extends TestCase {
	private const VEHICLE_ID = 7;

	/** @var list<Energy> */
	private array $fills = [];
	/** @var list<OdoReading> */
	private array $readingRows = [];

	protected function setUp(): void {
		$this->fills = [];
		$this->readingRows = [];
	}

	public function testAFullToFullSegmentYieldsLitresPerHundredKilometresOnItsClosingFillUp(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$closing = $this->fill(200, 'petrol', 30000, 10500);

		$this->assertSame([[
			'energy' => 'petrol',
			'closes' => $closing->getUuid(),
			'filled_at' => 200,
			'amount' => 30000,
			'distance' => 500,
			'per' => 'km',
			'value' => 6.0,
		]], $this->of($this->vehicle(['petrol'])));
	}

	public function testAVehiclesFirstFillUpYieldsNone(): void {
		$this->fill(100, 'petrol', 40000, 10000);

		$this->assertSame([], $this->of($this->vehicle(['petrol'])));
	}

	public function testPartialFillUpsAreAggregatedIntoTheSegmentTheyFallIn(): void {
		$this->fill(100, 'diesel', 50000, 20000);
		$this->fill(150, 'diesel', 10000, 20200, full: false);
		$this->fill(170, 'diesel', 5000, null, full: false);
		$this->fill(200, 'diesel', 21000, 20600);

		$segments = $this->of($this->vehicle(['diesel']));

		$this->assertCount(1, $segments);
		$this->assertSame(36000, $segments[0]['amount']);
		$this->assertSame(600, $segments[0]['distance']);
		$this->assertSame(6.0, $segments[0]['value']);
	}

	public function testAMissedPreviousFillUpSkipsTheSegmentItFallsIn(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(150, 'petrol', 10000, 10200, full: false, missed: true);
		$this->fill(200, 'petrol', 20000, 10500);
		$after = $this->fill(300, 'petrol', 35000, 11000);

		$this->assertSame([$after->getUuid()], array_column($this->of($this->vehicle(['petrol'])), 'closes'));
	}

	public function testAClosingFillUpWithMissedPreviousYieldsNone(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 20000, 10500, missed: true);

		$this->assertSame([], $this->of($this->vehicle(['petrol'])));
	}

	public function testAFillUpWithoutACounterEndsNoSegmentAndStartsNone(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, null);
		$this->fill(300, 'petrol', 30000, 11000);

		$this->assertSame([], $this->of($this->vehicle(['petrol'])));
	}

	public function testAFlaggedReadingAtEitherEndSkipsTheSegment(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500, flagged: true);
		$this->fill(300, 'petrol', 30000, 11000);
		$last = $this->fill(400, 'petrol', 30000, 11500);

		$this->assertSame([$last->getUuid()], array_column($this->of($this->vehicle(['petrol'])), 'closes'));
	}

	public function testADerivedReadingAtAnEndSkipsTheSegment(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500, origin: OdometerService::DERIVED);

		$this->assertSame([], $this->of($this->vehicle(['petrol'])));
	}

	public function testACounterThatDidNotMoveForwardYieldsNone(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10000);

		$this->assertSame([], $this->of($this->vehicle(['petrol'])));
	}

	public function testAPlugInHybridMeasuresEachEnergyOnItsOwnChain(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(110, 'electric', 8000, 10050, full: false);
		$this->fill(120, 'electric', 10000, 10100);
		$this->fill(150, 'electric', 9000, 10160, full: false);
		$charge = $this->fill(180, 'electric', 9000, 10200);
		$petrol = $this->fill(200, 'petrol', 25000, 10500);

		$segments = $this->of($this->vehicle(['petrol', 'electric']));

		$byEnergy = array_column($segments, null, 'energy');
		$this->assertSame($charge->getUuid(), $byEnergy['electric']['closes']);
		$this->assertSame(18000, $byEnergy['electric']['amount']);
		$this->assertSame(100, $byEnergy['electric']['distance']);
		$this->assertSame(18.0, $byEnergy['electric']['value']);
		$this->assertSame($petrol->getUuid(), $byEnergy['petrol']['closes']);
		$this->assertSame(25000, $byEnergy['petrol']['amount']);
		$this->assertSame(500, $byEnergy['petrol']['distance']);
		$this->assertSame(5.0, $byEnergy['petrol']['value']);
	}

	public function testAnHourCountedVehicleYieldsLitresPerHour(): void {
		$this->fill(100, 'diesel', 60000, 1200);
		$this->fill(200, 'diesel', 45000, 1210);

		$segments = $this->of($this->vehicle(['diesel'], 'h'));

		$this->assertSame('h', $segments[0]['per']);
		$this->assertSame(4.5, $segments[0]['value']);
	}

	public function testATwoCounterVehicleMeasuresAgainstKilometres(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000, second: 3040);

		$segments = $this->of($this->vehicle(['diesel'], 'km', 'h'));

		$this->assertSame('km', $segments[0]['per']);
		$this->assertSame(1000, $segments[0]['distance']);
		$this->assertSame(15.0, $segments[0]['value']);
	}

	public function testAnHourReadingAloneIsNoCounterForATwoCounterVehicle(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, null, second: 3040);

		$this->assertSame([], $this->of($this->vehicle(['diesel'], 'km', 'h')));
	}

	public function testWallSideSumsEveryChargeInThePeriodOverTheDistanceDrivenInIt(): void {
		$this->fill(50, 'electric', 30000, 9000, full: false);
		$this->fill(100, 'electric', 12000, 10000, full: false);
		$this->fill(150, 'petrol', 30000, 10200);
		$this->fill(200, 'electric', 9000, null, full: false);
		$this->fill(300, 'electric', 15000, 10400, full: false);
		$this->fill(400, 'electric', 20000, 11000, full: false);

		$this->assertSame(
			['amount' => 36000, 'distance' => 400, 'per' => 'km', 'value' => 9.0],
			$this->wallSide($this->vehicle(['petrol', 'electric']), 100, 400),
		);
	}

	public function testWallSideLeavesOutAReadingTheChainQuestions(): void {
		$this->fill(100, 'electric', 10000, 10000, full: false);
		$this->fill(200, 'electric', 10000, 10500, full: false);
		$this->fill(300, 'electric', 10000, 99999, full: false, flagged: true);

		$this->assertSame(500, $this->wallSide($this->vehicle(['electric']), 0, 1000)['distance'] ?? null);
	}

	public function testWallSideIsNothingWithoutAChargeInThePeriod(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500);
		$this->fill(500, 'electric', 10000, 11000, full: false);

		$this->assertNull($this->wallSide($this->vehicle(['petrol', 'electric']), 0, 500));
	}

	public function testWallSideIsNothingWithoutADistanceInThePeriod(): void {
		$this->fill(100, 'electric', 10000, 10000, full: false);
		$this->fill(200, 'electric', 10000, null, full: false);

		$this->assertNull($this->wallSide($this->vehicle(['electric']), 0, 1000));
	}

	public function testWallSideOnAnHourCountedVehicleIsPerHour(): void {
		$this->fill(100, 'electric', 20000, 500, full: false);
		$this->fill(200, 'electric', 30000, 510, full: false);

		$this->assertSame(
			['amount' => 50000, 'distance' => 10, 'per' => 'h', 'value' => 5.0],
			$this->wallSide($this->vehicle(['electric'], 'h'), 0, 1000),
		);
	}

	public function testAPeriodSumsTheSegmentsClosingInItPerEnergyRatherThanAveragingThem(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500);
		$this->fill(300, 'petrol', 90000, 11000);
		$this->fill(310, 'electric', 8000, 11000);
		$this->fill(320, 'electric', 9000, 11050);
		$this->fill(500, 'petrol', 30000, 11500);

		$this->assertSame([
			['energy' => 'petrol', 'amount' => 120000, 'distance' => 1000, 'per' => 'km', 'value' => 12.0],
			['energy' => 'electric', 'amount' => 9000, 'distance' => 50, 'per' => 'km', 'value' => 18.0],
		], $this->service()->period($this->vehicle(['petrol', 'electric']), 150, 500));
	}

	public function testAPeriodWithoutAClosedSegmentStatesNoConsumption(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500);

		$this->assertSame([], $this->service()->period($this->vehicle(['petrol']), 300, 400));
	}

	public function testTheSecondCounterMovesOnItsOwnChain(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000, second: 3040);

		$vehicle = $this->vehicle(['diesel'], 'km', 'h');
		$this->assertSame(40, $this->service()->distance($vehicle, 0, 1000, OdoReading::SECOND));
		$this->assertSame(1000, $this->service()->distance($vehicle, 0, 1000));
	}

	/** @return array<string, mixed>|null */
	private function wallSide(Vehicle $vehicle, int $from, int $to): ?array {
		return $this->service()->wallSide($vehicle, $from, $to);
	}

	/** @return list<array<string, mixed>> */
	private function of(Vehicle $vehicle): array {
		return $this->service()->of($vehicle);
	}

	private function service(): ConsumptionService {
		$energy = $this->createMock(EnergyMapper::class);
		$energy->method('findAllForVehicle')->willReturnCallback(fn (int $vehicleId): array => $this->fills);
		$energy->method('findBetween')->willReturnCallback(
			fn (int $vehicleId, int $from, int $to): array => array_values(array_filter(
				$this->fills,
				static fn (Energy $fill): bool => $fill->getFilledAt() >= $from && $fill->getFilledAt() < $to,
			)),
		);
		$readings = $this->createMock(OdoReadingMapper::class);
		$readings->method('findForSources')->willReturnCallback(
			fn (int $vehicleId, string $sourceType, array $ids): array => array_values(array_filter(
				$this->readingRows,
				static fn (OdoReading $reading): bool => $reading->getSourceType() === $sourceType
					&& in_array($reading->getSourceId(), $ids, true),
			)),
		);
		$readings->method('findChain')->willReturnCallback(
			fn (int $vehicleId, string $counter): array => array_values(array_filter(
				$this->readingRows,
				static fn (OdoReading $reading): bool => $reading->getCounter() === $counter,
			)),
		);

		return new ConsumptionService($energy, $readings);
	}

	/** @param list<string> $energyTypes */
	private function vehicle(array $energyTypes, string $odoUnit = 'km', ?string $secondUnit = null): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'energy_types' => json_encode($energyTypes),
			'odo_unit' => $odoUnit,
			'second_unit' => $secondUnit,
		]);
	}

	/**
	 * A fill-up in time order, and the Readings its counters wrote - Observed and unflagged unless
	 * the case says otherwise, as the energy service writes them.
	 */
	private function fill(
		int $at,
		string $energy,
		int $amount,
		?int $odo,
		bool $full = true,
		bool $missed = false,
		bool $flagged = false,
		string $origin = OdometerService::OBSERVED,
		?int $second = null,
	): Energy {
		$id = count($this->fills) + 1;
		$fill = Energy::fromRow([
			'id' => $id,
			'uuid' => sprintf('0195e2f1-2222-4000-8000-%012d', $id),
			'vehicle_id' => self::VEHICLE_ID,
			'filled_at' => $at,
			'filled_at_off' => 120,
			'odo' => $odo,
			'second_odo' => $second,
			'energy' => $energy,
			'amount' => $amount,
			'full_tank' => $full,
			'missed_previous' => $missed,
		]);
		$this->fills[] = $fill;
		foreach ([OdoReading::MAIN => $odo, OdoReading::SECOND => $second] as $counter => $value) {
			if ($value !== null) {
				$this->readingRows[] = OdoReading::fromRow([
					'id' => count($this->readingRows) + 1,
					'vehicle_id' => self::VEHICLE_ID,
					'read_at' => $at,
					'read_at_off' => 120,
					'value' => $value,
					'origin' => $origin,
					'flagged' => $flagged,
					'source_type' => OdoReading::ENERGY,
					'source_id' => $id,
					'counter' => $counter,
				]);
			}
		}

		return $fill;
	}
}
