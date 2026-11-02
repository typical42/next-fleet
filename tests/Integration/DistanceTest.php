<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\ConsumptionService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * How far a counter moved in a period, against the real database: which Readings bound it, and
 * what an answered reset does to it (docs/architecture.md#odometer-rules, rule 3). Each case runs
 * on the lookups and on the chain read once (ConsumptionService::distancesAlong()): both find the
 * same Readings.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class DistanceTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-distance';
	private const DAY = 86400;
	private const T = 1750000000;

	private OdometerService $odometer;
	private VehicleService $vehicles;
	private ConsumptionService $consumption;

	protected function setUp(): void {
		$this->odometer = \OCP\Server::get(OdometerService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->consumption = \OCP\Server::get(ConsumptionService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * A month with one Reading in it still moved: the counter stood somewhere when it began, and
	 * that Reading is where the month's kilometres start.
	 */
	/** @dataProvider ways */
	public function testAPeriodCountsFromTheReadingStandingAtItsStart(bool $once): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 10 * self::DAY, 10500);
		$this->read($vehicle, self::T + 40 * self::DAY, 11000);

		$this->assertSame(500, $this->distance($once, $vehicle, self::T + self::DAY, self::T + 30 * self::DAY));
	}

	/**
	 * A Reading in question is neither end: it may be a typo.
	 *
	 * @dataProvider ways
	 */
	public function testAReadingInQuestionBoundsNoPeriod(bool $once): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 5 * self::DAY, 10500);
		$this->read($vehicle, self::T + 10 * self::DAY, 9000);

		$this->assertSame(500, $this->distance($once, $vehicle, self::T + self::DAY, self::T + 12 * self::DAY));
	}

	/**
	 * A counter replaced: what the old one ran up to the swap, and what the new one has run since,
	 * add up. The drop between them is no distance at all.
	 *
	 * @dataProvider ways
	 */
	public function testAnAnsweredResetAddsTheSegmentsOnEitherSide(bool $once): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 5 * self::DAY, 10500);
		$swap = $this->read($vehicle, self::T + 10 * self::DAY, 200);
		$this->read($vehicle, self::T + 15 * self::DAY, 700);

		$this->odometer->reset(self::OWNER, $vehicle->getUuid(), $swap->getUuid(), $swap->getUpdatedAt());

		$this->assertSame(1000, $this->distance($once, $vehicle, self::T + self::DAY, self::T + 20 * self::DAY));
		$this->assertSame(500, $this->distance($once, $vehicle, self::T + 11 * self::DAY, self::T + 20 * self::DAY));
		$this->assertSame(1000, $this->distance($once, $vehicle, PHP_INT_MIN, PHP_INT_MAX));
		$this->assertSame(500, $this->distance($once, $vehicle, self::T + 3 * self::DAY, self::T + 12 * self::DAY));
	}

	/**
	 * Periods side by side, as the Statistics screen's buckets and their range: the first starts
	 * before any Reading, and a counter replaced on an edge splits neither neighbour. The period
	 * that ends on the swap moved nothing.
	 *
	 * @dataProvider ways
	 */
	public function testPeriodsSideBySideShareTheirEdges(bool $once): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 5 * self::DAY, 10500);
		$swap = $this->read($vehicle, self::T + 10 * self::DAY, 200);
		$this->read($vehicle, self::T + 15 * self::DAY, 700);
		$this->odometer->reset(self::OWNER, $vehicle->getUuid(), $swap->getUuid(), $swap->getUpdatedAt());
		$edges = [self::T - 10 * self::DAY, self::T + 5 * self::DAY, self::T + 10 * self::DAY, self::T + 20 * self::DAY];
		$periods = [[$edges[0], $edges[1]], [$edges[1], $edges[2]], [$edges[2], $edges[3]], [$edges[0], $edges[3]]];

		$this->assertSame([500, null, 500, 1000], $once
			? $this->consumption->distancesAlong($vehicle, $periods)
			: $this->consumption->distances($vehicle, $periods));
	}

	/** @return array<string, array{bool}> */
	public static function ways(): array {
		return ['looked up' => [false], 'read once' => [true]];
	}

	private function distance(bool $once, Vehicle $vehicle, int $from, int $to): ?int {
		return $once
			? $this->consumption->distancesAlong($vehicle, [[$from, $to]])[0]
			: $this->consumption->distance($vehicle, $from, $to);
	}

	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'B-DI 4']);
	}

	private function read(Vehicle $vehicle, int $at, int $value): OdoReading {
		return $this->odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => $at, 'read_at_off' => 120, 'value' => $value]);
	}
}
