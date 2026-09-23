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
use OCA\NextFleet\Db\ReminderRecipientMapper;
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
	private const OWNER = 'nextfleet-test-job-owner';
	/** On the list, with no Vehicle Access: told the plate and title, not given the link. */
	private const OUTSIDER = 'nextfleet-test-job-outsider';

	private string $password;
	private VehicleService $vehicles;
	private ReminderService $reminders;
	private RecipientService $recipients;

	protected function setUp(): void {
		// cron.php boots every app before it runs a job; a test does not, and without the
		// notifications app booted a notification has nowhere to go.
		\OCP\Server::get(IAppManager::class)->loadApps();
		$container = (new Application())->getContainer();
		$this->vehicles = $container->get(VehicleService::class);
		$this->reminders = $container->get(ReminderService::class);
		$this->recipients = $container->get(RecipientService::class);
		$this->forget();

		$this->password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach ([self::OWNER => 'de', self::OUTSIDER => 'en'] as $uid => $language) {
			$users->createUser($uid, $this->password);
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/**
	 * The accounts and the rows this suite invents, gone for real. Rows first: deleting an account
	 * pseudonymises them, and they would outlive the run.
	 */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::OUTSIDER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'user_id', 'fleet_reminder_receipts' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_maintenance' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::OUTSIDER] as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/** Done when, first line: 28 days ahead of a HU/AU, told once, in the recipient's language. */
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

		\OCP\Server::get(MaintenanceService::class)->delete(self::OWNER, $vehicle->getUuid(), $record['uuid'], $record['updated_at']);

		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/**
	 * The job, on a clock set to that moment in UTC.
	 */
	private function runAt(string $moment): void {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

		$container = (new Application())->getContainer();
		$service = new NotificationService(
			$container->get(\OCA\NextFleet\Db\VehicleMapper::class),
			$container->get(ReminderMapper::class),
			$container->get(ReminderRecipientMapper::class),
			$container->get(\OCA\NextFleet\Db\ReminderReceiptMapper::class),
			$container->get(\OCA\NextFleet\Db\OdoReadingMapper::class),
			$container->get(\OCP\Notification\IManager::class),
			$clock,
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
		);
		// The digest is ReminderMailTest's; a real one here would mail the whole instance.
		(new ReminderJob($clock, $service, $this->createMock(MailService::class)))->start(\OCP\Server::get(IJobList::class));
	}

	private function stored(int $vehicleId): Reminder {
		return \OCP\Server::get(ReminderMapper::class)->findByVehicle($vehicleId)[0];
	}

	/** How many notifications the store holds for this reminder, to anyone. */
	private function sent(Reminder $reminder): int {
		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		$notification = $manager->createNotification();
		$notification->setApp(Application::APP_ID)->setObject(NotificationService::OBJECT, (string)$reminder->getId());

		return $manager->getCount($notification);
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
				'auth' => [$uid, $this->password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$data = json_decode((string)$response->getBody(), true)['ocs']['data'];

		return array_values(array_filter($data, static fn (array $one): bool => $one['app'] === Application::APP_ID));
	}
}
