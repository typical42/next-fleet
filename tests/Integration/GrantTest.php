<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\BackgroundJob\ForgetMemberJob;
use OCA\NextFleet\BackgroundJob\PendingJob;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\Pending;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\Sharable;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who the owner lets use a vehicle, against the real database and real accounts and groups
 * (CONTEXT.md, Vehicle Access).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class GrantTest extends TestCase {
	use RunsQueuedJobs;
	use SharingRules;

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
	/** An account, and a group, deleted while being granted. */
	private const RACED = 'nextfleet-test-grant-raced';
	/** An account on the recipients with no grant and in no group. */
	private const BOOKKEEPER = 'nextfleet-test-grant-bookkeeper';
	/** A group grants name that no backend knows any more, and none told of its going. */
	private const VANISHED = 'nextfleet-test-grant-vanished';

	private GrantService $grants;
	private VehicleService $vehicles;
	private RecipientService $recipients;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([...self::ACCOUNTS, self::BOOKKEEPER] as $uid) {
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
		foreach ([...self::ACCOUNTS, self::BOOKKEEPER, self::RACED] as $uid) {
			$users->get($uid)?->delete();
		}
		foreach ([self::GROUP, self::GONE, self::RACED] as $gid) {
			\OCP\Server::get(IGroupManager::class)->get($gid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
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

	/**
	 * Ever granted outlasts the revoke: the bookings and trips they made stay on it. The list, the
	 * single read and an edit's answer all say so.
	 */
	public function testAVehicleReadsAsEverGrantedOnceAnybodyWasGivenAccess(): void {
		$vehicle = $this->vehicle();
		$this->assertFalse($this->vehicles->find(self::OWNER, $vehicle->getUuid())->jsonSerialize()['ever_granted']);

		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->assertTrue($this->vehicles->find(self::ANNA, $vehicle->getUuid())->jsonSerialize()['ever_granted']);
		$this->grants->revoke(self::OWNER, $vehicle->getUuid(), $list[0]['uuid']);

		$listed = array_values(array_filter(
			$this->vehicles->list(self::OWNER),
			static fn (Vehicle $row): bool => $row->getUuid() === $vehicle->getUuid(),
		));
		$this->assertTrue($listed[0]->jsonSerialize()['ever_granted']);
		$read = $this->vehicles->find(self::OWNER, $vehicle->getUuid());
		$written = $this->vehicles->update(self::OWNER, $vehicle->getUuid(), $read->getUpdatedAt(), ['notes' => 'Granted once']);
		$this->assertTrue($written->jsonSerialize()['ever_granted']);
	}

	/** A retried grant is answered with the list and writes nothing: a revoke since stands. */
	public function testAGrantSentAgainUnderItsClientUuidGrantsNothing(): void {
		$vehicle = $this->vehicle();
		$grant = ['client_uuid' => '0195e2f1-2222-4000-8000-000000000001', 'grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver'];
		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), $grant);
		$this->assertSame([$grant['client_uuid']], array_column($list, 'uuid'));
		$this->grants->revoke(self::OWNER, $vehicle->getUuid(), $grant['client_uuid']);

		try {
			$this->grants->grant(self::OWNER, $vehicle->getUuid(), $grant);
			$this->fail('granted again');
		} catch (AlreadyCreatedException $e) {
			$this->assertSame([], $e->answer);
		}
		$this->assertSame([], $this->grants->list(self::OWNER, $vehicle->getUuid()));
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

	/** The same role again writes nothing, so no sync hands the grant out again. */
	public function testGrantingTheSameRoleAgainLeavesTheGrantAsItWas(): void {
		$vehicle = $this->vehicle();
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		[$first] = \OCP\Server::get(AccessMapper::class)->findByVehicle((int)$vehicle->getId());

		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->grants->change(self::OWNER, $vehicle->getUuid(), $first->getUuid(), ['role' => 'viewer']);

		[$after] = \OCP\Server::get(AccessMapper::class)->findByVehicle((int)$vehicle->getId());
		$this->assertSame([$first->getUuid(), 'viewer', $first->getUpdatedAt()], [$after->getUuid(), $after->getRole(), $after->getUpdatedAt()]);
	}

	/**
	 * What cannot be a grant: nobody the instance knows, no role or one the domain lacks, no
	 * grantee, and a grantee that is neither a user nor a group (a circle or a team).
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

	/**
	 * A group the admin exempts from "members only" is no common ground, and no grantee either, as
	 * core's sharing reads it.
	 */
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
			], function () use ($annas, $grantBen): void {
				$this->assertRefused(self::ANNA, $annas, $grantBen);
				// Nor is the exempt group itself one to grant to, though Anna is in it.
				$this->assertRefused(self::ANNA, $annas, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
			});
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

	/** @param array<string, mixed> $fields */
	private function assertRefused(string $owner, Vehicle $vehicle, array $fields, ?GrantService $through = null): void {
		try {
			($through ?? $this->grants)->grant($owner, $vehicle->getUuid(), $fields);
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

	/**
	 * Only whom the change took the vehicle from comes off: a bookkeeper the owner put on the list
	 * never saw the car, and a revoke, a leave or a deleted group changes nothing for them.
	 */
	public function testARecipientWithoutAGrantOutlivesEveryRevoke(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$groups = \OCP\Server::get(IGroupManager::class);
		$groups->createGroup(self::GONE)?->addUser(\OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN));
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'viewer']);
		$list = $this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		foreach ([self::BOOKKEEPER, self::ANNA, self::BEN] as $uid) {
			$this->recipients->add(self::OWNER, $uuid, $uid);
		}

		$this->grants->revoke(self::OWNER, $uuid, array_column($list, 'uuid', 'grantee')[self::ANNA]);
		$this->assertSame([self::OWNER, self::BOOKKEEPER, self::BEN], $this->recipientIds($vehicle));

		$groups->get(self::GONE)?->delete();
		$this->assertSame([self::OWNER, self::BOOKKEEPER], $this->recipientIds($vehicle));

		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->grants->leave(self::ANNA, $uuid);
		$this->assertSame([self::OWNER, self::BOOKKEEPER], $this->recipientIds($vehicle));
	}

	/** Their own grant goes, the vehicle leaves their overview, and so do its reminders. */
	public function testADirectGranteeLeaves(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->recipients->add(self::OWNER, $uuid, self::ANNA);

		$this->assertSame(['role' => null, 'groups' => [], 'holders' => []], $this->grants->leave(self::ANNA, $uuid));

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

		// The owner here is no account, so it has no name to be listed by.
		$held = ['role' => null, 'groups' => [['grantee' => self::GROUP, 'display_name' => 'The Crew', 'role' => 'viewer']],
			'holders' => [['display_name' => 'The Crew', 'grantee_type' => 'group', 'role' => 'viewer']]];
		$this->assertSame($held, $this->grants->held(self::BEN, $uuid));
		try {
			$this->grants->leave(self::BEN, $uuid);
			$this->fail('Left a group grant');
		} catch (DoesNotExistException) {
		}
		$this->assertSame($before, $this->grants->list(self::OWNER, $uuid));
	}

	/**
	 * A grantee sees who else reads the vehicle - the owner, each account and each group with its
	 * role - by name, never by uid. A grant whose grantee is gone reaches nobody and is not named.
	 */
	public function testAGranteeSeesWhoCanViewTheVehicleByName(): void {
		$users = \OCP\Server::get(IUserManager::class);
		$vehicle = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::ANNA, $uuid, ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->grants->grant(self::ANNA, $uuid, ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);
		$gone = new Access();
		$gone->setVehicleId((int)$vehicle->getId());
		$gone->setGrantee(self::GONE);
		$gone->setGranteeType(Access::USER);
		$gone->setRole('manager');
		$gone->setCreatedBy(self::ANNA);
		\OCP\Server::get(AccessMapper::class)->insert($gone);
		$users->get(self::ANNA)?->setDisplayName('Anna A.');
		$users->get(self::BEN)?->setDisplayName('Ben B.');
		try {
			$this->assertSame([
				['display_name' => 'Anna A.', 'grantee_type' => 'user', 'role' => 'owner'],
				['display_name' => 'Ben B.', 'grantee_type' => 'user', 'role' => 'driver'],
				['display_name' => 'The Crew', 'grantee_type' => 'group', 'role' => 'viewer'],
			], $this->grants->held(self::BEN, $uuid)['holders']);
			$this->assertSame([], $this->grants->held(self::ANNA, $uuid)['holders']);
		} finally {
			$users->get(self::ANNA)?->setDisplayName(self::ANNA);
			$users->get(self::BEN)?->setDisplayName(self::BEN);
		}
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

		$this->assertSame(['role' => null, 'groups' => [['grantee' => self::GROUP, 'display_name' => 'The Crew', 'role' => 'viewer']],
			'holders' => [['display_name' => 'The Crew', 'grantee_type' => 'group', 'role' => 'viewer']]], $held);
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

	/**
	 * A member taken out of a group loses the car it gave them, and its reminders with it. Whoever
	 * still sees it through a grant of their own stays, and so does a bookkeeper.
	 */
	public function testAMemberTakenOutOfAGroupComesOffTheRecipients(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$users = \OCP\Server::get(IUserManager::class);
		$gone = \OCP\Server::get(IGroupManager::class)->createGroup(self::GONE) ?? throw new \RuntimeException('no ' . self::GONE);
		$members = [];
		foreach ([self::ANNA, self::BEN] as $uid) {
			$members[$uid] = $users->get($uid) ?? throw new \RuntimeException('no ' . $uid);
			$gone->addUser($members[$uid]);
		}
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'viewer']);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
		foreach ([self::BOOKKEEPER, self::ANNA, self::BEN] as $uid) {
			$this->recipients->add(self::OWNER, $uuid, $uid);
		}

		foreach ($members as $member) {
			$gone->removeUser($member);
		}
		// Before cron came round: the job still finds the grants they lost.
		$gone->delete();
		self::runQueued(ForgetMemberJob::class);

		$this->assertSame([self::OWNER, self::BOOKKEEPER, self::ANNA], $this->recipientIds($vehicle));
	}

	/**
	 * Taking a member out of a group answers at once: the recipients of every car the group
	 * reaches are checked by a queued job, not inside the group admin's request.
	 */
	public function testAMemberTakenOutOfAGroupComesOffTheRecipientsOnceTheJobRan(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$ben = \OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN);
		$gone = \OCP\Server::get(IGroupManager::class)->createGroup(self::GONE) ?? throw new \RuntimeException('no ' . self::GONE);
		$gone->addUser($ben);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'viewer']);
		$this->recipients->add(self::OWNER, $uuid, self::BEN);

		$gone->removeUser($ben);
		$this->assertSame([self::OWNER, self::BEN], $this->recipientIds($vehicle));

		self::runQueued(ForgetMemberJob::class);
		$this->assertSame([self::OWNER], $this->recipientIds($vehicle));
		$gone->delete();
	}

	/** A group no car is granted to took nothing from a member who left it: cron gets no work. */
	public function testLeavingAGroupNoCarIsGrantedToQueuesNothing(): void {
		$ben = \OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN);
		$gone = \OCP\Server::get(IGroupManager::class)->createGroup(self::GONE) ?? throw new \RuntimeException('no ' . self::GONE);
		$gone->addUser($ben);
		$jobs = \OCP\Server::get(IJobList::class);
		$queued = static function () use ($jobs): int {
			$count = 0;
			foreach ($jobs->getJobsIterator(ForgetMemberJob::class, null, 0) as $_) {
				$count++;
			}

			return $count;
		};
		$before = $queued();

		$gone->removeUser($ben);

		$this->assertSame($before, $queued());
		$gone->delete();
	}

	/**
	 * One car that fails does not keep a former member on the others' lists; the failure is
	 * thrown once all were tried, so the job runs again (ForgetMemberJobTest).
	 */
	public function testACarThatFailsLeavesAFormerMemberForgottenOnTheOthers(): void {
		$first = $this->vehicle();
		$second = $this->vehicles->create(self::OWNER, ['plate' => 'B-GR 5']);
		$ben = \OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN);
		$gone = \OCP\Server::get(IGroupManager::class)->createGroup(self::GONE) ?? throw new \RuntimeException('no ' . self::GONE);
		$gone->addUser($ben);
		foreach ([$first, $second] as $vehicle) {
			$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'viewer']);
			$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::BEN);
		}
		$gone->removeUser($ben);
		$broken = $this->racing(\OCP\Server::get(IUserManager::class), \OCP\Server::get(IGroupManager::class), (int)$first->getId());

		try {
			$broken->forgetMember(self::GONE, self::BEN, \OCP\Server::get(ITimeFactory::class)->getTime());
			$this->fail('The first car did not break');
		} catch (Exception) {
		}

		$this->assertSame([self::OWNER, self::BEN], $this->recipientIds($first));
		$this->assertSame([self::OWNER], $this->recipientIds($second));
		$gone->delete();
	}

	/**
	 * A deleted group's revokes a database error cut short stay pending, and the job finishes them.
	 * Nobody noted the group's members there - nor where a group vanished without the event that
	 * notes them - so every recipient who no longer sees the car comes off, a bookkeeper too.
	 */
	public function testADeletedGroupsRevokesCutShortAreFinishedByTheJob(): void {
		$first = $this->vehicle();
		$second = $this->vehicles->create(self::OWNER, ['plate' => 'B-GR 4']);
		foreach ([$first, $second] as $vehicle) {
			$grant = new Access();
			$grant->setVehicleId((int)$vehicle->getId());
			$grant->setGrantee(self::VANISHED);
			$grant->setGranteeType(Access::GROUP);
			$grant->setRole('viewer');
			$grant->setCreatedBy(self::OWNER);
			\OCP\Server::get(AccessMapper::class)->insert($grant);
			$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'viewer']);
			foreach ([self::BOOKKEEPER, self::ANNA, self::BEN] as $uid) {
				$this->recipients->add(self::OWNER, $vehicle->getUuid(), $uid);
			}
		}
		$broken = $this->racing(\OCP\Server::get(IUserManager::class), \OCP\Server::get(IGroupManager::class), (int)$second->getId());

		try {
			$broken->forgetGroup(self::VANISHED);
			$this->fail('The revoke did not break');
		} catch (Exception) {
		}
		$this->assertSame([self::ANNA], array_column($this->grants->list(self::OWNER, $first->getUuid()), 'grantee'));
		$this->assertSame([self::OWNER, self::ANNA], $this->recipientIds($first));
		$this->assertContains(self::VANISHED, array_column($this->grants->list(self::OWNER, $second->getUuid()), 'grantee'));

		\OCP\Server::get(PendingJob::class)->start(\OCP\Server::get(IJobList::class));

		$this->assertSame([self::ANNA], array_column($this->grants->list(self::OWNER, $second->getUuid()), 'grantee'));
		$this->assertSame([self::OWNER, self::ANNA], $this->recipientIds($second));
	}

	/** A finish that fails again names the group and why, for `occ nextfleet:pending` to print, and keeps the mark. */
	public function testAFinishThatFailsAgainSaysWhichAndWhy(): void {
		$vehicle = $this->vehicle();
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee(self::VANISHED);
		$grant->setGranteeType(Access::GROUP);
		$grant->setRole('viewer');
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
		$broken = $this->racing(\OCP\Server::get(IUserManager::class), \OCP\Server::get(IGroupManager::class), (int)$vehicle->getId());
		try {
			$broken->forgetGroup(self::VANISHED);
			$this->fail('The revoke did not break');
		} catch (Exception) {
		}

		// A mark left by a failed run would be older than this grant, and its finish would skip it.
		try {
			$failed = array_values(array_filter($broken->finish(), static fn (array $failure): bool => $failure['id'] === self::VANISHED));

			$this->assertCount(1, $failed);
			$this->assertSame('the database broke', $failed[0]['error']->getMessage());
			$this->assertContains(self::VANISHED, array_column(\OCP\Server::get(Pending::class)->of(Pending::GROUP), 'id'));
		} finally {
			\OCP\Server::get(Pending::class)->end(Pending::GROUP, self::VANISHED);
		}
	}

	/**
	 * A group made again under the id of one whose revokes were cut short keeps what it was
	 * granted since: only the old group's grants were the leak.
	 */
	public function testAGroupMadeAgainKeepsTheGrantsItGotAfterTheCutShortRevokes(): void {
		$old = $this->vehicle();
		$new = $this->vehicles->create(self::OWNER, ['plate' => 'B-GR 5']);
		$mapper = \OCP\Server::get(AccessMapper::class);
		$grant = new Access();
		$grant->setVehicleId((int)$old->getId());
		$grant->setGrantee(self::VANISHED);
		$grant->setGranteeType(Access::GROUP);
		$grant->setRole('viewer');
		$grant->setCreatedBy(self::OWNER);
		$mapper->insert($grant);
		$broken = $this->racing(\OCP\Server::get(IUserManager::class), \OCP\Server::get(IGroupManager::class), (int)$old->getId());
		try {
			$broken->forgetGroup(self::VANISHED);
			$this->fail('The revoke did not break');
		} catch (Exception) {
		}

		$later = new Access();
		$later->setVehicleId((int)$new->getId());
		$later->setGrantee(self::VANISHED);
		$later->setGranteeType(Access::GROUP);
		$later->setRole('viewer');
		$later->setCreatedBy(self::OWNER);
		$later = $mapper->insert($later);
		// Granted after the mark, which a same-second insert would not show.
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_access')
			->set('created_at', $qb->createNamedParameter($later->getCreatedAt() + 60, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($later->getUpdatedAt() + 60, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($later->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();

		\OCP\Server::get(PendingJob::class)->start(\OCP\Server::get(IJobList::class));

		$this->assertSame([], array_column($this->grants->list(self::OWNER, $old->getUuid()), 'grantee'));
		$this->assertSame([self::VANISHED], array_column($this->grants->list(self::OWNER, $new->getUuid()), 'grantee'));
		$this->assertSame([], \OCP\Server::get(Pending::class)->of(Pending::GROUP));
	}

	/** A vehicle in the trash loses the grants of a group deleted meanwhile, and its restore brings none back. */
	public function testADeletedGroupsGrantsOnAVehicleInTheTrashAreRevoked(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$groups = \OCP\Server::get(IGroupManager::class);
		$ben = \OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN);
		$groups->createGroup(self::GONE)?->addUser($ben);
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'driver']);
		$trashed = $this->vehicles->delete(self::OWNER, $uuid, $this->vehicles->find(self::OWNER, $uuid)->getUpdatedAt());

		$groups->get(self::GONE)?->delete();
		$groups->createGroup(self::GONE)?->addUser($ben);
		$this->vehicles->restore(self::OWNER, $uuid, $trashed->getUpdatedAt());

		$this->assertSame([], $this->grants->list(self::OWNER, $uuid));
		$this->expectException(AccessDeniedException::class);
		$this->vehicles->find(self::BEN, $uuid);
	}

	/**
	 * An account deleted between the grantee check and the commit: its erasure ran before the grant
	 * row existed, so the grant would wait for whoever takes the uid next. It is removed instead.
	 */
	public function testAUserDeletedWhileBeingGrantedHoldsNothing(): void {
		$vehicle = $this->vehicle();
		$real = \OCP\Server::get(IUserManager::class);
		$real->createUser(self::RACED, bin2hex(random_bytes(16)));
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static function (string $uid) use ($real): ?IUser {
			$user = $real->get($uid);
			if ($uid === self::RACED) {
				$user?->delete();
			}

			return $user;
		});
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $real->userExists($uid));
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $real->getDisplayName($uid));

		$this->assertRefused(self::OWNER, $vehicle, ['grantee' => self::RACED, 'grantee_type' => 'user', 'role' => 'driver'], $this->racing($users, \OCP\Server::get(IGroupManager::class)));

		// No revoked row either: an erasure leaves the uid on no row (ADR 0008).
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('fleet_access')->where($qb->expr()->eq('grantee', $qb->createNamedParameter(self::RACED)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}

	/** The same race with a group: its delete revoked every grant it had, and this one was not yet one. */
	public function testAGroupDeletedWhileBeingGrantedHoldsNothing(): void {
		$vehicle = $this->vehicle();
		$real = \OCP\Server::get(IGroupManager::class);
		$real->createGroup(self::RACED)?->addUser(\OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static function (string $gid) use ($real): ?IGroup {
			$group = $real->get($gid);
			if ($gid === self::RACED) {
				$group?->delete();
			}

			return $group;
		});
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $real->groupExists($gid));

		$this->assertRefused(self::OWNER, $vehicle, ['grantee' => self::RACED, 'grantee_type' => 'group', 'role' => 'driver'], $this->racing(\OCP\Server::get(IUserManager::class), $groups));

		$this->assertSame(0, $this->rowsOn($vehicle));
		$this->expectException(AccessDeniedException::class);
		$this->vehicles->find(self::BEN, $vehicle->getUuid());
	}

	/** Grant rows on the vehicle, revoked ones included: a grant that never took effect leaves none. */
	private function rowsOn(Vehicle $vehicle): int {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('fleet_access')->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId())));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * A backend that answers "no such user" once for a live account - LDAP briefly out of reach -
	 * costs the account this grant and nothing else: erasing cannot be undone, so one answer never
	 * triggers it.
	 */
	public function testAUserMissingForOneAnswerLosesOnlyTheNewGrant(): void {
		$vehicle = $this->vehicle();
		$held = $this->vehicles->create(self::OWNER, ['plate' => 'B-GR 3']);
		$this->grants->grant(self::OWNER, $held->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);
		$annas = $this->vehicles->create(self::ANNA, ['plate' => 'B-GR 2']);
		$real = \OCP\Server::get(IUserManager::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $real->get($uid));
		$blips = 1;
		$users->method('userExists')->willReturnCallback(static function (string $uid) use ($real, &$blips): bool {
			return $blips-- > 0 ? false : $real->userExists($uid);
		});
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $real->getDisplayName($uid));

		$this->assertRefused(self::OWNER, $vehicle, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver'], $this->racing($users, \OCP\Server::get(IGroupManager::class)));

		$this->assertSame(0, $this->rowsOn($vehicle));
		$this->assertSame(self::ANNA, $this->vehicles->find(self::ANNA, $annas->getUuid())->getUserId());
		$this->assertSame([self::ANNA], array_column($this->grants->list(self::OWNER, $held->getUuid()), 'grantee'));
	}

	/**
	 * The service as the container builds it, with the accounts it asks about swapped.
	 *
	 * @param ?int $breaking the vehicle whose hold throws
	 */
	private function racing(IUserManager $users, IGroupManager $groups, ?int $breaking = null): GrantService {
		$vehicles = \OCP\Server::get(VehicleMapper::class);
		if ($breaking !== null) {
			$vehicles = new class(\OCP\Server::get(IDBConnection::class), \OCP\Server::get(ITimeFactory::class), \OCP\Server::get(ISecureRandom::class), $breaking) extends VehicleMapper {
				public function __construct(
					IDBConnection $db,
					ITimeFactory $time,
					ISecureRandom $random,
					private int $breaking,
				) {
					parent::__construct($db, $time, $random);
				}

				public function hold(int $vehicleId): void {
					if ($vehicleId === $this->breaking) {
						throw new Exception('the database broke');
					}
					parent::hold($vehicleId);
				}
			};
		}

		return new GrantService(
			\OCP\Server::get(AccessMapper::class),
			\OCP\Server::get(VehicleService::class),
			\OCP\Server::get(VehicleAccess::class),
			$vehicles,
			\OCP\Server::get(ReminderRecipientMapper::class),
			$users,
			$groups,
			\OCP\Server::get(Sharable::class),
			\OCP\Server::get(GrantNotices::class),
			\OCP\Server::get(NotificationService::class),
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(Pending::class),
			\OCP\Server::get(LoggerInterface::class),
		);
	}

	/** The owner holds the car, not a grant on it. */
	public function testTheOwnerHoldsNoGrantToLeave(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertSame(['role' => null, 'groups' => [], 'holders' => []], $this->grants->held(self::OWNER, $uuid));
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
