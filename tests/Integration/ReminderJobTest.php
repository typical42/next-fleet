<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\ReminderJob;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\INotification;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The hourly job against the real database and the notifications app: what it persists, and
 * what reaches a recipient's list over OCS (docs/architecture.md#reminder-engine).
 *
 * It writes to the instance it runs against (docs/development.md#testing), and it needs real
 * accounts, since the list is read the way the phone reads it.
 */
class ReminderJobTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-job-owner';
	/** On the list, with no Vehicle Access: told the plate and title, not given the link. */
	private const OUTSIDER = 'nextfleet-test-job-outsider';
	private const LANGUAGES = [self::OWNER => 'de', self::OUTSIDER => 'en'];
	/** On the list, with no account any more. */
	private const GONE = 'nextfleet-test-job-gone';

	private static string $password;
	private VehicleService $vehicles;
	private ReminderService $reminders;
	private RecipientService $recipients;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
		self::$password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach (self::LANGUAGES as $uid => $language) {
			$users->createUser($uid, self::$password);
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
	}

	protected function setUp(): void {
		// cron.php boots every app before it runs a job; a test does not, and without the
		// notifications app booted a notification has nowhere to go.
		\OCP\Server::get(IAppManager::class)->loadApps();
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->reminders = \OCP\Server::get(ReminderService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
		$this->forget();
	}

	/** Before the accounts go: a deleted account's uid is on none of its rows. */
	protected function tearDown(): void {
		$this->forget();
	}

	/** The rows and the notifications this suite invents, gone for real. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [...array_keys(self::LANGUAGES), self::GONE];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'user_id', 'fleet_reminder_receipts' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_maintenance' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		foreach ($people as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
		}
	}

	/** 28 days ahead of a HU/AU, told once, in the recipient's language. */
	public function testTheOwnerIsToldOnceAboutAHuAuDueInFourWeeksInTheirLanguage(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03');
		$this->runAt('2031-05-03 01:00');

		$list = $this->notifications(self::OWNER);
		$this->assertCount(1, $list);
		$this->assertSame('B-XY 123: Hauptuntersuchung (HU/AU) ist am 31.05.2031 fällig', $list[0]['subject']);
		$this->assertStringContainsString('vehicle=' . $vehicle->getUuid(), $list[0]['link']);
		$this->assertSame(Reminder::WARNED, $this->stored($vehicle->getId())->getState());
	}

	/** A notification its reminder moved past is taken back, so only the overdue one rings. */
	public function testMovingOnReplacesTheWarningWithTheOverdueNotice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03');
		$this->runAt('2031-06-01');

		$list = $this->notifications(self::OWNER);
		$this->assertSame(['B-XY 123: Hauptuntersuchung (HU/AU) ist überfällig (fällig am 31.05.2031)'], array_column($list, 'subject'));
		$this->assertSame(Reminder::OVERDUE, $this->stored($vehicle->getId())->getState());
	}

	/** A newer point replaces the older one rather than standing beside it. */
	public function testTheStartOfTheMonthReplacesTheMonthBefore(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31', 'warn_month_start' => true]);

		$this->runAt('2031-04-30');
		$this->runAt('2031-05-01');

		$this->assertCount(1, $this->notifications(self::OWNER));
	}

	/** Due with the day's own warning unticked sends nothing new, and keeps the warning up. */
	public function testAnUntickedDueDateLeavesTheWarningStanding(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31', 'warn_due_date' => false]);

		$this->runAt('2031-05-03');
		$this->runAt('2031-05-31');

		$this->assertSame(['B-XY 123: Insurance ist am 31.05.2031 fällig'], array_column($this->notifications(self::OWNER), 'subject'));
		$this->assertSame(Reminder::DUE, $this->stored($vehicle->getId())->getState());
	}

	/** Being on the list grants nothing: told in their own language, given no way in. */
	public function testARecipientWithoutAccessIsToldWithoutALink(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-31');

		$list = $this->notifications(self::OUTSIDER);
		$this->assertSame(['B-XY 123: Insurance is due today'], array_column($list, 'subject'));
		$this->assertSame('', $list[0]['link']);
	}

	/**
	 * A recipient whose account went while the sweep held the vehicle: its erasure ran first, so a
	 * receipt written now would name the uid for whoever takes it next. The others are still told.
	 */
	public function testARecipientWhoseAccountIsGoneIsNeitherToldNorNamed(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$gone = new ReminderRecipient();
		$gone->setVehicleId((int)$vehicle->getId());
		$gone->setUserId(self::GONE);
		$gone->setCreatedBy(self::OWNER);
		\OCP\Server::get(ReminderRecipientMapper::class)->insert($gone);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03');

		$this->assertCount(1, $this->notifications(self::OWNER));
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('fleet_reminder_receipts')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::GONE)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}

	/** Laid up, the state moves and nobody is told; back on the road, where it stands sends once. */
	public function testALaidUpVehicleIsEvaluatedAndToldOnlyOnceBackInService(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'lifecycle' => 'laid_up']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03');
		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertSame(Reminder::WARNED, $this->stored($vehicle->getId())->getState());

		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['plate' => 'B-XY 123', 'lifecycle' => 'active']);
		$this->runAt('2031-05-04');
		$this->runAt('2031-05-05');

		$this->assertCount(1, $this->notifications(self::OWNER));
	}

	/** A disposed vehicle drops out of the round: its reminders are neither moved nor sent. */
	public function testADisposedVehicleIsLeftAlone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'lifecycle' => 'disposed', 'disposed_at' => '2030-01-01']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-06-01');

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertSame(Reminder::PLANNED, $this->stored($vehicle->getId())->getState());
	}

	/** Snoozed silences every channel: what was already sent stops ringing at once. */
	public function testSnoozingTakesTheNotificationBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03');
		// The job persisted the state, so the token moved.
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->snooze(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], '2031-05-20');

		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Deleting ends the reminder, and the job no longer sees it to take anything back. */
	public function testDeletingTakesTheNotificationBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03');
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->delete(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at']);

		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/**
	 * The job skips a deleted vehicle, so nothing else would ever take its notifications back.
	 * Counted in the store, not read over OCS: the notifier drops one whose vehicle is gone as the
	 * list is read, which hides the leftover from a phone but not from the push or the table.
	 */
	public function testDeletingTheVehicleTakesItsNotificationsBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03');
		$reminder = $this->stored($vehicle->getId());
		$this->assertSame(1, $this->sent($reminder));
		$vehicle = $this->vehicles->find(self::OWNER, $vehicle->getUuid());

		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$this->assertSame(0, $this->sent($reminder));
	}

	/** Withdrawn work takes its occurrence back, and with it what that occurrence sent. */
	public function testDeletingTheClosingRecordTakesTheNextOccurrencesNotificationBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$fluid = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Brake fluid', 'mode' => 'date', 'due_date' => '2026-01-31', 'recur_months' => 12]);
		$record = \OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'done_at' => (new \DateTimeImmutable('2026-01-20 12:00', new \DateTimeZone('UTC')))->getTimestamp(),
			'done_at_off' => 0,
			'title' => 'Brake fluid',
			'cost' => 8900,
			'closes' => $fluid['uuid'],
		]);
		$this->runAt('2026-12-25');
		$this->assertCount(1, $this->notifications(self::OWNER));

		WatchedWithdrawals::watch();
		try {
			\OCP\Server::get(MaintenanceService::class)->delete(self::OWNER, $vehicle->getUuid(), $record['uuid'], $record['updated_at']);
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		$this->assertSame([], $this->notifications(self::OWNER));
		// After the commit: one taken back inside it would reach the phone even if the write rolled back.
		$this->assertNotSame([], $withdrawals);
		$this->assertNotContains(true, $withdrawals);
	}

	/** An edit refused for the reminder it would close now takes nothing back from the one it closed. */
	public function testARefusedSwitchOfTheClosedReminderLeavesItsNotificationStanding(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$fluid = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Brake fluid', 'mode' => 'date', 'due_date' => '2026-01-31', 'recur_months' => 12]);
		// Recurs by km, so a record without a counter cannot close it.
		$oil = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'oil_change', 'due_date' => '2031-09-30', 'due_odo' => 125000]);
		$work = [
			'done_at' => (new \DateTimeImmutable('2026-01-20 12:00', new \DateTimeZone('UTC')))->getTimestamp(),
			'done_at_off' => 0,
			'title' => 'Brake fluid',
			'cost' => 8900,
		];
		$maintenance = \OCP\Server::get(MaintenanceService::class);
		$record = $maintenance->record(self::OWNER, $vehicle->getUuid(), $work + ['closes' => $fluid['uuid']]);
		$this->runAt('2026-12-25');
		$closed = $this->byUuid($vehicle->getId(), $fluid['uuid']);
		$this->assertSame(1, $this->sent($closed));

		WatchedWithdrawals::watch();
		try {
			$maintenance->update(self::OWNER, $vehicle->getUuid(), $record['uuid'], $record['updated_at'], $work + ['closes' => $oil['uuid']]);
			$this->fail('the record was saved');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('odo is the counter this reminder recurs from', $e->getMessage());
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		$this->assertSame([], $withdrawals);
		$this->assertSame(2, $this->byUuid($vehicle->getId(), $fluid['uuid'])->getOccurrence());
		$this->assertSame(1, $this->sent($closed));
	}

	/** Off the list is off it now: what they were sent goes, and the others' stays. */
	public function testRemovingARecipientTakesTheirNotificationsBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03');
		$reminder = $this->stored($vehicle->getId());
		$this->assertSame(1, $this->sent($reminder, self::OUTSIDER));

		$this->recipients->remove(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);

		$this->assertSame(0, $this->sent($reminder, self::OUTSIDER));
		$this->assertSame(1, $this->sent($reminder, self::OWNER));
	}

	/** A revoke that prunes a recipient takes what they were sent with them. */
	public function testARevokeThatPrunesARecipientTakesTheirNotificationsBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$grants = \OCP\Server::get(GrantService::class);
		$grant = $grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::OUTSIDER, 'grantee_type' => 'user', 'role' => 'viewer'])[0];
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03');
		$reminder = $this->stored($vehicle->getId());
		$this->assertSame(1, $this->sent($reminder, self::OUTSIDER));

		$grants->revoke(self::OWNER, $vehicle->getUuid(), $grant['uuid']);

		$this->assertSame(0, $this->sent($reminder, self::OUTSIDER));
		$this->assertSame(1, $this->sent($reminder, self::OWNER));
	}

	/** A new due date is a new point: the old notice goes at once, and the new date rings. */
	public function testAnEditAfterOverdueRangRingsForTheNewDate(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-06-01');
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->update(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['mode' => 'date', 'due_date' => '2031-06-02']);
		$this->assertSame(0, $this->sent($this->stored($vehicle->getId())));
		$this->runAt('2031-06-03');

		$this->assertSame(['B-XY 123: Hauptuntersuchung (HU/AU) ist überfällig (fällig am 02.06.2031)'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/** An edit that leaves the standing notice's point alone leaves the notice. */
	public function testAnEditOfAnotherPointLeavesTheNoticeStanding(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-01');
		$this->runAt('2031-05-31');
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->update(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31', 'warn_month_before' => false]);

		$this->assertSame(1, $this->sent($this->stored($vehicle->getId())));
		$this->assertSame(['B-XY 123: Insurance ist heute fällig'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/** Unticking the point that rang tells the one before it again, as the job does on the day. */
	public function testUntickingTheDayThatRangLeavesDueOnStanding(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-01');
		$this->runAt('2031-05-31');
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->update(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31', 'warn_due_date' => false]);
		$this->runAt('2031-05-31 01:00');

		$this->assertSame(['B-XY 123: Insurance ist am 31.05.2031 fällig'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/** An edit between the sweep's commit and its send: the old date is not shown, the new one rings. */
	public function testAnEditRacingTheSweepLeavesNoStaleNotice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-06-01', function () use ($vehicle): void {
			$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];
			$this->reminders->update(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['mode' => 'date', 'due_date' => '2031-05-30']);
		});

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->runAt('2031-06-01 01:00');
		$this->assertSame(['B-XY 123: Hauptuntersuchung (HU/AU) ist überfällig (fällig am 30.05.2031)'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/**
	 * The sweep sends after its commit, so a dismissal can land between the two and withdraw
	 * before the notice is out. The notifier then drops it when the list is read.
	 */
	public function testADismissRacingTheSweepLeavesNoNotice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03', function () use ($vehicle): void {
			$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];
			$this->reminders->dismiss(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at']);
		});
		$reminder = $this->stored($vehicle->getId());
		$this->assertSame(1, $this->sent($reminder));

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertSame(0, $this->sent($reminder));
	}

	/** A send that fails takes nobody else's with it, and is tried again on the next run. */
	public function testAFailedSendIsTriedAgainAndTheOthersAreStillTold(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		// An \Error, not an \Exception: a TypeError in a notifier app is one.
		$this->runAt('2031-05-03', failing: static fn (INotification $notification): bool => $notification->getUser() === self::OWNER);

		$reminder = $this->stored($vehicle->getId());
		$this->assertSame(0, $this->sent($reminder, self::OWNER));
		$this->assertSame(1, $this->sent($reminder, self::OUTSIDER));

		$this->runAt('2031-05-03 01:00');

		$this->assertSame(1, $this->sent($reminder, self::OWNER));
	}

	/** A vehicle that cannot be swept leaves the next one told, and the digest still goes. */
	public function testAVehicleThatFailsLeavesTheOthersSwept(): void {
		$broken = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$fine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		foreach ([$broken, $fine] as $vehicle) {
			$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		}

		$this->runAt('2031-05-03', breaking: $broken->getId());

		$this->assertSame(0, $this->sent($this->stored($broken->getId())));
		$this->assertSame(1, $this->sent($this->stored($fine->getId())));
	}

	/** A sweep that cannot even list the vehicles still leaves the digest its turn. */
	public function testASweepThatCannotListTheVehiclesStillReachesTheDigest(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		// runAt() asserts the digest ran.
		$this->runAt('2031-05-03', unlisted: true);

		$this->assertSame(0, $this->sent($this->stored($vehicle->getId())));
	}

	/**
	 * The job, on a clock set to that moment in UTC. Every run asserts the digest was reached.
	 *
	 * @param ?\Closure(): void $race run once, after the sweep's commit and before its first send
	 * @param ?\Closure(INotification): bool $failing which sends throw
	 * @param ?int $breaking the vehicle whose hold throws
	 * @param bool $unlisted whether listing the vehicles throws
	 */
	private function runAt(string $moment, ?\Closure $race = null, ?\Closure $failing = null, ?int $breaking = null, bool $unlisted = false): void {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		if ($race !== null || $failing !== null) {
			$real = $manager;
			$manager = $this->createMock(\OCP\Notification\IManager::class);
			$manager->method('createNotification')->willReturnCallback(static fn () => $real->createNotification());
			$manager->method('markProcessed')->willReturnCallback(static fn ($notification) => $real->markProcessed($notification));
			$manager->method('notify')->willReturnCallback(static function (INotification $notification) use ($real, &$race, $failing): void {
				if ($race !== null) {
					$run = $race;
					$race = null;
					$run();
				}
				if ($failing !== null && $failing($notification)) {
					throw new \Error('the notifier broke');
				}
				$real->notify($notification);
			});
		}
		$vehicles = \OCP\Server::get(VehicleMapper::class);
		if ($breaking !== null || $unlisted) {
			$vehicles = new class(\OCP\Server::get(IDBConnection::class), \OCP\Server::get(ITimeFactory::class), \OCP\Server::get(ISecureRandom::class), $breaking, $unlisted) extends VehicleMapper {
				public function __construct(
					IDBConnection $db,
					ITimeFactory $time,
					ISecureRandom $random,
					private ?int $breaking,
					private bool $unlisted,
				) {
					parent::__construct($db, $time, $random);
				}

				public function findReminded(bool $lists = true): array {
					if ($this->unlisted) {
						throw new \Error('the list broke');
					}

					return parent::findReminded($lists);
				}

				public function hold(int $vehicleId): void {
					if ($vehicleId === $this->breaking) {
						throw new \Error('the vehicle broke');
					}
					parent::hold($vehicleId);
				}
			};
		}
		$mail = $this->createMock(MailService::class);
		$mail->expects($this->once())->method('digest');
		$service = new NotificationService(
			$vehicles,
			\OCP\Server::get(ReminderMapper::class),
			\OCP\Server::get(ReminderRecipientMapper::class),
			\OCP\Server::get(\OCA\NextFleet\Db\ReminderReceiptMapper::class),
			\OCP\Server::get(\OCA\NextFleet\Db\OdoReadingMapper::class),
			$manager,
			$clock,
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
			\OCP\Server::get(IUserManager::class),
		);
		// The digest is ReminderMailTest's; a real one here would mail the whole instance.
		(new ReminderJob($clock, $service, $mail))->start(\OCP\Server::get(IJobList::class));
	}

	private function stored(int $vehicleId): Reminder {
		return \OCP\Server::get(ReminderMapper::class)->findByVehicle($vehicleId)[0];
	}

	private function byUuid(int $vehicleId, string $uuid): Reminder {
		foreach (\OCP\Server::get(ReminderMapper::class)->findByVehicle($vehicleId) as $reminder) {
			if ($reminder->getUuid() === $uuid) {
				return $reminder;
			}
		}
		$this->fail('reminder ' . $uuid . ' is not on the vehicle');
	}

	/**
	 * How many notifications the store holds for this reminder, to one user or to anyone of this
	 * suite. Not to anyone at all: the schema test hands reminder ids out again, and the instance's
	 * other accounts keep the notices of reminders a suite deleted under them.
	 */
	private function sent(Reminder $reminder, ?string $uid = null): int {
		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		$count = 0;
		foreach ($uid === null ? [...array_keys(self::LANGUAGES), self::GONE] : [$uid] as $one) {
			$notification = $manager->createNotification();
			$notification->setApp(Application::APP_ID)->setObject(NotificationService::OBJECT, (string)$reminder->getId())->setUser($one);
			$count += $manager->getCount($notification);
		}

		return $count;
	}

	/**
	 * The user's notifications as the Nextcloud app on a phone reads them, prepared in their
	 * language by the notifier.
	 *
	 * @return list<array{subject: string, link: string, object_id: string}>
	 */
	private function notifications(string $uid): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->get(
			'http://localhost/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
			[
				'auth' => [$uid, self::$password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$data = json_decode((string)$response->getBody(), true)['ocs']['data'];

		return array_values(array_filter($data, static fn (array $one): bool => $one['app'] === Application::APP_ID));
	}
}
