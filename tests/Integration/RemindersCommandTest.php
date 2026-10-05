<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Command\RemindersCommand;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\UserZone;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:reminders`: a user's due reminders as the dashboard reads them, and the hourly
 * reminder job on demand, read back from Mailpit as ReminderMailTest does.
 *
 * It empties Mailpit and writes to the instance it runs against (docs/development.md#testing).
 */
class RemindersCommandTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-reminders-owner';
	private const MAILPIT = 'http://mail:8025/api/v1';

	private VehicleService $vehicles;
	private ReminderService $reminders;
	private CommandTester $command;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::OWNER]);
		\OCP\Server::get(IUserManager::class)->createUser(self::OWNER, bin2hex(random_bytes(16)))?->setSystemEMailAddress(self::OWNER . '@example.org');
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::OWNER]);
	}

	protected function setUp(): void {
		// cron.php boots every app before a job; without the notifications app a notice goes nowhere.
		\OCP\Server::get(IAppManager::class)->loadApps();
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->reminders = \OCP\Server::get(ReminderService::class);
		$this->command = new CommandTester(\OCP\Server::get(RemindersCommand::class));
		$this->forget();
		$this->mailpit('DELETE', '/messages');
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'user_id', 'fleet_reminder_receipts' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
		$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser(self::OWNER));
		\OCP\Server::get(IConfig::class)->deleteUserValue(self::OWNER, Application::APP_ID, MailService::CHECKED);
	}

	/** Most urgent first, a template's title in words, as the dashboard shows them. */
	public function testItListsTheUsersDueRemindersMostUrgentFirst(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 1']);
		$later = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance', 'mode' => 'date', 'due_date' => '2099-01-31']);
		$overdue = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2020-05-31']);

		$this->assertSame(0, $this->command->execute(['uid' => self::OWNER, '--output' => 'json']), $this->command->getDisplay());

		$this->assertSame([
			[
				'vehicle' => $vehicle->getUuid(),
				'plate' => 'B-RM 1',
				'reminder' => $overdue['uuid'],
				'title' => 'Technical inspection (HU/AU)',
				'due_date' => '2020-05-31',
				'due_odo' => null,
				'estimate' => null,
				'state' => 'overdue',
				'snoozed_until' => null,
			],
			[
				'vehicle' => $vehicle->getUuid(),
				'plate' => 'B-RM 1',
				'reminder' => $later['uuid'],
				'title' => 'Insurance',
				'due_date' => '2099-01-31',
				'due_odo' => null,
				'estimate' => null,
				'state' => 'planned',
				'snoozed_until' => null,
			],
		], json_decode($this->command->getDisplay(), true));
	}

	public function testPlainSaysWhenNothingIsDue(): void {
		$this->assertSame(0, $this->command->execute(['uid' => self::OWNER]));

		$this->assertSame("No reminders are due.\n", $this->command->getDisplay());
	}

	public function testAUidWithNoAccountIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['uid' => 'nextfleet-test-reminders-nobody', '--output' => 'json'], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('nextfleet-test-reminders-nobody', $this->command->getErrorOutput());
	}

	public function testNoUidAndNoSendIsBadInput(): void {
		$this->assertSame(2, $this->command->execute([], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertNotSame('', $this->command->getErrorOutput());
	}

	/** The job's run: states persisted, the digest mailed, and a second run that day mails nobody again. */
	public function testSendRunsTheJobAndMailsOnceADay(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 2', 'reminder_mail' => 'daily']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2031-05-31']);

		$this->assertSame(0, $this->commandAt('2031-05-03 07:00')->execute(['--send' => true]));
		$this->assertSame(0, $this->commandAt('2031-05-03 08:00')->execute(['--send' => true]));

		$this->assertSame(Reminder::WARNED, \OCP\Server::get(ReminderMapper::class)->findByVehicle($vehicle->getId())[0]->getState());
		$this->assertSame(1, $this->mailpit('GET', '/search?query=' . rawurlencode('to:' . self::OWNER . '@example.org'))['messages_count']);
	}

	/** With a uid too, the list follows the run. */
	public function testSendWithAUidListsAfterTheRun(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 3']);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2020-05-31']);

		$this->assertSame(0, $this->command->execute(['uid' => self::OWNER, '--send' => true, '--output' => 'json']));

		$this->assertSame(['overdue'], array_column(json_decode($this->command->getDisplay(), true), 'state'));
		$this->assertSame(Reminder::OVERDUE, \OCP\Server::get(ReminderMapper::class)->findByVehicle($vehicle->getId())[0]->getState());
	}

	/** The command with the job's two services on a clock set to that moment in UTC. */
	private function commandAt(string $moment): CommandTester {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

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
		$mail = new MailService(
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(ReminderMapper::class),
			\OCP\Server::get(ReminderReceiptMapper::class),
			\OCP\Server::get(OdoReadingMapper::class),
			\OCP\Server::get(VehicleAccess::class),
			\OCP\Server::get(IMailer::class),
			\OCP\Server::get(IUserManager::class),
			\OCP\Server::get(IFactory::class),
			\OCP\Server::get(IConfig::class),
			\OCP\Server::get(UserZone::class),
			\OCP\Server::get(IURLGenerator::class),
			$clock,
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
		);

		return new CommandTester(new RemindersCommand(
			$this->reminders,
			$notifications,
			$mail,
			\OCP\Server::get(IFactory::class),
			\OCP\Server::get(IUserManager::class),
		));
	}

	private function mailpit(string $method, string $path): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->request($method, self::MAILPIT . $path, [
			'nextcloud' => ['allow_local_address' => true],
		]);

		return json_decode((string)$response->getBody(), true) ?? [];
	}
}
