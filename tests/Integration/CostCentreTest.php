<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\CostCentreService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The owner keeps a list of cost centres: creates, renames, archives and deletes them. Whoever
 * reaches one of the owner's vehicles reads the list, to pick from it.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class CostCentreTest extends TestCase {
	/** Not an account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-cc-owner';
	/** Accounts, since a grantee has to exist on the instance. */
	private const DRIVER = 'nextfleet-test-cc-driver';
	private const STRANGER = 'nextfleet-test-cc-stranger';

	private CostCentreService $centres;

	public static function setUpBeforeClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::DRIVER, self::STRANGER] as $uid) {
			$users->get($uid)?->delete();
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ([self::DRIVER, self::STRANGER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->centres = Server::get(CostCentreService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		$tables = [
			'fleet_trips' => 'created_by',
			'fleet_audit' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_access' => 'created_by',
			'fleet_cost_centres' => 'user_id',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter(
				[self::OWNER, self::DRIVER, self::STRANGER],
				$qb::PARAM_STR_ARRAY,
			)));
			$qb->executeStatement();
		}
	}

	public function testTheOwnerCreatesACostCentreWithACodeAndAName(): void {
		$made = $this->centres->create(self::OWNER, ['code' => ' 4711 ', 'name' => 'Vertrieb ']);

		$this->assertSame(['4711', 'Vertrieb', false, self::OWNER], [$made['code'], $made['name'], $made['archived'], $made['user_id']]);
		$this->assertSame([$made], $this->centres->list(self::OWNER));
	}

	public function testTheCodeIsOptionalAndTheNameIsNot(): void {
		$this->assertNull($this->centres->create(self::OWNER, ['name' => 'Vertrieb'])['code']);

		$this->expectException(\InvalidArgumentException::class);
		$this->centres->create(self::OWNER, ['code' => '4711', 'name' => '  ']);
	}

	public function testANameTheListHoldsIsRefused(): void {
		$this->centres->create(self::OWNER, ['name' => 'Vertrieb']);

		$this->assertRefused('name_taken', fn () => $this->centres->create(self::OWNER, ['name' => 'Vertrieb']));
	}

	public function testACodeTheListHoldsIsRefused(): void {
		$this->centres->create(self::OWNER, ['code' => '4711', 'name' => 'Vertrieb']);

		$this->assertRefused('code_taken', fn () => $this->centres->create(self::OWNER, ['code' => '4711', 'name' => 'Service']));
	}

	/** Each owner's list is their own. */
	public function testTwoOwnersMayUseOneName(): void {
		$this->centres->create(self::OWNER, ['code' => '4711', 'name' => 'Vertrieb']);
		$this->centres->create(self::STRANGER, ['code' => '4711', 'name' => 'Vertrieb']);

		$this->assertCount(1, $this->centres->list(self::OWNER));
	}

	/** A driver picks from the list of the owner whose vehicle they log on. */
	public function testADriverReadsTheListOfEachOwnerWhoseVehicleTheyReach(): void {
		$vehicle = $this->vehicle('NF-CC 1');
		$this->grant($vehicle, self::DRIVER, 'driver');
		$this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$this->centres->create(self::DRIVER, ['name' => 'Eigenes']);

		$read = $this->centres->list(self::DRIVER);

		$this->assertSame([[self::DRIVER, 'Eigenes'], [self::OWNER, 'Vertrieb']], array_map(static fn (array $centre): array => [$centre['user_id'], $centre['name']], $read));
		$this->assertSame([], $this->centres->list(self::STRANGER));
	}

	public function testTheOwnerRenamesAndArchives(): void {
		$made = $this->centres->create(self::OWNER, ['code' => '4711', 'name' => 'Vertrieb']);

		$changed = $this->centres->update(self::OWNER, $made['uuid'], $made['updated_at'], ['code' => '4712', 'name' => 'Vertrieb Nord', 'archived' => true]);

		$this->assertSame(['4712', 'Vertrieb Nord', true], [$changed['code'], $changed['name'], $changed['archived']]);
		$this->assertSame([$changed], $this->centres->list(self::OWNER));
	}

	/** Its own name and code are no clash. */
	public function testAnUpdateThatKeepsTheNameIsNoClash(): void {
		$made = $this->centres->create(self::OWNER, ['code' => '4711', 'name' => 'Vertrieb']);

		$changed = $this->centres->update(self::OWNER, $made['uuid'], $made['updated_at'], ['code' => '4711', 'name' => 'Vertrieb', 'archived' => true]);

		$this->assertTrue($changed['archived']);
	}

	public function testARenameOntoAnotherNameIsRefused(): void {
		$this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$service = $this->centres->create(self::OWNER, ['name' => 'Service']);

		$this->assertRefused('name_taken', fn () => $this->centres->update(self::OWNER, $service['uuid'], $service['updated_at'], ['name' => 'Vertrieb']));
	}

	public function testAStaleUpdateIsRefused(): void {
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);

		$this->expectException(StaleUpdateException::class);
		$this->centres->update(self::OWNER, $made['uuid'], $made['updated_at'] - 1, ['name' => 'Service']);
	}

	public function testOnlyTheOwnerChangesTheList(): void {
		$vehicle = $this->vehicle('NF-CC 1');
		$this->grant($vehicle, self::DRIVER, 'manager');
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);

		$this->expectException(AccessDeniedException::class);
		$this->centres->update(self::DRIVER, $made['uuid'], $made['updated_at'], ['name' => 'Service']);
	}

	public function testAnUnusedCostCentreIsDeleted(): void {
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);

		$gone = $this->centres->delete(self::OWNER, $made['uuid'], $made['updated_at']);

		$this->assertSame($made['uuid'], $gone['uuid']);
		$this->assertSame([], $this->centres->list(self::OWNER));
		// Its name is free again.
		$this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
	}

	public function testACostCentreAnEntryNamesStays(): void {
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$this->trip($this->vehicle('NF-CC 1'), ['cost_centre_uuid' => $made['uuid']]);

		$this->assertRefused('in_use', fn () => $this->centres->delete(self::OWNER, $made['uuid'], $made['updated_at']));
	}

	/** A voided trip comes back with its cost centre. */
	public function testAVoidedTripStillUsesItsCostCentre(): void {
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$vehicle = $this->vehicle('NF-CC 1');
		$trip = $this->trip($vehicle, ['cost_centre_uuid' => $made['uuid']]);
		Server::get(TripService::class)->delete(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertRefused('in_use', fn () => $this->centres->delete(self::OWNER, $made['uuid'], $made['updated_at']));
	}

	public function testAVehicleDefaultUsesItsCostCentre(): void {
		$made = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$this->vehicle('NF-CC 1', ['cost_centre_uuid' => $made['uuid']]);

		$this->assertRefused('in_use', fn () => $this->centres->delete(self::OWNER, $made['uuid'], $made['updated_at']));
	}

	/** @param callable(): mixed $call */
	private function assertRefused(string $reason, callable $call): void {
		try {
			$call();
			$this->fail('not refused');
		} catch (RefusedException $e) {
			$this->assertSame($reason, $e->reason);
		}
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(string $plate, array $fields = []): Vehicle {
		return Server::get(VehicleService::class)->create(self::OWNER, ['plate' => $plate] + $fields);
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => 'user', 'role' => $role]);
	}

	/** @param array<string, mixed> $more */
	private function trip(Vehicle $vehicle, array $more = []): Trip {
		return Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), $more + [
			'started_at' => 1750000000,
			'started_at_off' => 60,
			'ended_at' => 1750003600,
			'ended_at_off' => 60,
			'end_odo' => 1000,
			'category' => Trip::BUSINESS,
		]);
	}
}
