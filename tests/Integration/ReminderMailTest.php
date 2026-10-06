<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\ReminderJob;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\UserZone;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The mail digest as the job sends it, read back from Mailpit, the dev stack's SMTP sink
 * (.docker/compose.yml). It empties Mailpit and writes to the instance it runs against
 * (docs/development.md#testing).
 */
class ReminderMailTest extends TestCase {
	use Accounts;
	use CountsQueries;

	private const OWNER = 'nextfleet-test-mail-owner';
	/** On the list, with no Vehicle Access: told the plate and title, not given the link. */
	private const OUTSIDER = 'nextfleet-test-mail-outsider';

	private const LANGUAGES = [self::OWNER => 'de', self::OUTSIDER => 'en'];

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
			$users->createUser($uid, self::$password)->setSystemEMailAddress($uid . '@example.org');
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
	}

	protected function setUp(): void {
		\OCP\Server::get(IAppManager::class)->loadApps();
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->reminders = \OCP\Server::get(ReminderService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
		$this->forget();
		$this->mailpit('DELETE', '/messages');
	}

	/** Before the accounts go: a deleted account's uid is on none of its rows. */
	protected function tearDown(): void {
		$this->forget();
	}

	/**
	 * The rows and the notifications this suite invents gone for real, and the accounts as
	 * setUpBeforeClass() left them: a case may move one's zone or disable it.
	 */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = array_keys(self::LANGUAGES);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'user_id', 'fleet_reminder_receipts' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		$users = \OCP\Server::get(IUserManager::class);
		foreach ($people as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
			\OCP\Server::get(IConfig::class)->deleteUserValue($uid, 'core', 'timezone');
			\OCP\Server::get(IConfig::class)->deleteUserValue($uid, Application::APP_ID, MailService::CHECKED);
			$users->get($uid)?->setEnabled(true);
		}
	}

	/** A HU/AU due in four weeks reaches the owner's inbox once, in German. */
	public function testTheOwnerGetsOneDigestADayInTheirLanguage(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03 07:00');
		$this->runAt('2031-05-03 08:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertSame('Erinnerungen für deine Fahrzeuge', $mails[0]['Subject']);
		$this->assertStringContainsString('B-XY 123', $mails[0]['Text']);
		$this->assertStringContainsString('Hauptuntersuchung (HU/AU) ist am 31.05.2031 fällig', $mails[0]['Text']);
		$this->assertStringContainsString('vehicle=' . $vehicle->getUuid(), $mails[0]['Text']);
	}

	/** A reminder's line in the plain text ends at its words: the list item carries no meta info. */
	public function testAPlainTextLineEndsAtItsWords(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03 07:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertMatchesRegularExpression('/ist am 31\.05\.2031 fällig\r?\n/', $mails[0]['Text']);
	}

	/** 07:00 is the recipient's: before it nothing goes, in their zone as in the server's. */
	public function testTheDigestWaitsForSevenInTheRecipientsTimeZone(): void {
		\OCP\Server::get(IConfig::class)->setUserValue(self::OWNER, 'core', 'timezone', 'Europe/Berlin');
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		// 06:00 in Berlin.
		$this->runAt('2031-05-03 04:00');
		$this->assertSame([], $this->mails(self::OWNER));

		// 07:00 in Berlin, 05:00 on the server.
		$this->runAt('2031-05-03 05:00');
		$this->assertCount(1, $this->mails(self::OWNER));
	}

	/** Weekly goes on Monday and monthly on the 1st, with whatever is news by then. */
	public function testWeeklyWaitsForMondayAndMonthlyForTheFirst(): void {
		$weekly = $this->vehicles->create(self::OWNER, ['plate' => 'B-WK 1', 'reminder_mail' => 'weekly']);
		$monthly = $this->vehicles->create(self::OWNER, ['plate' => 'B-MO 1', 'reminder_mail' => 'monthly']);
		foreach ([$weekly, $monthly] as $vehicle) {
			$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		}

		// Saturday.
		$this->runAt('2031-05-03 07:00');
		$this->assertSame([], $this->mails(self::OWNER));

		// Monday: the weekly one only.
		$this->runAt('2031-05-05 07:00');
		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertStringContainsString('B-WK 1', $mails[0]['Text']);
		$this->assertStringNotContainsString('B-MO 1', $mails[0]['Text']);

		// The 1st of June, a Sunday: overdue is news for the monthly one.
		$this->runAt('2031-06-01 07:00');
		$mails = $this->mails(self::OWNER);
		$this->assertCount(2, $mails);
		$this->assertStringContainsString('B-MO 1', $mails[0]['Text']);
		$this->assertStringNotContainsString('B-WK 1', $mails[0]['Text']);
	}

	/** One mail covers every vehicle with news; the next day, only what is new since. */
	public function testOneMailCoversEveryVehicleAndNoNewsSendsNothing(): void {
		foreach (['B-AA 1', 'B-BB 2'] as $plate) {
			$vehicle = $this->vehicles->create(self::OWNER, ['plate' => $plate, 'reminder_mail' => 'daily']);
			$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		}

		$this->runAt('2031-05-03 07:00');
		$this->runAt('2031-05-04 07:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertStringContainsString('B-AA 1', $mails[0]['Text']);
		$this->assertStringContainsString('B-BB 2', $mails[0]['Text']);
	}

	/** A new due date is news by mail too, though the point it reached has the same name. */
	public function testAnEditedDueDateIsMailedAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03 07:00');
		$reminder = $this->reminders->list(self::OWNER, $vehicle->getUuid())[0];

		$this->reminders->update(self::OWNER, $vehicle->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['mode' => 'date', 'due_date' => '2031-05-20']);
		// Still one mail a day: the moved point's mail still counts for today.
		$this->runAt('2031-05-03 08:00');
		$this->assertCount(1, $this->mails(self::OWNER));
		$this->runAt('2031-05-04 07:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(2, $mails);
		$this->assertStringContainsString('Hauptuntersuchung (HU/AU) ist am 20.05.2031 fällig', $mails[0]['Text']);
	}

	/** Off, laid up or disposed: nothing by mail. */
	public function testAVehicleOffOrOutOfServiceIsNotMailed(): void {
		foreach ([['reminder_mail' => 'off'], ['reminder_mail' => 'daily', 'lifecycle' => 'laid_up']] as $fields) {
			$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'] + $fields);
			$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		}

		$this->runAt('2031-05-03 07:00');

		$this->assertSame([], $this->mails(self::OWNER));
	}

	/** A tractor counts engine hours, and its mail says so. */
	public function testALeadByCounterNamesTheVehiclesUnit(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-TR 1', 'odo_unit' => 'h', 'reminder_mail' => 'daily']);
		\OCP\Server::get(OdometerService::class)->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750000000, 'read_at_off' => 0, 'value' => 1450]);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Hydrauliköl', 'mode' => 'odo', 'due_odo' => 1500, 'lead_odo' => 100]);

		$this->runAt('2031-05-03 07:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertStringContainsString('Hydrauliköl ist bei 1500 h fällig', $mails[0]['Text']);
	}

	/** A disabled account on the list is told nothing. */
	public function testADisabledAccountIsNotMailed(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		\OCP\Server::get(IUserManager::class)->get(self::OWNER)?->setEnabled(false);

		$this->runAt('2031-05-03 07:00');

		$this->assertSame([], $this->mails(self::OWNER));
	}

	/** A receipt dated after now - a clock set ahead once - does not count as today's mail. */
	public function testAReceiptFromTheFutureDoesNotCountAsMailed(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-06-01 07:00');
		$this->mailpit('DELETE', '/messages');

		$this->runAt('2031-05-03 07:00');

		$this->assertCount(1, $this->mails(self::OWNER));
	}

	/** Being on the list grants nothing: told in their own language, given no way in. */
	public function testARecipientWithoutAccessIsMailedWithoutALink(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-31 07:00');

		$mails = $this->mails(self::OUTSIDER);
		$this->assertCount(1, $mails);
		$this->assertSame('Reminders for your vehicles', $mails[0]['Subject']);
		$this->assertStringContainsString('Insurance is due today', $mails[0]['Text']);
		$this->assertStringNotContainsString('vehicle=', $mails[0]['Text']);
	}

	/** A refusing SMTP server silences neither the notification nor tomorrow's mail. */
	public function testARefusingMailServerLeavesTheNotificationAndTomorrowsMail(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->runAt('2031-05-03 07:00', refusing: true);

		$this->assertCount(1, $this->notifications(self::OWNER));
		$this->assertSame([], $this->mails(self::OWNER));

		$this->runAt('2031-05-04 07:00');

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertStringContainsString('Hauptuntersuchung (HU/AU) ist am 31.05.2031 fällig', $mails[0]['Text']);
	}

	/** A mail that fails takes nobody else's with it, and goes on the next run, the same day. */
	public function testAMailThatFailsLeavesTheOthersMailedAndGoesNextRun(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::OUTSIDER);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		// The owner's mail is the round's first: the owner joined the list first.
		$this->runAt('2031-05-03 07:00', throwingFirst: true);

		$this->assertSame([], $this->mails(self::OWNER));
		$this->assertCount(1, $this->mails(self::OUTSIDER));

		$this->runAt('2031-05-03 08:00');

		$this->assertCount(1, $this->mails(self::OWNER));
		$this->assertCount(1, $this->mails(self::OUTSIDER));
	}

	/** The hourly job reads only the vehicles a reminder could ring for: the rest cost it nothing. */
	public function testVehiclesWithoutALiveReminderCostTheJobNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->runAt('2031-05-03 07:00');
		$one = self::queriesOf(fn () => $this->runAt('2031-05-03 08:00'));

		for ($i = 0; $i < 5; $i++) {
			$quiet = $this->vehicles->create(self::OWNER, ['plate' => 'B-QQ ' . $i, 'reminder_mail' => 'daily']);
			if ($i === 0) {
				// Dismissed is as quiet as none: nothing moves it on but a person.
				$dismissed = $this->reminders->create(self::OWNER, $quiet->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2031-05-31']);
				$this->reminders->dismiss(self::OWNER, $quiet->getUuid(), $dismissed['uuid'], $dismissed['updated_at']);
			}
		}
		$six = self::queriesOf(fn () => $this->runAt('2031-05-03 09:00'));

		$this->assertSame($one, $six);
	}

	/**
	 * A daily digest with nothing new is checked once that day, not every hour, however many
	 * vehicles it covers: news found later on them goes with tomorrow's.
	 */
	public function testADayWithNothingNewIsCheckedOnce(): void {
		$first = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $first->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2032-05-31']);
		$this->mailAt('2031-05-03 07:00')->digest();
		$one = self::queriesOf(fn () => $this->mailAt('2031-05-03 08:00')->digest());

		for ($i = 0; $i < 4; $i++) {
			$more = $this->vehicles->create(self::OWNER, ['plate' => 'B-MM ' . $i, 'reminder_mail' => 'daily']);
			$this->reminders->create(self::OWNER, $more->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2032-05-31']);
		}
		$this->mailAt('2031-05-03 09:00')->digest();
		$five = self::queriesOf(fn () => $this->mailAt('2031-05-03 10:00')->digest());
		$this->assertSame($one, $five);

		$reminder = $this->reminders->list(self::OWNER, $first->getUuid())[0];
		$this->reminders->update(self::OWNER, $first->getUuid(), $reminder['uuid'], $reminder['updated_at'], ['mode' => 'date', 'due_date' => '2031-05-31']);
		$this->mailAt('2031-05-03 11:00')->digest();
		$this->assertSame([], $this->mails(self::OWNER));
		$this->mailAt('2031-05-04 07:00')->digest();
		$this->assertCount(1, $this->mails(self::OWNER));
	}

	/**
	 * A vehicle that joins the recipient's list after the day's check - added, or given its first
	 * reminder - was never checked, so it is that day.
	 */
	public function testAVehicleNewToTheListIsCheckedTheSameDay(): void {
		$quiet = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $quiet->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2032-05-31']);
		$this->mailAt('2031-05-03 07:00')->digest();

		$due = $this->vehicles->create(self::OWNER, ['plate' => 'B-DD 1', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $due->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->mailAt('2031-05-03 08:00')->digest();

		$mails = $this->mails(self::OWNER);
		$this->assertCount(1, $mails);
		$this->assertStringContainsString('B-DD 1', $mails[0]['Text']);
	}

	/** Checked that day, but the day's digest went already: its news goes with tomorrow's. */
	public function testAVehicleNewToTheListAfterTheDaysMailWaitsForTomorrow(): void {
		$first = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $first->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->mailAt('2031-05-03 07:00')->digest();

		$due = $this->vehicles->create(self::OWNER, ['plate' => 'B-DD 1', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $due->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		$this->mailAt('2031-05-03 08:00')->digest();

		$this->assertCount(1, $this->mails(self::OWNER));
		$this->mailAt('2031-05-04 07:00')->digest();
		$texts = array_column($this->mails(self::OWNER), 'Text');
		$this->assertCount(2, $texts);
		// Each vehicle's news once: the first day's, and the newcomer's the day after.
		$this->assertCount(1, array_filter($texts, static fn (string $text): bool => str_contains($text, 'B-DD 1')));
		$this->assertCount(1, array_filter($texts, static fn (string $text): bool => str_contains($text, 'B-XY 123')));
	}

	/**
	 * The job, on a clock set to that moment in UTC. A refusing mailer answers the way
	 * Nextcloud's does when the SMTP server turns the message down: every recipient failed. A
	 * throwing one breaks with an \Error on the round's first mail, as a TypeError in a mail plugin
	 * would.
	 */
	private function runAt(string $moment, bool $refusing = false, bool $throwingFirst = false): void {
		$clock = $this->clockAt($moment);
		$mailer = \OCP\Server::get(IMailer::class);
		if ($refusing || $throwingFirst) {
			$real = $mailer;
			$mailer = $this->createMock(IMailer::class);
			$mailer->method('createMessage')->willReturnCallback(static fn () => $real->createMessage());
			$mailer->method('createEMailTemplate')->willReturnCallback(static fn (string $id, array $data = []) => $real->createEMailTemplate($id, $data));
			$mailer->method('send')->willReturnCallback(static function (IMessage $message) use ($real, $refusing, &$throwingFirst): array {
				if ($refusing) {
					return [self::OWNER . '@example.org'];
				}
				if ($throwingFirst) {
					$throwingFirst = false;
					throw new \Error('the mailer broke');
				}

				return $real->send($message);
			});
		}

		$notifications = new NotificationService(
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(ReminderMapper::class),
			\OCP\Server::get(ReminderRecipientMapper::class),
			\OCP\Server::get(ReminderReceiptMapper::class),
			\OCP\Server::get(OdoReadingMapper::class),
			\OCP\Server::get(\OCP\Notification\IManager::class),
			$clock,
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
			\OCP\Server::get(IUserManager::class),
		);
		(new ReminderJob($clock, $notifications, $this->mailAt($moment, $mailer)))->start(\OCP\Server::get(IJobList::class));
	}

	/** The digest alone, on a clock set to that moment in UTC. */
	private function mailAt(string $moment, ?IMailer $mailer = null): MailService {
		return new MailService(
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(ReminderMapper::class),
			\OCP\Server::get(ReminderReceiptMapper::class),
			\OCP\Server::get(OdoReadingMapper::class),
			\OCP\Server::get(VehicleAccess::class),
			$mailer ?? \OCP\Server::get(IMailer::class),
			\OCP\Server::get(IUserManager::class),
			\OCP\Server::get(IFactory::class),
			\OCP\Server::get(IConfig::class),
			\OCP\Server::get(UserZone::class),
			\OCP\Server::get(IURLGenerator::class),
			$this->clockAt($moment),
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
		);
	}

	private function clockAt(string $moment): ITimeFactory {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

		return $clock;
	}

	/**
	 * What reached one address, newest first, with its plain text.
	 *
	 * @return list<array{Subject: string, Text: string}>
	 */
	private function mails(string $uid): array {
		$list = $this->mailpit('GET', '/search?query=' . rawurlencode('to:' . $uid . '@example.org'));

		return array_map(fn (array $one): array => $this->mailpit('GET', '/message/' . $one['ID']), $list['messages']);
	}

	private function mailpit(string $method, string $path): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->request($method, Endpoints::mailpit() . $path, [
			'nextcloud' => ['allow_local_address' => true],
		]);

		return json_decode((string)$response->getBody(), true) ?? [];
	}

	/** @return list<array<string, mixed>> the user's notifications of this app, over OCS */
	private function notifications(string $uid): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->get(
			Endpoints::server() . '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
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
