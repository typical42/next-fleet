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

	/** A full tank at the same reading ends no segment: its fuel counts towards the next one. */
	public function testAFullTankAtTheSameReadingCarriesItsFuelIntoTheNextSegment(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(150, 'petrol', 10000, 10000);
		$closing = $this->fill(200, 'petrol', 30000, 10500);

		$segments = $this->of($this->vehicle(['petrol']));

		$this->assertSame([[$closing->getUuid(), 40000, 500, 8.0]], array_map(
			static fn (array $segment): array => [$segment['closes'], $segment['amount'], $segment['distance'], $segment['value']],
			$segments,
		));
	}

	public function testAFullTankAtTheSameEngineHoursCarriesItsFuelIntoTheNextHourSegment(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 100);
		$this->fill(200, 'diesel', 30000, 50100, second: 100);
		$closing = $this->fill(300, 'diesel', 50000, 51000, second: 110);

		$segments = $this->service()->of($this->vehicle(['diesel'], 'km', 'h'), OdoReading::SECOND);

		$this->assertSame([[$closing->getUuid(), 80000, 10, 8.0]], array_map(
			static fn (array $segment): array => [$segment['closes'], $segment['amount'], $segment['distance'], $segment['value']],
			$segments,
		));
	}

	public function testAFullTankBelowTheOpeningReadingStillBreaksTheChain(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(150, 'petrol', 10000, 9900);
		$closing = $this->fill(200, 'petrol', 30000, 10500);

		$this->assertSame([[$closing->getUuid(), 30000, 600]], array_map(
			static fn (array $segment): array => [$segment['closes'], $segment['amount'], $segment['distance']],
			$this->of($this->vehicle(['petrol'])),
		));
	}

	/**
	 * A counter replaced between two full tanks leaves the old counter's last kilometres unknown,
	 * so the segment over the swap yields no number, and the next one counts on the new counter.
	 */
	public function testASegmentOverAnAnsweredResetYieldsNone(): void {
		$this->fill(100, 'petrol', 40000, 150000);
		$this->read(150, 140000, OdoReading::RESET);
		$this->fill(200, 'petrol', 30000, 150500);
		$this->fill(300, 'petrol', 36000, 151000);

		$segments = $this->of($this->vehicle(['petrol']));

		$this->assertSame([[300, 500]], array_map(static fn (array $segment): array => [$segment['filled_at'], $segment['distance']], $segments));
	}

	/**
	 * A segment that ran backwards hides an unanswered question; the kilometres after a reset do
	 * not make it a number.
	 */
	public function testADistanceWithASegmentRunningBackwardsIsNone(): void {
		$this->read(100, 10000);
		$this->read(200, 9000, flagged: true);
		$this->read(300, 9500);
		$this->read(400, 0, OdoReading::RESET);
		$this->read(500, 3000);

		$this->assertNull($this->service()->distance($this->vehicle(['petrol']), 0, 1000));
	}

	/**
	 * Many periods at once, as the Costs screen's months and year ask, each measured as it would
	 * be alone: one before any Reading, one after the vehicle's first, one over the swap, and the
	 * whole span.
	 */
	public function testManyPeriodsAreEachMeasuredAsTheyWouldBeAlone(): void {
		$this->read(100, 10000);
		$this->read(200, 10500);
		$this->read(300, 0, OdoReading::RESET);
		$this->read(400, 800);
		$this->read(500, 1000);

		$distances = $this->service()->distances($this->vehicle(['petrol']), [[0, 50], [0, 250], [250, 450], [450, 1000], [0, 1000]]);

		$this->assertSame([null, 500, 800, 200, 1500], $distances);
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

	public function testATwoCounterVehicleIsAlsoMeasuredAgainstItsEngineHours(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$closing = $this->fill(200, 'diesel', 150000, 51000, second: 3040);

		$this->assertSame([[
			'energy' => 'diesel',
			'closes' => $closing->getUuid(),
			'filled_at' => 200,
			'amount' => 150000,
			'distance' => 40,
			'per' => 'h',
			'value' => 3.75,
		]], $this->service()->of($this->vehicle(['diesel'], 'km', 'h'), OdoReading::SECOND));
	}

	/** Kilometres at both ends make a segment per 100 km, not one per hour. */
	public function testAFillUpWithoutEngineHoursEndsNoHourSegmentAndStartsNone(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000);
		$this->fill(300, 'diesel', 150000, 52000, second: 3100);

		$vehicle = $this->vehicle(['diesel'], 'km', 'h');
		$this->assertSame([], $this->service()->of($vehicle, OdoReading::SECOND));
		$this->assertCount(2, $this->service()->of($vehicle));
	}

	public function testAFlaggedHourReadingAtAnEndSkipsTheHourSegment(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000);
		$this->readingRows[] = OdoReading::fromRow([
			'id' => count($this->readingRows) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'read_at' => 200,
			'read_at_off' => 120,
			'value' => 3040,
			'origin' => OdometerService::OBSERVED,
			'flagged' => true,
			'source_type' => OdoReading::ENERGY,
			'source_id' => 2,
			'counter' => OdoReading::SECOND,
		]);

		$vehicle = $this->vehicle(['diesel'], 'km', 'h');
		$this->assertSame([], $this->service()->of($vehicle, OdoReading::SECOND));
		$this->assertCount(1, $this->service()->of($vehicle));
	}

	public function testAnHourSegmentOverAReplacedHourCounterYieldsNone(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->read(150, 0, OdoReading::RESET, counter: OdoReading::SECOND);
		$this->fill(200, 'diesel', 150000, 51000, second: 20);
		$this->fill(300, 'diesel', 150000, 52000, second: 60);

		$segments = $this->service()->of($this->vehicle(['diesel'], 'km', 'h'), OdoReading::SECOND);

		$this->assertSame([[300, 40]], array_map(static fn (array $segment): array => [$segment['filled_at'], $segment['distance']], $segments));
	}

	public function testAPeriodPerHourSumsTheHourSegmentsClosingInIt(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000, second: 3040);
		$this->fill(300, 'diesel', 90000, 52000, second: 3060);

		$this->assertSame(
			[['energy' => 'diesel', 'amount' => 240000, 'distance' => 60, 'per' => 'h', 'value' => 4.0]],
			$this->service()->measuredOnEachCounter($this->vehicle(['diesel'], 'km', 'h'), 150, 400)[OdoReading::SECOND]['period'],
		);
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
			$this->wallSide($this->vehicle(['petrol', 'electric']), 100, 350),
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
		], $this->service()->measuredOnEachCounter($this->vehicle(['petrol', 'electric']), 150, 500)[OdoReading::MAIN]['period']);
	}

	public function testAPeriodWithoutAClosedSegmentStatesNoConsumption(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500);

		$this->assertSame([], $this->service()->measuredOnEachCounter($this->vehicle(['petrol']), 300, 400)[OdoReading::MAIN]['period']);
	}

	/** A period that closed no segment still knows the energy was measured once. */
	public function testMeasuredNamesTheEnergiesThatEverClosedASegment(): void {
		$this->fill(100, 'petrol', 40000, 10000);
		$this->fill(200, 'petrol', 30000, 10500);
		$this->fill(300, 'electric', 8000, 10600);

		$this->assertSame(
			[OdoReading::MAIN => ['period' => [], 'measured' => ['petrol']]],
			$this->service()->measuredOnEachCounter($this->vehicle(['petrol', 'electric']), 300, 400),
		);
	}

	public function testMeasuredPerHourNeedsEngineHoursAtBothEnds(): void {
		$this->fill(100, 'diesel', 200000, 50000, second: 3000);
		$this->fill(200, 'diesel', 150000, 51000);

		$measured = $this->service()->measuredOnEachCounter($this->vehicle(['diesel'], 'km', 'h'), 0, 1000);
		$this->assertSame(['period' => [], 'measured' => []], $measured[OdoReading::SECOND]);
		$this->assertSame(['diesel'], $measured[OdoReading::MAIN]['measured']);
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
		FakeChain::onto($readings, fn (): array => $this->readingRows);

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

	/** An Odometer Entry of the kind given, on the main counter unless the case says otherwise. */
	private function read(int $at, int $value, string $kind = OdoReading::READING, bool $flagged = false, string $counter = OdoReading::MAIN): void {
		$this->readingRows[] = OdoReading::fromRow([
			'id' => count($this->readingRows) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'read_at' => $at,
			'read_at_off' => 120,
			'value' => $value,
			'kind' => $kind,
			'origin' => OdometerService::OBSERVED,
			'flagged' => $flagged,
			'source_type' => OdoReading::MANUAL,
			'counter' => $counter,
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
