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
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The mail digest as the job sends it, read back from Mailpit, the dev stack's SMTP sink
 * (.docker/compose.yml). It empties Mailpit and writes to the instance it runs against
 * (docs/development.md#testing).
 */
class ReminderMailTest extends TestCase {
	private const OWNER = 'nextfleet-test-mail-owner';
	/** On the list, with no Vehicle Access: told the plate and title, not given the link. */
	private const OUTSIDER = 'nextfleet-test-mail-outsider';
	private const MAILPIT = 'http://mail:8025/api/v1';

	private string $password;
	private VehicleService $vehicles;
	private ReminderService $reminders;
	private RecipientService $recipients;

	protected function setUp(): void {
		\OCP\Server::get(IAppManager::class)->loadApps();
		$container = (new Application())->getContainer();
		$this->vehicles = $container->get(VehicleService::class);
		$this->reminders = $container->get(ReminderService::class);
		$this->recipients = $container->get(RecipientService::class);
		$this->forget();
		$this->mailpit('DELETE', '/messages');

		$this->password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach ([self::OWNER => 'de', self::OUTSIDER => 'en'] as $uid => $language) {
			$users->createUser($uid, $this->password)->setSystemEMailAddress($uid . '@example.org');
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** The accounts and the rows this suite invents, gone for real. */
	private function forget(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::OUTSIDER] as $uid) {
			$users->get($uid)?->delete();
		}
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::OUTSIDER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'user_id', 'fleet_reminder_receipts' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** Done when, second line: the same HU/AU reaches the owner's inbox, once, in German. */
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

	/** Off, laid up or disposed: nothing by mail. */
	public function testAVehicleOffOrOutOfServiceIsNotMailed(): void {
		foreach ([['reminder_mail' => 'off'], ['reminder_mail' => 'daily', 'lifecycle' => 'laid_up']] as $fields) {
			$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'] + $fields);
			$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);
		}

		$this->runAt('2031-05-03 07:00');

		$this->assertSame([], $this->mails(self::OWNER));
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

	/** Done when, second line: a refusing SMTP server silences neither the app nor tomorrow. */
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

	/**
	 * The job, on a clock set to that moment in UTC. A refusing mailer answers the way
	 * Nextcloud's does when the SMTP server turns the message down: every recipient failed.
	 */
	private function runAt(string $moment, bool $refusing = false): void {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

		$mailer = \OCP\Server::get(IMailer::class);
		if ($refusing) {
			$real = $mailer;
			$mailer = $this->createMock(IMailer::class);
			$mailer->method('createMessage')->willReturnCallback(static fn () => $real->createMessage());
			$mailer->method('createEMailTemplate')->willReturnCallback(static fn (string $id, array $data = []) => $real->createEMailTemplate($id, $data));
			$mailer->method('send')->willReturn([self::OWNER . '@example.org']);
		}

		$container = (new Application())->getContainer();
		$db = \OCP\Server::get(IDBConnection::class);
		$logger = \OCP\Server::get(LoggerInterface::class);
		$notifications = new NotificationService(
			$container->get(VehicleMapper::class),
			$container->get(ReminderMapper::class),
			$container->get(ReminderRecipientMapper::class),
			$container->get(ReminderReceiptMapper::class),
			$container->get(OdoReadingMapper::class),
			\OCP\Server::get(\OCP\Notification\IManager::class),
			$clock,
			$db,
			$logger,
		);
		$mail = new MailService(
			$container->get(VehicleMapper::class),
			$container->get(ReminderMapper::class),
			$container->get(ReminderRecipientMapper::class),
			$container->get(ReminderReceiptMapper::class),
			$container->get(OdoReadingMapper::class),
			$container->get(VehicleAccess::class),
			$mailer,
			\OCP\Server::get(IUserManager::class),
			\OCP\Server::get(IFactory::class),
			\OCP\Server::get(IConfig::class),
			\OCP\Server::get(IURLGenerator::class),
			$clock,
			$db,
			$logger,
		);
		(new ReminderJob($clock, $notifications, $mail))->start(\OCP\Server::get(IJobList::class));
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
		$response = \OCP\Server::get(IClientService::class)->newClient()->request($method, self::MAILPIT . $path, [
			'nextcloud' => ['allow_local_address' => true],
		]);

		return json_decode((string)$response->getBody(), true) ?? [];
	}

	/** @return list<array<string, mixed>> the user's notifications of this app, over OCS */
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
