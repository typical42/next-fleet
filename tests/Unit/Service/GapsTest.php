<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\Gaps;
use PHPUnit\Framework\TestCase;

/**
 * The kilometres before a trip that no record accounts for: its `start_odo` claim, measured against
 * the Reading before it (docs/architecture.md#odometer-rules, rule 5).
 *
 * The mappers are stores that hand back what the real reads do - one vehicle's rows, voided ones
 * left out, in their order. That the queries do so is tests/Integration/TimelineTest.php's.
 */
class GapsTest extends TestCase {
	private const VEHICLE_ID = 7;
	private const DAY = 86400;
	private const T0 = 1750000000;

	/** @var list<Trip> */
	private array $tripRows = [];
	/** @var list<OdoReading> */
	private array $readingRows = [];

	private function gaps(): Gaps {
		$trips = $this->createMock(TripMapper::class);
		$trips->method('findAllForVehicle')->willReturnCallback(fn (): array => $this->ordered(
			$this->tripRows,
			static fn (Trip $trip): array => [$trip->getStartedAt(), (int)$trip->getId()],
		));
		$readings = $this->createMock(OdoReadingMapper::class);
		$readings->method('findAllForVehicle')->willReturnCallback(fn (): array => $this->ordered(
			$this->readingRows,
			static fn (OdoReading $reading): array => [$reading->getReadAt(), (int)$reading->getId()],
		));

		return new Gaps($trips, $readings);
	}

	/**
	 * @template T of Trip|OdoReading
	 * @param list<T> $rows
	 * @param callable(T): array{int, int} $key
	 * @return list<T>
	 */
	private function ordered(array $rows, callable $key): array {
		usort($rows, static fn ($a, $b): int => $key($a) <=> $key($b));

		return $rows;
	}

	private function vehicle(): Vehicle {
		return Vehicle::fromRow(['id' => self::VEHICLE_ID]);
	}

	/** An Odometer Entry: a counter somebody read, with no journey around it. */
	private function counter(int $at, int $value, bool $flagged = false): OdoReading {
		return $this->reading($at, $value, OdoReading::MANUAL, null, $flagged);
	}

	private function reading(int $at, int $value, string $sourceType, ?int $sourceId, bool $flagged = false): OdoReading {
		$reading = OdoReading::fromRow([
			'id' => count($this->readingRows) + 1,
			'vehicle_id' => self::VEHICLE_ID,
			'read_at' => $at,
			'read_at_off' => 120,
			'value' => $value,
			'flagged' => $flagged,
			'source_type' => $sourceType,
			'source_id' => $sourceId,
		]);
		$this->readingRows[] = $reading;

		return $reading;
	}

	/**
	 * A counter trip and the Reading it left at its end, as TripService writes the two.
	 */
	private function trip(int $startedAt, ?int $startOdo, int $endedAt, int $endOdo): Trip {
		$id = count($this->tripRows) + 1;
		$trip = Trip::fromRow([
			'id' => $id,
			'uuid' => sprintf('0195e2f1-1111-4000-8000-%012d', $id),
			'vehicle_id' => self::VEHICLE_ID,
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $endedAt,
			'ended_at_off' => 60,
			'start_odo' => $startOdo,
			'end_odo' => $endOdo,
		]);
		$this->tripRows[] = $trip;
		$this->reading($endedAt, $endOdo, OdoReading::TRIP, $id);

		return $trip;
	}

	public function testAClaimAboveTheReadingBeforeItIsAGap(): void {
		$this->counter(self::T0, 120000);
		$trip = $this->trip(self::T0 + self::DAY, 120250, self::T0 + self::DAY + 3600, 120330);

		$this->assertSame([[
			'trip' => $trip->getUuid(),
			'distance' => 250,
			'from_at' => self::T0,
			'from_at_off' => 120,
			'to_at' => self::T0 + self::DAY,
			'to_at_off' => 60,
		]], $this->gaps()->of($this->vehicle()));
	}

	/**
	 * Setting off where the counter stood is the gapless logbook. Setting off below it is a claim
	 * that contradicts the counter, which is no kilometres anybody drove unrecorded.
	 *
	 * @return array<string, array{int}>
	 */
	public static function accountedClaims(): array {
		return [
			'where the counter stood' => [120000],
			'below it' => [119990],
		];
	}

	/** @dataProvider accountedClaims */
	public function testAClaimThatDoesNotRiseAboveTheReadingBeforeItIsNoGap(int $claim): void {
		$this->counter(self::T0, 120000);
		$this->trip(self::T0 + self::DAY, $claim, self::T0 + self::DAY + 3600, 120080);

		$this->assertSame([], $this->gaps()->of($this->vehicle()));
	}

	public function testATripThatClaimsNoStartIsNeverMeasured(): void {
		$this->counter(self::T0, 120000);
		$this->trip(self::T0 + self::DAY, null, self::T0 + self::DAY + 3600, 120500);

		$this->assertSame([], $this->gaps()->of($this->vehicle()));
	}

	public function testTheFirstTripOnAVehicleHasNothingToBeMeasuredAgainst(): void {
		$this->trip(self::T0, 120000, self::T0 + 3600, 120080);

		$this->assertSame([], $this->gaps()->of($this->vehicle()));
	}

	/**
	 * The newest Reading at or before the start, the one a distance would count from (rule 6) - not
	 * the first one, and not one read while the journey was under way.
	 */
	public function testEachTripIsMeasuredAgainstTheNewestReadingAtOrBeforeItsStart(): void {
		$this->counter(self::T0, 119000);
		$this->trip(self::T0 + self::DAY, 119000, self::T0 + self::DAY + 3600, 120000);
		$this->counter(self::T0 + 2 * self::DAY, 120040);
		$second = $this->trip(self::T0 + 2 * self::DAY, 120100, self::T0 + 3 * self::DAY, 120900);
		$this->counter(self::T0 + 2 * self::DAY + 60, 120500);

		$this->assertSame(
			[[$second->getUuid(), 60, self::T0 + 2 * self::DAY]],
			array_map(
				static fn (array $gap): array => [$gap['trip'], $gap['distance'], $gap['from_at']],
				$this->gaps()->of($this->vehicle()),
			),
		);
	}

	/** A journey that ends the moment it starts has its own Reading at its start. It is not the one before. */
	public function testATripIsNeverMeasuredAgainstItsOwnReading(): void {
		$this->counter(self::T0, 120000);
		$this->trip(self::T0 + self::DAY, 120100, self::T0 + self::DAY, 120150);

		$this->assertSame(
			[100],
			array_column($this->gaps()->of($this->vehicle()), 'distance'),
		);
	}

	/**
	 * A flagged Reading is already a question (rule 3). A cluster swap and a typo look alike from
	 * here, and a gap counted from a typo is a number nobody drove.
	 */
	public function testAClaimMeasuredAgainstAReadingInQuestionStatesNoGap(): void {
		$this->counter(self::T0, 120000);
		$this->counter(self::T0 + 60, 12000, true);
		$this->trip(self::T0 + self::DAY, 120300, self::T0 + self::DAY + 3600, 120380);

		$this->assertSame([], $this->gaps()->of($this->vehicle()));
	}
}
