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
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\CostCentreService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\PartnerService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\TripService;
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
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('fleet_trips')
				->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$trips = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			if ($trips !== []) {
				$qb = $db->getQueryBuilder();
				$qb->delete('fleet_audit')
					->where($qb->expr()->eq('entity', $qb->createNamedParameter(Audit::TRIP)))
					->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($trips, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			foreach (['fleet_access', 'fleet_reminder_recipients', 'fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_expenses'] as $table) {
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
		foreach (['fleet_partners', 'fleet_cost_centres'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('user_id', $qb->createNamedParameter(self::ACCOUNTS, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}

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

	/** A vehicle that followed its owner's default keeps the answer it had: the new owner's default may differ. */
	public function testATransferNeverChangesWhetherBookingsWaitForAnAnswer(): void {
		$preferences = \OCP\Server::get(PreferencesService::class);
		$preferences->write(self::OWNER, ['booking_approval' => true]);
		$asked = $this->vehicle();
		$this->command->execute(['vehicle' => $asked->getUuid(), 'owner' => self::NEW_OWNER]);
		$this->assertSame([true, Vehicle::APPROVAL_ALWAYS], [$this->fresh($asked)->needsApproval(), $this->fresh($asked)->approvalRule()]);

		$preferences->write(self::OWNER, ['booking_approval' => false]);
		$preferences->write(self::NEW_OWNER, ['booking_approval' => true]);
		$free = $this->vehicle();
		$this->command->execute(['vehicle' => $free->getUuid(), 'owner' => self::NEW_OWNER]);
		$this->assertSame([false, Vehicle::APPROVAL_NEVER], [$this->fresh($free)->needsApproval(), $this->fresh($free)->approvalRule()]);
	}

	/**
	 * The partners the vehicle's trips name go into the new owner's list: a record of the same name
	 * there is taken as it is, another is copied with its address and note. Every trip, voided ones
	 * too, keeps its text and links the new owner's record; the move is on each trip's trail. The
	 * former owner's list stays as it was.
	 */
	public function testTheVehiclesPartnersFollowItIntoTheNewOwnersList(): void {
		$theirs = $this->vehicle(self::NEW_OWNER);
		$this->trip(self::NEW_OWNER, $theirs, 1750000000, 'Weber');
		$this->describe(self::NEW_OWNER, 'Weber', 'Neue Adresse', null);
		$vehicle = $this->vehicle(self::OWNER, ['jurisdiction' => 'de']);
		$vehicle = $this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['logbook_mode' => true]);
		$mueller = $this->trip(self::OWNER, $vehicle, 1750000000, 'Müller');
		$weber = $this->trip(self::OWNER, $vehicle, 1750100000, 'Weber');
		$voided = $this->trip(self::OWNER, $vehicle, 1750200000, 'Müller');
		\OCP\Server::get(TripService::class)->delete(self::OWNER, $vehicle->getUuid(), $voided->getUuid(), $voided->getUpdatedAt());
		$this->trip(self::OWNER, $this->vehicle(self::OWNER), 1750000000, 'Schulz');
		$this->describe(self::OWNER, 'Müller', "Hafenstraße 1\n20457 Hamburg", 'Zentrale');
		$this->describe(self::OWNER, 'Weber', 'Alte Adresse', 'Alt');
		$was = $this->listOf(self::OWNER);
		$oldMueller = (string)$this->stored($mueller)->getPartnerUuid();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$list = $this->listOf(self::NEW_OWNER);
		$this->assertSame(['Müller', 'Weber'], array_keys($list));
		$this->assertSame(["Hafenstraße 1\n20457 Hamburg", 'Zentrale'], [$list['Müller']['address'], $list['Müller']['note']]);
		$this->assertSame(['Neue Adresse', null], [$list['Weber']['address'], $list['Weber']['note']]);
		foreach ([[$mueller, 'Müller'], [$voided, 'Müller'], [$weber, 'Weber']] as [$trip, $name]) {
			$stored = $this->stored($trip);
			$this->assertSame($name, $stored->getPartner());
			$this->assertSame($list[$name]['uuid'], $stored->getPartnerUuid(), $name);
		}
		$this->assertSame($was, $this->listOf(self::OWNER));

		$rows = \OCP\Server::get(AuditMapper::class)->findForEntity(Audit::TRIP, (int)$mueller->getId());
		$last = end($rows);
		$this->assertInstanceOf(Audit::class, $last);
		$this->assertSame(Audit::TRANSFERRED_BY, $last->getCreatedBy());
		$this->assertSame(['partner_uuid' => [$oldMueller, $list['Müller']['uuid']]], $last->getDiffJson()['fields']);
		$this->assertFalse($last->getDiffJson()['late']);
	}

	/** A partner the former owner deleted stays deleted: the trip names no record, and none is made. */
	public function testAPartnerTheFormerOwnerDeletedIsNotCopied(): void {
		$vehicle = $this->vehicle();
		$trip = $this->trip(self::OWNER, $vehicle, 1750000000, 'Erika Mustermann');
		$uuid = (string)$this->stored($trip)->getPartnerUuid();
		$partners = \OCP\Server::get(PartnerService::class);
		$partners->delete(self::OWNER, $uuid, \OCP\Server::get(PartnerMapper::class)->findByUuid($uuid)->getUpdatedAt());

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$this->assertSame([], $this->listOf(self::NEW_OWNER));
		$stored = $this->stored($trip);
		$this->assertNull($stored->getPartnerUuid());
		$this->assertSame('Erika Mustermann', $stored->getPartner());
	}

	/**
	 * The cost centres the vehicle's entries and its default name go into the new owner's list: a
	 * record there with the same code, else the same name, is taken; another is copied. A trip's
	 * move is on its trail. The former owner's list stays as it was.
	 */
	public function testTheVehiclesCostCentresFollowItIntoTheNewOwnersList(): void {
		$centres = \OCP\Server::get(CostCentreService::class);
		$sales = $centres->create(self::NEW_OWNER, ['code' => '4711', 'name' => 'Sales']);
		$service = $centres->create(self::NEW_OWNER, ['name' => 'Service']);
		$vertrieb = $centres->create(self::OWNER, ['code' => '4711', 'name' => 'Vertrieb']);
		$ownService = $centres->create(self::OWNER, ['name' => 'Service']);
		$workshop = $centres->create(self::OWNER, ['code' => '9', 'name' => 'Werkstatt']);
		$vehicle = $this->vehicle(self::OWNER, ['logbook_mode' => true, 'cost_centre_uuid' => $workshop['uuid']]);
		$centres->update(self::OWNER, $workshop['uuid'], $workshop['updated_at'], ['code' => '9', 'name' => 'Werkstatt', 'archived' => true]);
		$trip = \OCP\Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), ['started_at' => 1750000000, 'started_at_off' => 60, 'ended_at' => 1750003600, 'ended_at_off' => 60, 'end_odo' => 50000, 'category' => Trip::BUSINESS, 'cost_centre_uuid' => $vertrieb['uuid']]);
		$expense = \OCP\Server::get(ExpenseService::class)->record(self::OWNER, $vehicle->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 60, 'amount' => 500, 'cost_centre_uuid' => $ownService['uuid']]);
		// The former owner reads the new owner's list too, through the viewer grant they keep.
		$own = static fn (array $list): array => array_values(array_filter($list, static fn (array $centre): bool => $centre['user_id'] === self::OWNER));
		$was = $own($centres->list(self::OWNER));

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), 'owner' => self::NEW_OWNER]), $this->command->getDisplay());

		$list = [];
		foreach ($centres->list(self::NEW_OWNER) as $centre) {
			$this->assertSame(self::NEW_OWNER, $centre['user_id']);
			$list[$centre['name']] = $centre;
		}
		$this->assertSame(['Sales', 'Service', 'Werkstatt'], array_keys($list));
		$this->assertSame(['9', true], [$list['Werkstatt']['code'], $list['Werkstatt']['archived']]);
		$this->assertSame($sales['uuid'], $this->stored($trip)->getCostCentreUuid());
		$this->assertSame($service['uuid'], \OCP\Server::get(ExpenseMapper::class)->findByUuid($expense['uuid'])->getCostCentreUuid());
		$this->assertSame($list['Werkstatt']['uuid'], $this->fresh($vehicle)->getCostCentreUuid());
		$this->assertSame($was, $own($centres->list(self::OWNER)));
		$rows = \OCP\Server::get(AuditMapper::class)->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertSame(['cost_centre_uuid' => [$vertrieb['uuid'], $sales['uuid']]], end($rows)->getDiffJson()['fields']);
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(string $owner = self::OWNER, array $fields = []): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => 'NF-TR ' . (count($this->vehicleIds) + 1)] + $fields);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function trip(string $owner, Vehicle $vehicle, int $startedAt, string $partner): Trip {
		return \OCP\Server::get(TripService::class)->record($owner, $vehicle->getUuid(), [
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 60,
			// Each later trip reads a higher counter, as the chain wants.
			'end_odo' => intdiv($startedAt - 1700000000, 1000),
			'category' => Trip::BUSINESS,
			'partner' => $partner,
		]);
	}

	private function stored(Trip $trip): Trip {
		return \OCP\Server::get(TripMapper::class)->findAnyByUuid($trip->getUuid());
	}

	private function describe(string $owner, string $name, ?string $address, ?string $note): void {
		$record = \OCP\Server::get(PartnerMapper::class)->findByName($owner, $name);
		$this->assertNotNull($record);
		\OCP\Server::get(PartnerService::class)->update($owner, $record->getUuid(), $record->getUpdatedAt(), ['name' => $name, 'address' => $address, 'note' => $note]);
	}

	/** @return array<string, array<string, mixed>> the live list, by name */
	private function listOf(string $owner): array {
		$list = [];
		foreach (\OCP\Server::get(PartnerService::class)->list($owner) as $partner) {
			$list[$partner['name']] = $partner;
		}

		return $list;
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
