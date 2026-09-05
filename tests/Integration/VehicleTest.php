<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\VehicleController;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle against the real database: what the columns give back, what the concurrency check
 * does when a second tab got there first, and what a soft delete leaves behind.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class VehicleTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';

	private VehicleService $service;

	protected function setUp(): void {
		$this->service = (new Application())->getContainer()->get(VehicleService::class);
		$this->forgetTestVehicles();
	}

	protected function tearDown(): void {
		$this->forgetTestVehicles();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestVehicles(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter([self::OWNER, self::STRANGER], $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * Every kind of column the table has, written and read back: a JSON set, a calendar day, a
	 * boolean nobody touched, integers, and the identity the server chose.
	 */
	public function testAVehicleComesBackAsItWasWritten(): void {
		$written = $this->service->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'manufacturer' => 'Volkswagen',
			'model' => 'Caddy',
			'vehicle_type' => 'van',
			'engine' => 'hybrid',
			'energy_types' => ['petrol', 'electric'],
			'tank_ml' => '55000',
			'battery_wh' => '13600',
			'first_reg' => '2019-03-07',
			'purchase_price' => '1850000',
			'currency' => 'EUR',
			'odo_unit' => 'km',
			'notes' => "two rows of seats\nand a dent",
		]);

		$read = $this->service->find($written->getUuid());

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$read->getUuid(),
		);
		$this->assertSame(self::OWNER, $read->getUserId());
		$this->assertSame(self::OWNER, $read->getCreatedBy());
		$this->assertSame('B-XY 123', $read->getPlate());
		$this->assertSame('van', $read->getVehicleType());
		$this->assertSame(['petrol', 'electric'], $read->getEnergyTypes());
		$this->assertSame(55000, $read->getTankMl());
		$this->assertSame(1850000, $read->getPurchasePrice());
		$this->assertSame('2019-03-07', $read->getFirstReg()?->format('Y-m-d'));
		$this->assertSame("two rows of seats\nand a dent", $read->getNotes());
		// Nobody set it, and the column's default is what a flag nobody touched means.
		$this->assertFalse((bool)$read->getLogbookMode());
		$this->assertNull($read->getOdoValue());
		$this->assertSame($read->getCreatedAt(), $read->getUpdatedAt());
	}

	/** The overview is one user's fleet, and a row belonging to someone else is not in it. */
	public function testTheListHoldsOneUsersVehicles(): void {
		$mine = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->service->create(self::STRANGER, ['plate' => 'HH-ZZ 9']);

		$this->assertSame(
			[$mine->getUuid()],
			array_map(static fn (Vehicle $v): string => $v->getUuid(), $this->service->list(self::OWNER)),
		);
	}

	/**
	 * The second tab read the row before the first one saved it, so its write matches nothing
	 * and is refused rather than quietly winning (docs/architecture.md#concurrency).
	 */
	public function testAWriteThatLostTheRaceIsRefused(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$stale = $vehicle->getUpdatedAt();

		$fresh = $this->service->update($vehicle->getUuid(), $stale, ['plate' => 'B-ZZ 9']);
		$this->assertSame('B-ZZ 9', $this->service->find($vehicle->getUuid())->getPlate());
		$this->assertGreaterThan($stale, $fresh->getUpdatedAt());

		$this->expectException(StaleUpdateException::class);
		$this->service->update($vehicle->getUuid(), $stale, ['plate' => 'B-AA 1']);
	}

	/** A deleted vehicle is out of reach of a read, and the row is still there for the trash. */
	public function testADeletedVehicleIsOutOfReachButNotGone(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);

		$deleted = $this->service->delete($vehicle->getUuid(), $vehicle->getUpdatedAt());

		$this->assertNotNull($deleted->getDeletedAt());
		$this->assertSame([], $this->service->list(self::OWNER));

		$this->expectException(DoesNotExistException::class);
		$this->service->find($vehicle->getUuid());
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to
	 * build the whole chain from constructor types alone - and a route that cannot be built is
	 * a 500 no unit test sees.
	 */
	public function testTheControllerIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			VehicleController::class,
			(new Application())->getContainer()->get(VehicleController::class),
		);
	}
}
