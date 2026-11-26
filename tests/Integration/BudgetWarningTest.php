<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\BudgetMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\BudgetService;
use OCA\NextFleet\Service\BudgetWarnings;
use OCA\NextFleet\Service\CostService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\UserZone;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The budget's warning in the reminder round: at 80 % and at 100 % of the year's costs, to the
 * owner and the managers, once each, in the notification list and by mail
 * (docs/architecture.md#reminder-engine). On a moved clock; the list is read over OCS and the mail
 * from Mailpit, as ReminderMailTest reads them.
 *
 * It writes to the instance it runs against and empties Mailpit (docs/development.md#testing).
 *
 * @psalm-import-type NextFleetBudget from \OCA\NextFleet\ResponseDefinitions
 */
class BudgetWarningTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-bgw-owner';
	private const MANAGER = 'nextfleet-test-bgw-manager';
	private const DRIVER = 'nextfleet-test-bgw-driver';
	private const PEOPLE = [self::OWNER, self::MANAGER, self::DRIVER];

	private static string $password;
	private BudgetService $budgets;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(self::PEOPLE);
		self::$password = bin2hex(random_bytes(16));
		$users = Server::get(IUserManager::class);
		foreach (self::PEOPLE as $uid) {
			$users->createUser($uid, self::$password)->setSystemEMailAddress($uid . '@example.org');
			Server::get(IConfig::class)->setUserValue($uid, 'core', 'lang', 'en');
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(self::PEOPLE);
	}

	protected function setUp(): void {
		// cron.php boots every app before it runs a job; a test does not (ReminderJobTest).
		Server::get(IAppManager::class)->loadApps();
		$this->budgets = Server::get(BudgetService::class);
		$this->forget();
		$this->mailpit('DELETE', '/messages');
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		foreach (['fleet_budgets' => 'created_by', 'fleet_expenses' => 'created_by', 'fleet_access' => 'created_by', 'fleet_vehicles' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter(self::PEOPLE, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = Server::get(\OCP\Notification\IManager::class);
		foreach (self::PEOPLE as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
			Server::get(IConfig::class)->deleteUserValue($uid, 'core', 'timezone');
		}
	}

	public function testTheOwnerAndTheManagerAreToldOnceAtEightyPercent(): void {
		$vehicle = $this->pool();
		$this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 85000);

		$this->sweepAt('2031-04-01 08:00');
		$this->sweepAt('2031-04-02 08:00');

		foreach ([self::OWNER, self::MANAGER] as $uid) {
			$this->assertSame(['NF-BW 1: 80 % of the budget for 2031 is used'], array_column($this->notifications($uid), 'subject'), $uid);
			$this->assertSame(['Budget of NF-BW 1'], array_column($this->mails($uid), 'Subject'), $uid);
		}
		$this->assertSame([], $this->notifications(self::DRIVER));
		$this->assertSame([], $this->mails(self::DRIVER));
	}

	/** One notice per budget: the share it reached last. */
	public function testAtAHundredPercentTheyAreToldAgain(): void {
		$vehicle = $this->pool();
		$this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 85000);
		$this->sweepAt('2031-04-01 08:00');
		$this->expense($vehicle, '2031-04-10', 20000);

		$this->sweepAt('2031-04-11 08:00');

		$this->assertSame(['NF-BW 1: The budget for 2031 is used up'], array_column($this->notifications(self::OWNER), 'subject'));
		$this->assertCount(2, $this->mails(self::OWNER));
	}

	/** A cost past both shares at once tells the higher one only. */
	public function testAJumpPastBothSharesTellsTheHigherOne(): void {
		$vehicle = $this->pool();
		$this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 120000);

		$this->sweepAt('2031-04-01 08:00');
		$this->sweepAt('2031-04-02 08:00');

		$this->assertSame(['NF-BW 1: The budget for 2031 is used up'], array_column($this->notifications(self::OWNER), 'subject'));
		$this->assertCount(1, $this->mails(self::OWNER));
	}

	/** A net budget counts the costs net, whatever the reader's VAT switch. */
	public function testANetBudgetCountsTheCostsNet(): void {
		$vehicle = $this->pool();
		$this->budget($vehicle, 2031, 100000, true);
		// 1 130.00 gross at 19 % is 949.58 net: past 80 %, short of 100 %.
		$this->expense($vehicle, '2031-03-01', 113000);

		$this->sweepAt('2031-04-01 08:00');

		$this->assertSame(['NF-BW 1: 80 % of the budget for 2031 is used'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/** A raise above a share arms it again; the notice it made stale is gone. */
	public function testARaiseArmsTheWarningAgain(): void {
		$vehicle = $this->pool();
		$budget = $this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 85000);
		$this->sweepAt('2031-04-01 08:00');

		$raised = $this->budgets->update(self::OWNER, $vehicle->getUuid(), $budget['uuid'], $budget['updated_at'], ['amount' => 200000, 'net' => false]);

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->expense($vehicle, '2031-05-01', 80000);
		$this->sweepAt('2031-05-02 08:00');
		$this->assertSame(['NF-BW 1: 80 % of the budget for 2031 is used'], array_column($this->notifications(self::OWNER), 'subject'));
		$this->assertCount(2, $this->mails(self::OWNER));

		// A cut arms nothing: 80 % stays told, and 100 % is told once.
		$this->budgets->update(self::OWNER, $vehicle->getUuid(), $budget['uuid'], $raised['updated_at'], ['amount' => 150000, 'net' => false]);
		$this->sweepAt('2031-05-03 08:00');
		$this->assertSame(['NF-BW 1: The budget for 2031 is used up'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	/** A raise that leaves the costs past 80 % tells that share in place of "used up", and mails nothing. */
	public function testARaiseBelowTheCostsLeavesTheShareItStillReaches(): void {
		$vehicle = $this->pool();
		$budget = $this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 95000);
		$this->expense($vehicle, '2031-03-02', 10000);
		$this->sweepAt('2031-04-01 08:00');

		$this->budgets->update(self::OWNER, $vehicle->getUuid(), $budget['uuid'], $budget['updated_at'], ['amount' => 120000, 'net' => false]);
		$this->sweepAt('2031-04-02 08:00');

		foreach ([self::OWNER, self::MANAGER] as $uid) {
			$this->assertSame(['NF-BW 1: 80 % of the budget for 2031 is used'], array_column($this->notifications($uid), 'subject'), $uid);
			$this->assertCount(1, $this->mails($uid), $uid);
		}
	}

	/** The calendar year is the owner's: a cost late on New Year's Eve in UTC is in Berlin's new year. */
	public function testTheYearIsTheOneInTheOwnersZone(): void {
		Server::get(IConfig::class)->setUserValue(self::OWNER, 'core', 'timezone', 'Europe/Berlin');
		$vehicle = $this->pool();
		$this->budget($vehicle, 2031, 100000, false);
		$this->budget($vehicle, 2032, 100000, false);
		$this->expense($vehicle, '2031-12-31 23:30', 90000);

		$this->sweepAt('2031-12-31 23:45');

		$this->assertSame(['NF-BW 1: 80 % of the budget for 2032 is used'], array_column($this->notifications(self::OWNER), 'subject'));
	}

	public function testADeletedBudgetWarnsNoMoreAndTakesItsNoticeBack(): void {
		$vehicle = $this->pool();
		$budget = $this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 85000);
		$this->sweepAt('2031-04-01 08:00');

		$this->budgets->delete(self::OWNER, $vehicle->getUuid(), $budget['uuid'], $budget['updated_at']);
		$this->expense($vehicle, '2031-04-10', 20000);
		$this->sweepAt('2031-04-11 08:00');

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertCount(1, $this->mails(self::OWNER));
	}

	/** The vehicle's mail switch for reminders is the budget's too; the notification still comes. */
	public function testAVehicleThatMailsNoRemindersMailsNoWarning(): void {
		$vehicle = $this->pool(['reminder_mail' => Vehicle::MAIL_OFF]);
		$this->budget($vehicle, 2031, 100000, false);
		$this->expense($vehicle, '2031-03-01', 85000);

		$this->sweepAt('2031-04-01 08:00');

		$this->assertCount(1, $this->notifications(self::OWNER));
		$this->assertSame([], $this->mails(self::OWNER));
	}

	/** @param array<string, mixed> $fields */
	private function pool(array $fields = []): Vehicle {
		$vehicle = Server::get(VehicleService::class)->create(self::OWNER, $fields + ['plate' => 'NF-BW 1', 'currency' => 'EUR', 'costs_shared' => false]);
		foreach ([self::MANAGER => 'manager', self::DRIVER => 'driver'] as $grantee => $role) {
			Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => 'user', 'role' => $role]);
		}

		return $vehicle;
	}

	/** @return NextFleetBudget */
	private function budget(Vehicle $vehicle, int $year, int $amount, bool $net): array {
		return $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => $year, 'amount' => $amount, 'net' => $net]);
	}

	/** A cost at that moment in UTC, gross at 19 %. */
	private function expense(Vehicle $vehicle, string $moment, int $gross): void {
		Server::get(ExpenseService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'spent_at' => (new \DateTimeImmutable($moment, new \DateTimeZone('UTC')))->getTimestamp(),
			'spent_at_off' => 0,
			'category' => 'other',
			'amount' => $gross,
			'vat_rate' => 1900,
		]);
	}

	private function sweepAt(string $moment): void {
		$now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn($now);
		$clock->method('getTime')->willReturn($now->getTimestamp());
		$clock->method('getDateTime')->willReturnCallback(static fn (): \DateTime => \DateTime::createFromImmutable($now));

		(new BudgetWarnings(
			Server::get(BudgetMapper::class),
			Server::get(VehicleMapper::class),
			Server::get(CostService::class),
			Server::get(VehicleAccess::class),
			Server::get(UserZone::class),
			Server::get(MailService::class),
			Server::get(\OCP\Notification\IManager::class),
			Server::get(IUserManager::class),
			$clock,
			Server::get(IDBConnection::class),
			Server::get(LoggerInterface::class),
		))->sweep();
	}

	/** @return list<array<string, mixed>> the user's budget notices, over OCS: a grant tells the manager too */
	private function notifications(string $uid): array {
		$response = Server::get(IClientService::class)->newClient()->get(
			Endpoints::server() . '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
			[
				'auth' => [$uid, self::$password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$data = json_decode((string)$response->getBody(), true)['ocs']['data'];

		return array_values(array_filter($data, static fn (array $one): bool => $one['app'] === Application::APP_ID && $one['object_type'] === BudgetWarnings::OBJECT));
	}

	/** @return list<array{Subject: string, Text: string}> what reached one address, newest first */
	private function mails(string $uid): array {
		$list = $this->mailpit('GET', '/search?query=' . rawurlencode('to:' . $uid . '@example.org'));

		return array_map(fn (array $one): array => $this->mailpit('GET', '/message/' . $one['ID']), $list['messages']);
	}

	private function mailpit(string $method, string $path): array {
		$response = Server::get(IClientService::class)->newClient()->request($method, Endpoints::mailpit() . $path, [
			'nextcloud' => ['allow_local_address' => true],
		]);

		return json_decode((string)$response->getBody(), true) ?? [];
	}
}
