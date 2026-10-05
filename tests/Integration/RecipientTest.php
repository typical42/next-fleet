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
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Repair\StrangerRecipients;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\Sharable;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Who a vehicle's reminders go to, against the real database (docs/architecture.md#reminder-engine).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class RecipientTest extends TestCase {
	use Accounts;
	use SharingRules;

	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const MANAGER = 'nextfleet-test-dave';
	private const DRIVER = 'nextfleet-test-erin';
	/** A real account, which a recipient added through the sheet has to be. */
	private const RECIPIENT = 'nextfleet-test-recipient';
	/** An account in a group with RECIPIENT, and one in no group. */
	private const MATE = 'nextfleet-test-recipient-mate';
	private const LONER = 'nextfleet-test-recipient-loner';
	private const ACCOUNTS = [self::RECIPIENT, self::MATE, self::LONER];
	private const GROUP = 'nextfleet-test-recipient-crew';
	/** An account deleted while being added. */
	private const RACED = 'nextfleet-test-recipient-raced';

	private RecipientService $recipients;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::GROUP);
		foreach ([self::RECIPIENT, self::MATE] as $uid) {
			$crew?->addUser($users->get($uid) ?? throw new \RuntimeException('no ' . $uid));
		}
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		self::deleteAccounts([...self::ACCOUNTS, self::RACED]);
		\OCP\Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
	}

	protected function setUp(): void {
		$this->recipients = \OCP\Server::get(RecipientService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::DRIVER, ...self::ACCOUNTS];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_reminder_recipients' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function testANewVehicleStartsWithItsOwnerAsTheOneRecipient(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->assertSame([self::OWNER], $this->userIds($vehicle));
	}

	/** A manager edits the list as the owner does, and the owner is not a fixed member of it. */
	public function testAManagerAddsSomebodyAndTakesTheOwnerOff(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant($vehicle, self::MANAGER, 'manager');

		$added = $this->recipients->add(self::MANAGER, $vehicle->getUuid(), self::RECIPIENT);
		$left = $this->recipients->remove(self::MANAGER, $vehicle->getUuid(), self::OWNER);

		$this->assertSame([self::OWNER, self::RECIPIENT], array_column($added, 'user_id'));
		$this->assertSame([self::RECIPIENT], array_column($left, 'user_id'));
		$this->assertSame([self::RECIPIENT], $this->userIds($vehicle));
	}

	/**
	 * Removing is for good, so the same person can be added again: the unique index would refuse a
	 * second row beside a soft-deleted one.
	 */
	public function testSomebodyRemovedCanBeAddedAgainAndAddingTwiceIsOnce(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->remove(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);

		$this->assertSame([self::OWNER, self::RECIPIENT], $this->userIds($vehicle));
	}

	/** The user backend finds an account in any case, so the list must hold its one spelling. */
	public function testAnAccountIsStoredAsTheInstanceSpellsIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), strtoupper(self::RECIPIENT));

		$this->assertSame([self::OWNER, self::RECIPIENT], $this->userIds($vehicle));
	}

	/** A name the instance does not know would be a recipient nothing can ever reach. */
	public function testSomebodyWithoutAnAccountIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), 'nextfleet-test-nobody');
	}

	/**
	 * An account deleted between the check and the write: its erasure took it off every list
	 * before this row existed, so the row would wait for whoever takes the uid next. Refused.
	 */
	public function testAnAccountDeletedWhileBeingAddedIsNotListed(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
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
		$racing = new RecipientService(
			\OCP\Server::get(ReminderRecipientMapper::class),
			$this->vehicles,
			\OCP\Server::get(VehicleMapper::class),
			$users,
			\OCP\Server::get(Sharable::class),
			\OCP\Server::get(VehicleAccess::class),
			\OCP\Server::get(NotificationService::class),
			\OCP\Server::get(IDBConnection::class),
		);

		try {
			$racing->add(self::OWNER, $vehicle->getUuid(), self::RACED);
			$this->fail('A deleted account was listed');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame([self::OWNER], $this->userIds($vehicle));
	}

	/**
	 * A recipient is told the plate, so the list follows whom the admin lets the caller share with,
	 * as a grant does. Somebody out of reach is refused in the words of somebody missing: under
	 * "members only", whether they exist is not the caller's to learn.
	 */
	public function testUnderGroupMembersOnlyARecipientSharesAGroupWithTheCaller(): void {
		$mates = $this->vehicles->create(self::MATE, ['plate' => 'B-XY 123']);
		$owners = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);

		$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($mates, $owners): void {
			$this->assertSame(
				[self::MATE, self::RECIPIENT],
				array_column($this->recipients->add(self::MATE, $mates->getUuid(), self::RECIPIENT), 'user_id'),
			);
			$this->assertSame($this->refusal(self::OWNER, $owners, 'nextfleet-test-nobody'), $this->refusal(self::OWNER, $owners, self::RECIPIENT));
		});
		$this->assertSame([self::OWNER], $this->userIds($owners));
	}

	/**
	 * Whoever sees the vehicle already knows its plate, so putting them back on the list tells
	 * nothing and mails nothing new: the caller themselves, and a grantee outside the caller's groups.
	 */
	public function testWhoeverSeesTheVehicleIsAddedWhateverTheSharingRules(): void {
		$theirs = $this->vehicles->create(self::LONER, ['plate' => 'B-XY 123']);
		$this->recipients->remove(self::LONER, $theirs->getUuid(), self::LONER);
		$mates = $this->vehicles->create(self::MATE, ['plate' => 'B-XY 124']);
		$this->grant($mates, self::LONER, 'driver');

		$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($theirs, $mates): void {
			$this->assertSame(
				[self::LONER],
				array_column($this->recipients->add(self::LONER, $theirs->getUuid(), self::LONER), 'user_id'),
			);
			$this->assertSame(
				[self::MATE, self::LONER],
				array_column($this->recipients->add(self::MATE, $mates->getUuid(), self::LONER), 'user_id'),
			);
		});
	}

	/** Whoever may not share at all adds nobody else either. */
	public function testWithTheShareApiOffNobodyElseIsAdded(): void {
		$mates = $this->vehicles->create(self::MATE, ['plate' => 'B-XY 123']);

		$this->underSharingRules(['shareapi_enabled' => 'no'], fn () => $this->refusal(self::MATE, $mates, self::RECIPIENT));
		$this->assertSame([self::MATE], $this->userIds($mates, self::MATE));
	}

	/** The message adding `$recipient` is refused with. */
	private function refusal(string $userId, Vehicle $vehicle, string $recipient): string {
		try {
			$this->recipients->add($userId, $vehicle->getUuid(), $recipient);
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->fail($recipient . ' was added');
	}

	/** Drivers and viewers neither read nor write the list (docs/architecture.md#reminder-engine). */
	public function testADriverNeitherReadsNorWritesTheList(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant($vehicle, self::DRIVER, 'driver');

		foreach ([
			fn () => $this->recipients->list(self::DRIVER, $vehicle->getUuid()),
			fn () => $this->recipients->add(self::DRIVER, $vehicle->getUuid(), self::RECIPIENT),
			fn () => $this->recipients->remove(self::DRIVER, $vehicle->getUuid(), self::OWNER),
		] as $call) {
			try {
				$call();
				$this->fail('A driver reached the recipients list');
			} catch (AccessDeniedException) {
			}
		}
		$this->assertSame([self::OWNER], $this->userIds($vehicle));
	}

	/**
	 * Before 0.3.1 the list took any account, so the plate went to people the owner may not share
	 * with. The upgrade takes off whoever neither the owner nor whoever added them may share with,
	 * nor sees the vehicle - add() asks the same of the caller. Once 0.3.1 is installed it no
	 * longer runs, so a sharing rule tightened later drops nobody.
	 */
	public function testTheUpgradeDropsRecipientsTheOwnerCouldNotHaveAdded(): void {
		$mates = $this->vehicles->create(self::MATE, ['plate' => 'B-XY 123']);
		$granted = $this->vehicles->create(self::MATE, ['plate' => 'B-XY 124']);
		$this->grant($granted, self::LONER, 'driver');
		foreach ([[$mates, self::RECIPIENT], [$mates, self::LONER], [$granted, self::LONER]] as [$vehicle, $uid]) {
			$this->listedTheOldWay($vehicle, $uid);
		}
		// The owner shares no group with the recipient; the manager who listed them does.
		$managed = $this->vehicles->create(self::LONER, ['plate' => 'B-XY 125']);
		$this->grant($managed, self::MATE, 'manager');
		$this->listedTheOldWay($managed, self::RECIPIENT, self::MATE);

		$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($mates, $granted): void {
			$this->asInstalled('0.3.1', fn () => \OCP\Server::get(StrangerRecipients::class)->run($this->createMock(IOutput::class)));
			$this->assertSame([self::MATE, self::RECIPIENT, self::LONER], $this->userIds($mates, self::MATE), 'installed already: nothing runs');

			$output = $this->createMock(IOutput::class);
			$output->expects($this->once())->method('info')->with($this->stringContains('1 '));
			$this->asInstalled('0.3.0', fn () => \OCP\Server::get(StrangerRecipients::class)->run($output));
		});

		$this->assertSame([self::MATE, self::RECIPIENT], $this->userIds($mates, self::MATE), 'a group mate stays, a stranger goes');
		$this->assertSame([self::MATE, self::LONER], $this->userIds($granted, self::MATE), 'whoever sees the vehicle stays');
		$this->assertSame([self::LONER, self::RECIPIENT], $this->userIds($managed, self::LONER), 'whom the manager who listed them may share with stays');
		// See writeInstalledVersion().
		$this->assertSame(IAppConfig::VALUE_MIXED, \OCP\Server::get(IAppConfig::class)->getValueType(Application::APP_ID, 'installed_version'));
	}

	/** A recipient row as add() wrote it before 0.3.1, without asking whom the owner may share with. */
	private function listedTheOldWay(Vehicle $vehicle, string $uid, ?string $by = null): void {
		$row = new ReminderRecipient();
		$row->setVehicleId((int)$vehicle->getId());
		$row->setUserId($uid);
		$row->setCreatedBy($by ?? $vehicle->getUserId());
		\OCP\Server::get(ReminderRecipientMapper::class)->insert($row);
	}

	/** Runs `$test` as the upgrade from `$version` would, then puts back the version the instance had. */
	private function asInstalled(string $version, \Closure $test): void {
		$before = \OCP\Server::get(IAppConfig::class)->getValueString(Application::APP_ID, 'installed_version');
		$this->writeInstalledVersion($version);
		try {
			$test();
		} finally {
			$this->writeInstalledVersion($before);
		}
	}

	/**
	 * Written to the row, not through IAppConfig: its typed setters retype the key core stores
	 * untyped, and core's own write in the next `occ upgrade` then throws a type conflict.
	 */
	private function writeInstalledVersion(string $version): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter($version))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('installed_version')))
			->executeStatement();
		\OCP\Server::get(IAppConfig::class)->clearCache();
	}

	/** @return list<string> */
	private function userIds(Vehicle $vehicle, string $asking = self::OWNER): array {
		return array_column($this->recipients->list($asking, $vehicle->getUuid()), 'user_id');
	}

	/** One grant on the vehicle, as the sharing UI will write it (M6). */
	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
