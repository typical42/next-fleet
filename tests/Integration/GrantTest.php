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
use OCP\IConfig;
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
	/** Deleted and made again by one case. */
	private const GONE = 'nextfleet-test-grant-gone';
	/** An account one case makes and deletes. */
	private const EXCLUDED = 'nextfleet-test-grant-excluded';

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
		foreach ([self::GROUP, self::GONE] as $gid) {
			\OCP\Server::get(IGroupManager::class)->get($gid)?->delete();
		}
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
		$this->forgetRowsOf(self::OWNER, ...self::ACCOUNTS);
	}

	private function forgetRowsOf(string ...$people): void {
		$db = \OCP\Server::get(IDBConnection::class);
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

	/**
	 * Whom the admin lets a user share with is whom they may grant: the picker offers no one else
	 * (core's autocomplete), and the server holds the same line. Ben is in the crew, Anna in no
	 * group.
	 */
	public function testUnderGroupMembersOnlyAGranteeSharesAGroupWithTheOwner(): void {
		$annas = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);
		$bens = $this->vehicles->create(self::BEN, ['plate' => 'B-GR 3']);

		$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($annas, $bens): void {
			$this->assertRefused(self::ANNA, $annas, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);
			$this->assertRefused(self::ANNA, $annas, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
			$this->assertSame([self::GROUP], array_column(
				$this->grants->grant(self::BEN, $bens->getUuid(), ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']),
				'grantee',
			));
		});
	}

	/** A group the admin exempts from "members only" is no common ground, as core's sharing reads it. */
	public function testAnExemptGroupIsNoCommonGround(): void {
		$annas = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);
		$groups = \OCP\Server::get(IGroupManager::class);
		$anna = \OCP\Server::get(IUserManager::class)->get(self::ANNA) ?? throw new \RuntimeException('no ' . self::ANNA);
		$groups->get(self::GROUP)?->addUser($anna);
		$grantBen = ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer'];

		try {
			$this->underSharingRules([
				'shareapi_only_share_with_group_members' => 'yes',
				'shareapi_only_share_with_group_members_exclude_group_list' => json_encode([self::GROUP]),
			], fn () => $this->assertRefused(self::ANNA, $annas, $grantBen));
			$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($annas, $grantBen): void {
				$this->assertSame([self::BEN], array_column($this->grants->grant(self::ANNA, $annas->getUuid(), $grantBen), 'grantee'));
			});
		} finally {
			$groups->get(self::GROUP)?->removeUser($anna);
		}
	}

	/** Whoever may not share at all grants nobody either. */
	public function testWithTheShareApiOffNobodyIsGranted(): void {
		$annas = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);

		$this->underSharingRules(['shareapi_enabled' => 'no'], fn () => $this->assertRefused(
			self::ANNA, $annas, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer'],
		));
	}

	/**
	 * An owner in groups excluded from sharing grants nobody. Their own account: core keeps that
	 * answer per account for the rest of the process.
	 */
	public function testAnOwnerExcludedFromSharingGrantsNobody(): void {
		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::EXCLUDED)?->delete();
		$excluded = $users->createUser(self::EXCLUDED, bin2hex(random_bytes(16))) ?: throw new \RuntimeException('no ' . self::EXCLUDED);
		\OCP\Server::get(IGroupManager::class)->get(self::GROUP)?->addUser($excluded);
		try {
			$theirs = $this->vehicles->create(self::EXCLUDED, ['plate' => 'B-GR 4']);
			$this->underSharingRules([
				'shareapi_exclude_groups' => 'yes',
				'shareapi_exclude_groups_list' => json_encode([self::GROUP]),
			], fn () => $this->assertRefused(
				self::EXCLUDED, $theirs, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer'],
			));
		} finally {
			$this->forgetRowsOf(self::EXCLUDED);
			$excluded->delete();
		}
	}

	public function testWithoutGroupSharingNoGroupIsGranted(): void {
		$bens = $this->vehicles->create(self::BEN, ['plate' => 'B-GR 3']);

		$this->underSharingRules(['shareapi_allow_group_sharing' => 'no'], fn () => $this->assertRefused(
			self::BEN, $bens, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer'],
		));
	}

	/**
	 * Runs `$test` under core's sharing settings, then puts back what the instance had: the test
	 * servers are dev servers, and their admin's settings are not ours to reset.
	 *
	 * @param array<string, string> $values
	 */
	private function underSharingRules(array $values, \Closure $test): void {
		$config = \OCP\Server::get(IConfig::class);
		$before = [];
		foreach ($values as $key => $value) {
			$before[$key] = $config->getAppValue('core', $key, "\0unset");
			$config->setAppValue('core', $key, $value);
		}
		try {
			$test();
		} finally {
			foreach ($before as $key => $value) {
				$value === "\0unset" ? $config->deleteAppValue('core', $key) : $config->setAppValue('core', $key, $value);
			}
		}
	}

	/** @param array<string, mixed> $fields */
	private function assertRefused(string $owner, Vehicle $vehicle, array $fields): void {
		try {
			$this->grants->grant($owner, $vehicle->getUuid(), $fields);
			$this->fail('The grant went through');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame([], $this->grants->list($owner, $vehicle->getUuid()));
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

	/** Their own grant goes, the vehicle leaves their overview, and so do its reminders. */
	public function testADirectGranteeLeaves(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->recipients->add(self::OWNER, $uuid, self::ANNA);

		$this->assertSame(['role' => null, 'groups' => []], $this->grants->leave(self::ANNA, $uuid));

		$this->assertSame([self::BEN], array_column($this->grants->list(self::OWNER, $uuid), 'grantee'));
		$this->assertNotContains($uuid, array_map(static fn (Vehicle $one): string => $one->getUuid(), $this->vehicles->list(self::ANNA)));
		$this->assertSame([self::OWNER], $this->recipientIds($vehicle));
	}

	/** Through a group there is nothing of theirs to leave: they are told which group, and it stays. */
	public function testAGroupGranteeIsToldWhichGroupAndCannotLeave(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
		$before = $this->grants->list(self::OWNER, $uuid);

		$held = ['role' => null, 'groups' => [['grantee' => self::GROUP, 'display_name' => 'The Crew', 'role' => 'viewer']]];
		$this->assertSame($held, $this->grants->held(self::BEN, $uuid));
		try {
			$this->grants->leave(self::BEN, $uuid);
			$this->fail('Left a group grant');
		} catch (DoesNotExistException) {
		}
		$this->assertSame($before, $this->grants->list(self::OWNER, $uuid));
	}

	/** Leaving their own grant leaves the group's standing, and with it the vehicle and its reminders. */
	public function testLeavingKeepsWhatAGroupGives(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'manager']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
		$this->recipients->add(self::OWNER, $uuid, self::BEN);
		$this->assertSame('manager', $this->grants->held(self::BEN, $uuid)['role']);

		$held = $this->grants->leave(self::BEN, $uuid);

		$this->assertSame(['role' => null, 'groups' => [['grantee' => self::GROUP, 'display_name' => 'The Crew', 'role' => 'viewer']]], $held);
		$this->assertSame(['view'], $this->vehicles->find(self::BEN, $uuid)->getMay());
		$this->assertSame([self::OWNER, self::BEN], $this->recipientIds($vehicle));
	}

	/**
	 * A deleted group's grants go with it, recipients included: a group made later under the same
	 * id would otherwise inherit the car.
	 */
	public function testADeletedGroupsGrantsAreRevokedAndItsIdReusedReachesNothing(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$groups = \OCP\Server::get(IGroupManager::class);
		$ben = \OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN);
		$groups->createGroup(self::GONE)?->addUser($ben);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'driver']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->recipients->add(self::OWNER, $uuid, self::BEN);

		$groups->get(self::GONE)?->delete();
		$groups->createGroup(self::GONE)?->addUser($ben);

		$this->assertSame([self::ANNA], array_column($this->grants->list(self::OWNER, $uuid), 'grantee'));
		$this->assertSame([self::OWNER], $this->recipientIds($vehicle));
		$this->expectException(AccessDeniedException::class);
		$this->vehicles->find(self::BEN, $uuid);
	}

	/** The owner holds the car, not a grant on it. */
	public function testTheOwnerHoldsNoGrantToLeave(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertSame(['role' => null, 'groups' => []], $this->grants->held(self::OWNER, $uuid));
		$this->expectException(DoesNotExistException::class);
		$this->grants->leave(self::OWNER, $uuid);
	}

	/**
	 * A grantee's overview names whose vehicle it is, on the list and the single read alike; the
	 * owner's own carries no name. An owner with no account reads as the uid the row carries.
	 */
	public function testAVehicleReachedThroughAGrantNamesItsOwner(): void {
		\OCP\Server::get(IUserManager::class)->get(self::ANNA)?->setDisplayName('Anna Adler');
		$annas = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);
		$this->grants->grant(self::ANNA, $annas->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);
		$nobodys = $this->vehicle();
		$this->grants->grant(self::OWNER, $nobodys->getUuid(), ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
		$bens = $this->vehicles->create(self::BEN, ['plate' => 'B-GR 3']);

		$owners = [];
		foreach ($this->vehicles->list(self::BEN) as $one) {
			$owners[$one->getUuid()] = $one->jsonSerialize()['owned_by'];
		}

		$this->assertSame([
			$annas->getUuid() => 'Anna Adler',
			$nobodys->getUuid() => self::OWNER,
			$bens->getUuid() => null,
		], array_intersect_key($owners, [$annas->getUuid() => 1, $nobodys->getUuid() => 1, $bens->getUuid() => 1]));
		$this->assertSame('Anna Adler', $this->vehicles->find(self::BEN, $annas->getUuid())->jsonSerialize()['owned_by']);
		$this->assertNull($this->vehicles->find(self::ANNA, $annas->getUuid())->jsonSerialize()['owned_by']);
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
