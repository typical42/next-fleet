<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a Nextcloud account pseudonymises its rows and deletes none
 * (docs/adr/0008-erasing-a-driver-pseudonymises.md). The account is real and is deleted the way
 * an admin deletes it, so the listener is reached through the server's own event.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ErasureTest extends TestCase {
	private const OWNER = 'nextfleet-test-erase-owner';
	private const DRIVER = 'nextfleet-test-erase-driver';

	/** Every column that names an account, by table. */
	private const PEOPLE_COLUMNS = [
		'fleet_vehicles' => ['user_id', 'created_by'],
		'fleet_odo_readings' => ['created_by'],
		'fleet_trips' => ['created_by'],
		'fleet_energy' => ['created_by'],
		'fleet_maintenance' => ['created_by'],
		'fleet_expenses' => ['created_by'],
		'fleet_reminders' => ['created_by'],
		'fleet_reminder_receipts' => ['user_id', 'created_by'],
		'fleet_reminder_recipients' => ['user_id', 'created_by'],
		'fleet_documents' => ['created_by'],
		'fleet_audit' => ['created_by'],
		'fleet_access' => ['grantee', 'created_by'],
	];

	private VehicleService $vehicles;
	private TripService $trips;
	private ExpenseService $expenses;
	private RecipientService $recipients;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->vehicles = $container->get(VehicleService::class);
		$this->trips = $container->get(TripService::class);
		$this->expenses = $container->get(ExpenseService::class);
		$this->recipients = $container->get(RecipientService::class);
		$this->forget();

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/**
	 * The accounts and the rows this suite invents, gone for real. Rows go first and by vehicle:
	 * once an account is deleted its uid is on none of them.
	 */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (array_keys(self::PEOPLE_COLUMNS) as $table) {
			if ($this->vehicleIds === [] || in_array($table, ['fleet_vehicles', 'fleet_audit', 'fleet_reminder_receipts'], true)) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		if ($this->vehicleIds !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/** Done when: deleting a Nextcloud user pseudonymises their rows and deletes none. */
	public function testADeletedDriversRowsStayUnderOnePseudonym(): void {
		$shared = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->grant($shared, self::DRIVER, 'manager');
		$trip = $this->trip(self::DRIVER, $shared);
		$expense = $this->expenses->record(self::DRIVER, $shared->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400]);
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		$before = $this->rowCounts();

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		// Their own vehicle listed them as its recipient, and that entry is the one that goes.
		$before['fleet_reminder_recipients']--;
		$this->assertSame($before, $this->rowCounts(), 'a row was deleted');
		$this->assertSame([], $this->rowsNaming(self::DRIVER));
		$pseudonyms = array_unique([
			$this->column('fleet_trips', 'created_by', $trip->getId()),
			$this->column('fleet_expenses', 'created_by', $this->idOf('fleet_expenses', (string)$expense['uuid'])),
			$this->column('fleet_vehicles', 'user_id', $own->getId()),
			$this->column('fleet_vehicles', 'created_by', $own->getId()),
			$this->granteeOn($shared),
		]);
		$this->assertCount(1, $pseudonyms, 'one erasure, one pseudonym');
		$this->assertNotSame('', $pseudonyms[0]);
		$this->assertStringNotContainsString(self::DRIVER, $pseudonyms[0]);
		$this->assertSame(self::OWNER, $this->column('fleet_vehicles', 'user_id', $shared->getId()));
	}

	/** A deleted account receives nothing, so it leaves every reminder list. */
	public function testADeletedAccountLeavesTheRecipientLists(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::DRIVER);

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		$this->assertSame([self::OWNER], array_column($this->recipients->list(self::OWNER, $vehicle->getUuid()), 'user_id'));
	}

	/** Nextcloud lets a uid be taken again; whoever takes it inherits nothing of the old account. */
	public function testAnAccountRecreatedUnderTheSameUidReachesNothing(): void {
		$shared = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->grant($shared, self::DRIVER, 'manager');
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');

		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::DRIVER)?->delete();
		$users->createUser(self::DRIVER, bin2hex(random_bytes(16)));

		$access = \OCP\Server::get(VehicleAccess::class);
		foreach ([$shared, $own] as $vehicle) {
			$fresh = \OCP\Server::get(VehicleMapper::class)->findAnyByUuid($vehicle->getUuid());
			$this->assertFalse($access->may(self::DRIVER, VehicleAccess::VIEW, $fresh));
		}
		$this->assertSame([], $access->reachableVehicleIds(self::DRIVER));
	}

	private function vehicle(string $owner, string $plate): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => $plate]);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function trip(string $author, Vehicle $vehicle): Trip {
		return $this->trips->record($author, $vehicle->getUuid(), [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 12000,
			'category' => Trip::BUSINESS,
		]);
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/** @return array<string, int> rows per table, whoever wrote them */
	private function rowCounts(): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$counts = [];
		foreach (array_keys(self::PEOPLE_COLUMNS) as $table) {
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'n'))->from($table);
			$counts[$table] = (int)$qb->executeQuery()->fetchOne();
		}

		return $counts;
	}

	/** @return list<string> `table.column` wherever the account is still named */
	private function rowsNaming(string $uid): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$found = [];
		foreach (self::PEOPLE_COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				$qb = $db->getQueryBuilder();
				$qb->select($qb->func()->count('*', 'n'))->from($table)
					->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
				if ((int)$qb->executeQuery()->fetchOne() > 0) {
					$found[] = $table . '.' . $column;
				}
			}
		}

		return $found;
	}

	private function column(string $table, string $column, int|string|null $id): string {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select($column)->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, $qb::PARAM_INT)));

		return (string)$qb->executeQuery()->fetchOne();
	}

	private function idOf(string $table, string $uuid): int {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	private function granteeOn(Vehicle $vehicle): string {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('grantee')->from('fleet_access')->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));

		return (string)$qb->executeQuery()->fetchOne();
	}
}
