<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\TransferCommand;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:transfer`: an admin hands a vehicle to another account before the owner's is
 * deleted, which would close it (ErasureService::erase()).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TransferCommandTest extends TestCase {
	private const OWNER = 'nextfleet-test-transfer-owner';
	private const NEW_OWNER = 'nextfleet-test-transfer-new';
	private const DRIVER = 'nextfleet-test-transfer-driver';
	private const ACCOUNTS = [self::OWNER, self::NEW_OWNER, self::DRIVER];

	private VehicleService $vehicles;
	private GrantService $grants;
	private CommandTester $command;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->command = new CommandTester(\OCP\Server::get(TransferCommand::class));
		$this->forget();

		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Rows first, by vehicle: once an account is deleted its uid is on none of them. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (['fleet_access', 'fleet_reminder_recipients', 'fleet_odo_readings', 'fleet_trips'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_audit')
				->where($qb->expr()->eq('entity', $qb->createNamedParameter(Audit::VEHICLE)))
				->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];

		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/**
	 * The new owner owns it, every other grant stands, and the former owner keeps reading what they
	 * wrote. A grant the new owner held is spent: the owner holds every right already.
	 */
	public function testTheNewOwnerOwnsTheVehicleAndEveryOtherGrantStands(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::DRIVER, 'driver');
		$this->grant($vehicle, self::NEW_OWNER, 'manager');

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$fresh = $this->fresh($vehicle);
		$this->assertSame(self::NEW_OWNER, $fresh->getUserId());
		$this->assertSame([self::DRIVER => 'driver', self::OWNER => 'viewer'], $this->grantsOn($vehicle));
		$access = \OCP\Server::get(VehicleAccess::class);
		$this->assertTrue($access->may(self::NEW_OWNER, VehicleAccess::OWN, $fresh));
		$this->assertFalse($access->may(self::OWNER, VehicleAccess::LOG, $fresh));
		$this->assertTrue($access->may(self::OWNER, VehicleAccess::VIEW, $fresh));
		$this->assertContains(self::NEW_OWNER, $this->recipientsOf($vehicle), 'the owner is where reminders go');
	}

	/** The admin's act is on the vehicle's trail, from whom to whom. */
	public function testTheTransferIsOnTheVehiclesTrail(): void {
		$vehicle = $this->vehicle();

		$this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]);

		$rows = \OCP\Server::get(AuditMapper::class)->findForEntity(Audit::VEHICLE, (int)$vehicle->getId());
		$this->assertCount(1, $rows);
		$this->assertSame(Audit::TRANSFERRED_BY, $rows[0]->getCreatedBy());
		$this->assertSame(['change' => 'transferred', 'fields' => ['user_id' => [self::OWNER, self::NEW_OWNER]]], $rows[0]->getDiffJson());
	}

	/** Told once, not twice: the list keeps the place the new owner had on it. */
	public function testANewOwnerOnTheReminderListAlreadyStaysOnItOnce(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::NEW_OWNER, 'viewer');
		\OCP\Server::get(RecipientService::class)->add(self::OWNER, $vehicle->getUuid(), self::NEW_OWNER);

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$this->assertSame([self::NEW_OWNER], array_values(array_filter($this->recipientsOf($vehicle), static fn (string $uid): bool => $uid === self::NEW_OWNER)));
	}

	/** A vehicle in the trash is refused as one that is not there. */
	public function testAnUnknownVehicleIsRefused(): void {
		$vehicle = $this->vehicle();
		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $this->fresh($vehicle)->getUpdatedAt());

		foreach (['nextfleet-test-transfer-no-such-uuid', $vehicle->getUuid()] as $uuid) {
			$this->assertSame(1, $this->command->execute(['vehicle' => $uuid, 'owner' => self::NEW_OWNER]));
			$this->assertStringContainsString('No vehicle has this uuid, or it is deleted', $this->command->getDisplay());
		}
		$this->assertSame(self::OWNER, $this->fresh($vehicle)->getUserId());
	}

	public function testANewOwnerWhoDoesNotExistIsRefused(): void {
		$vehicle = $this->vehicle();

		$this->assertSame(1, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => 'nextfleet-test-transfer-nobody']));

		$this->assertStringContainsString('No such user', $this->command->getDisplay());
		$this->assertSame(self::OWNER, $this->fresh($vehicle)->getUserId());
	}

	public function testTheOwnerCannotBeHandedTheirOwnVehicle(): void {
		$vehicle = $this->vehicle();

		$this->assertSame(1, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::OWNER]));

		$this->assertSame([], $this->grantsOn($vehicle));
	}

	/** A pool an erasure before 0.3.1 left open still has an owner who is gone; nobody is granted in their name. */
	public function testAFormerOwnerWhoIsGoneIsGrantedNothing(): void {
		$vehicle = $this->vehicle();
		$gone = 'erased:' . str_repeat('g0n3x', 4);
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_vehicles')->set('user_id', $qb->createNamedParameter($gone))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));
		$qb->executeStatement();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$this->assertSame(self::NEW_OWNER, $this->fresh($vehicle)->getUserId());
		$this->assertSame([], $this->grantsOn($vehicle));
	}

	/** The trail names both owners, so erasing the former one renames them there too, and the new owner keeps the car. */
	public function testErasingTheFormerOwnerLeavesTheVehicleOpen(): void {
		$vehicle = $this->vehicle();
		$this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]);

		\OCP\Server::get(IUserManager::class)->get(self::OWNER)?->delete();

		$fresh = $this->fresh($vehicle);
		$this->assertNull($fresh->getDeletedAt());
		$this->assertSame(self::NEW_OWNER, $fresh->getUserId());
		$rows = \OCP\Server::get(AuditMapper::class)->findForEntity(Audit::VEHICLE, (int)$vehicle->getId());
		[$was, $now] = $rows[0]->getDiffJson()['fields']['user_id'];
		$this->assertStringStartsWith('erased:', $was);
		$this->assertSame(self::NEW_OWNER, $now);
		$this->assertSame([$was => 'viewer'], $this->grantsOn($vehicle), 'one erasure, one pseudonym');
	}

	private function vehicle(): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-TR 1']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => Access::USER, 'role' => $role]);
	}

	private function fresh(Vehicle $vehicle): Vehicle {
		return \OCP\Server::get(VehicleMapper::class)->findAnyByUuid($vehicle->getUuid());
	}

	/** @return array<string, string> the live grants' roles, by grantee */
	private function grantsOn(Vehicle $vehicle): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('grantee', 'role')->from('fleet_access')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('grantee');
		$roles = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$roles[(string)$row['grantee']] = (string)$row['role'];
		}

		return $roles;
	}

	/** @return list<string> */
	private function recipientsOf(Vehicle $vehicle): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('user_id')->from('fleet_reminder_recipients')
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));

		return array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}
}
