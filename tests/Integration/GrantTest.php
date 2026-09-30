<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Who the owner lets use a vehicle, against the real database and real accounts and groups
 * (CONTEXT.md, Vehicle Access).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class GrantTest extends TestCase {
	/** Not an account: a vehicle's `user_id` is a string column. */
	private const OWNER = 'nextfleet-test-grant-owner';
	/** Accounts, since a grantee has to exist on the instance. */
	private const ANNA = 'nextfleet-test-grant-anna';
	private const BEN = 'nextfleet-test-grant-ben';
	private const ACCOUNTS = [self::ANNA, self::BEN];
	private const GROUP = 'nextfleet-test-grant-crew';

	private GrantService $grants;
	private VehicleService $vehicles;
	private RecipientService $recipients;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
		$groups = \OCP\Server::get(IGroupManager::class);
		$crew = $groups->createGroup(self::GROUP);
		$crew?->setDisplayName('The Crew');
		$crew?->addUser($users->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN));
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->get($uid)?->delete();
		}
		\OCP\Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
	}

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->grants = $container->get(GrantService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->recipients = $container->get(RecipientService::class);
		$this->forgetTestRows();
	}

	/** Before the accounts go: a deleted account's uid is on none of its rows. */
	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, ...self::ACCOUNTS];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'created_by', 'fleet_reminder_recipients' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function testTheOwnerGrantsAUserARoleAndTheListNamesThem(): void {
		$vehicle = $this->vehicle();
		$anna = \OCP\Server::get(IUserManager::class)->get(self::ANNA);
		$anna?->setDisplayName('Anna Adler');

		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertSame([[
			'grantee' => self::ANNA,
			'grantee_type' => 'user',
			'display_name' => 'Anna Adler',
			'role' => 'driver',
		]], $this->withoutUuids($list));
		$this->assertSame($list, $this->grants->list(self::OWNER, $vehicle->getUuid()));
	}

	/** A group's members reach the vehicle through it; the list names the group, not them. */
	public function testAGroupIsGrantedByItsDisplayNameAndItsMembersReachTheVehicle(): void {
		$vehicle = $this->vehicle();

		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);

		$this->assertSame([[
			'grantee' => self::GROUP,
			'grantee_type' => 'group',
			'display_name' => 'The Crew',
			'role' => 'viewer',
		]], $this->withoutUuids($list));
		$this->assertSame(['view'], $this->vehicles->find(self::BEN, $vehicle->getUuid())->getMay());
	}

	/** A second grant to the same grantee is a role change, not a second row beside the first. */
	public function testGrantingAgainChangesTheRole(): void {
		$vehicle = $this->vehicle();
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);

		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => strtoupper(self::ANNA), 'grantee_type' => 'user', 'role' => 'manager']);

		$this->assertSame([[self::ANNA, 'manager']], array_map(static fn (array $grant): array => [$grant['grantee'], $grant['role']], $list));
	}

	/**
	 * What cannot be a grant: nobody the instance knows, the owner, a role the domain lacks, and a
	 * kind of grantee that is neither a user nor a group - a circle or a team included.
	 *
	 * @dataProvider refusedGrants
	 * @param array<string, mixed> $fields
	 */
	public function testWhatCannotBeAGrantIsRefusedAndWritesNothing(array $fields): void {
		$vehicle = $this->vehicle();

		try {
			$this->grants->grant(self::OWNER, $vehicle->getUuid(), $fields);
			$this->fail('The grant went through');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame([], $this->grants->list(self::OWNER, $vehicle->getUuid()));
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function refusedGrants(): iterable {
		yield 'no such account' => [['grantee' => 'nextfleet-test-grant-nobody', 'grantee_type' => 'user', 'role' => 'viewer']];
		yield 'no such group' => [['grantee' => 'nextfleet-test-grant-nogroup', 'grantee_type' => 'group', 'role' => 'viewer']];
		yield 'a user named like the group' => [['grantee' => self::GROUP, 'grantee_type' => 'user', 'role' => 'viewer']];
		yield 'a circle' => [['grantee' => self::GROUP, 'grantee_type' => 'circle', 'role' => 'viewer']];
		yield 'no role' => [['grantee' => self::ANNA, 'grantee_type' => 'user']];
		yield 'a role the domain lacks' => [['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'owner']];
		yield 'no grantee' => [['grantee_type' => 'user', 'role' => 'viewer']];
	}

	/** The owner holds everything already, and a row saying less would read as if it narrowed that. */
	public function testTheOwnerCannotBeGranted(): void {
		$vehicle = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);

		try {
			$this->grants->grant(self::ANNA, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
			$this->fail('The owner was granted their own vehicle');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame([], $this->grants->list(self::ANNA, $vehicle->getUuid()));
	}

	public function testTheOwnerChangesTheRoleOfAGrant(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);

		$list = $this->grants->change(self::OWNER, $vehicle->getUuid(), $grant['uuid'], ['role' => 'driver']);

		$this->assertSame([[$grant['uuid'], 'driver']], array_map(static fn (array $one): array => [$one['uuid'], $one['role']], $list));
		$this->assertSame(['view', 'log'], $this->vehicles->find(self::ANNA, $vehicle->getUuid())->getMay());
	}

	/** A grant is reached through the vehicle the route names, as an Entry is. */
	public function testAGrantOnAnotherVehicleIsNotFound(): void {
		$mine = $this->vehicle();
		$theirs = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 3']);
		[$grant] = $this->grants->grant(self::ANNA, $theirs->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);

		foreach ([
			fn () => $this->grants->change(self::OWNER, $mine->getUuid(), $grant['uuid'], ['role' => 'manager']),
			fn () => $this->grants->revoke(self::OWNER, $mine->getUuid(), $grant['uuid']),
		] as $call) {
			try {
				$call();
				$this->fail('Reached a grant on another vehicle');
			} catch (DoesNotExistException) {
			}
		}
		$this->assertSame([[self::BEN, 'viewer']], array_map(
			static fn (array $one): array => [$one['grantee'], $one['role']],
			$this->grants->list(self::ANNA, $theirs->getUuid()),
		));
	}

	public function testARoleTheDomainLacksIsRefused(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);

		$this->expectException(\InvalidArgumentException::class);
		$this->grants->change(self::OWNER, $vehicle->getUuid(), $grant['uuid'], ['role' => 'owner']);
	}

	public function testRevokingTakesTheVehicleAwayAndItCanBeGrantedAgain(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertSame([], $this->grants->revoke(self::OWNER, $vehicle->getUuid(), $grant['uuid']));
		$this->assertNotContains($vehicle->getUuid(), array_map(static fn (Vehicle $one): string => $one->getUuid(), $this->vehicles->list(self::ANNA)));
		try {
			$this->vehicles->find(self::ANNA, $vehicle->getUuid());
			$this->fail('A revoked grantee still reaches the vehicle');
		} catch (AccessDeniedException) {
		}

		$again = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->assertSame([[self::ANNA, 'viewer']], array_map(static fn (array $one): array => [$one['grantee'], $one['role']], $again));
	}

	/** Granting tells nobody by mail or by reminder: who gets reminders stays the managers' call. */
	public function testGrantingAddsNobodyToTheRecipients(): void {
		$vehicle = $this->vehicle();

		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'manager']);

		$this->assertSame([self::OWNER], $this->recipientIds($vehicle));
	}

	/**
	 * A recipient who no longer sees the vehicle would be told about a car they cannot open. The
	 * one still reaching it through another grant stays on the list.
	 */
	public function testRevokingTakesOffTheRecipientsWhoNoLongerSeeTheVehicle(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);
		$list = $this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
		foreach (self::ACCOUNTS as $uid) {
			$this->recipients->add(self::OWNER, $uuid, $uid);
		}
		$byGrantee = array_column($list, 'uuid', 'grantee');

		$this->grants->revoke(self::OWNER, $uuid, $byGrantee[self::BEN]);
		$this->grants->revoke(self::OWNER, $uuid, $byGrantee[self::ANNA]);
		$this->assertSame([self::OWNER, self::BEN], $this->recipientIds($vehicle));

		$this->grants->revoke(self::OWNER, $uuid, $byGrantee[self::GROUP]);
		$this->assertSame([self::OWNER], $this->recipientIds($vehicle));
	}

	/** @return list<string> */
	private function recipientIds(Vehicle $vehicle): array {
		return array_column($this->recipients->list(self::OWNER, $vehicle->getUuid()), 'user_id');
	}

	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'B-GR 1']);
	}

	/**
	 * @param list<array<string, mixed>> $list
	 * @return list<array<string, mixed>>
	 */
	private function withoutUuids(array $list): array {
		foreach ($list as $i => $grant) {
			$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$grant['uuid']);
			unset($list[$i]['uuid']);
		}

		return $list;
	}
}
