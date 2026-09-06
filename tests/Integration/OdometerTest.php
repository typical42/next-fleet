<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The odometer against the real database: the order the readings come back in, and what the
 * vehicle shows once they have.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class OdometerTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);
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
	 * Rule 2 as the column sees it: the vehicle caches the newest reading, and the cache is not
	 * what a client edited - so writing it must leave the row's concurrency token where it was,
	 * or every open sheet loses its next save to an odometer entry.
	 */
	public function testTheVehicleCachesTheNewestReadingWithoutMovingItsToken(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->odometer->record(self::OWNER, $vehicle->getUuid(), $this->at(1750000000, 120450));

		$read = $this->vehicles->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame(120450, $read->getOdoValue());
		$this->assertSame($vehicle->getUpdatedAt(), $read->getUpdatedAt());
	}

	/**
	 * Rule 1 through the query that has to hold it up: the readings come back by when they were
	 * read, not by when they were entered, and the flags follow that order.
	 */
	public function testAReadingEnteredLateLandsWhereItHappened(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$backwards = $this->odometer->record(self::OWNER, $uuid, $this->at(1750172800, 119000));
		$this->assertTrue($backwards->getFlagged());

		$this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 118000));

		$chain = [];
		foreach ($this->odometer->list(self::OWNER, $uuid) as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}
		$this->assertSame([120000 => false, 118000 => true, 119000 => false], $chain);
		$this->assertSame(119000, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		// The flag is recomputed state, like `odo_value`: it answers to the chain around the row
		// and not to anyone editing it, so re-deciding it does not re-date the row.
		$this->assertSame($backwards->getUpdatedAt(), $this->reading($uuid, 119000)->getUpdatedAt());
	}

	private function reading(string $vehicleUuid, int $value): OdoReading {
		foreach ($this->odometer->list(self::OWNER, $vehicleUuid) as $reading) {
			if ($reading->getValue() === $value) {
				return $reading;
			}
		}

		$this->fail('no reading of ' . $value . ' on this vehicle');
	}

	/**
	 * Rule 6 through the same query: a distance counts from the newest reading at or before the
	 * moment it was driven, and a reading after that moment is not it.
	 */
	public function testADistanceCountsFromTheNewestReadingBeforeIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750259200, 121000));

		$derived = $this->odometer->record(self::OWNER, $uuid, [
			'read_at' => 1750086400,
			'read_at_off' => -300,
			'distance' => 137,
		]);

		$this->assertSame(120137, $derived->getValue());
		$this->assertSame(OdometerService::DERIVED, $derived->getOrigin());
		$this->assertSame(-300, $derived->getReadAtOff());
		// And the derived row sits between the two, not after them.
		$this->assertSame(
			[120000, 120137, 121000],
			array_map(
				static fn (OdoReading $reading): int => $reading->getValue(),
				$this->odometer->list(self::OWNER, $uuid),
			),
		);
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to
	 * build the whole chain from constructor types alone.
	 */
	public function testTheServiceIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			OdometerService::class,
			(new Application())->getContainer()->get(OdometerService::class),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function at(int $readAt, int $value): array {
		return ['read_at' => $readAt, 'read_at_off' => 120, 'value' => $value];
	}
}
