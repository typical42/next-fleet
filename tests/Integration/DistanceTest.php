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
 * what an answered reset does to it (docs/architecture.md#odometer-rules, rule 3).
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
	public function testAPeriodCountsFromTheReadingStandingAtItsStart(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 10 * self::DAY, 10500);
		$this->read($vehicle, self::T + 40 * self::DAY, 11000);

		$this->assertSame(500, $this->consumption->distance($vehicle, self::T + self::DAY, self::T + 30 * self::DAY));
	}

	/** A Reading in question is neither end: it may be a typo. */
	public function testAReadingInQuestionBoundsNoPeriod(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 5 * self::DAY, 10500);
		$this->read($vehicle, self::T + 10 * self::DAY, 9000);

		$this->assertSame(500, $this->consumption->distance($vehicle, self::T + self::DAY, self::T + 12 * self::DAY));
	}

	/**
	 * A counter replaced: what the old one ran up to the swap, and what the new one has run since,
	 * add up. The drop between them is no distance at all.
	 */
	public function testAnAnsweredResetAddsTheSegmentsOnEitherSide(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, self::T, 10000);
		$this->read($vehicle, self::T + 5 * self::DAY, 10500);
		$swap = $this->read($vehicle, self::T + 10 * self::DAY, 200);
		$this->read($vehicle, self::T + 15 * self::DAY, 700);

		$this->odometer->reset(self::OWNER, $vehicle->getUuid(), $swap->getUuid(), $swap->getUpdatedAt());

		$this->assertSame(1000, $this->consumption->distance($vehicle, self::T + self::DAY, self::T + 20 * self::DAY));
		$this->assertSame(500, $this->consumption->distance($vehicle, self::T + 11 * self::DAY, self::T + 20 * self::DAY));
		$this->assertSame(1000, $this->consumption->distance($vehicle, PHP_INT_MIN, PHP_INT_MAX));
		$this->assertSame(500, $this->consumption->distance($vehicle, self::T + 3 * self::DAY, self::T + 12 * self::DAY));
	}

	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'B-DI 4']);
	}

	private function read(Vehicle $vehicle, int $at, int $value): OdoReading {
		return $this->odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => $at, 'read_at_off' => 120, 'value' => $value]);
	}
}
