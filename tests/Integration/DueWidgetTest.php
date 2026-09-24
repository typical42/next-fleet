<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Dashboard\DueWidget;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard widget against the real database: the fleet's open reminders, most urgent first.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class DueWidgetTest extends TestCase {
	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const MANAGER = 'nextfleet-test-dave';
	private const STRANGER = 'nextfleet-test-bob';
	/** A real account, for the one case that goes through the dashboard's own route. */
	private const ACCOUNT = 'nextfleet-test-dashboard';

	private DueWidget $widget;
	private VehicleService $vehicles;
	private ReminderService $reminders;

	protected function setUp(): void {
		// The router links only to a loaded app's routes; the CLI has not loaded it.
		\OCP\Server::get(IAppManager::class)->loadApp(Application::APP_ID);
		$container = (new Application())->getContainer();
		$this->widget = $container->get(DueWidget::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->reminders = $container->get(ReminderService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** Rows first: deleting an account pseudonymises them, and they would leak. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::STRANGER, self::ACCOUNT];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_reminders' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		\OCP\Server::get(IUserManager::class)->get(self::ACCOUNT)?->delete();
	}

	/**
	 * Across every vehicle the reader may see, ranked as the overview ranks them
	 * (src/utils/reminders.js `openByUrgency`): by state, then by the sooner day. A dismissed one is
	 * over and not listed. The state is a word as well as a colour.
	 */
	public function testItListsTheOpenRemindersMostUrgentFirst(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$shared = $this->vehicles->create(self::MANAGER, ['plate' => 'B-XY 456']);
		$this->grant($shared, self::OWNER, 'viewer');
		$this->remind($mine, ['title' => 'Wax', 'due_date' => '2036-01-01']);
		$this->remind($mine, ['title' => 'Polish', 'due_date' => '2035-01-01']);
		$this->remind($shared, ['title' => 'Wash', 'due_date' => $this->day('+10 days'), 'warn_month_before' => true], self::MANAGER);
		$this->remind($shared, ['title' => 'Oil', 'due_date' => $this->day('-1 day')], self::MANAGER);
		$this->remind($mine, ['template_key' => 'tyre_swap', 'mode' => Reminder::DATE, 'due_date' => $this->day('+0 days'), 'recur_months' => null]);
		$gone = $this->remind($mine, ['title' => 'Gone', 'due_date' => '2034-01-01']);
		$this->reminders->dismiss(self::OWNER, $mine->getUuid(), $gone['uuid'], $gone['updated_at']);

		$items = $this->items(self::OWNER);

		$this->assertSame(
			['B-XY 456: Oil', 'B-XY 123: Tyre swap', 'B-XY 456: Wash', 'B-XY 123: Polish', 'B-XY 123: Wax'],
			array_column($items, 'title'),
		);
		$this->assertSame(['Overdue', 'Due', 'Coming up', 'Planned', 'Planned'], array_column($items, 'subtitle'));
		$this->assertSame(
			['red', 'red', 'amber', 'green', 'green'],
			array_map(static fn (string $url): string => (string)preg_replace('/^.*\/light-(\w+)\.svg$/', '$1', $url), array_column($items, 'iconUrl')),
		);
		$this->assertStringEndsWith('/apps/nextfleet/?vehicle=' . $shared->getUuid(), $items[0]['link']);
	}

	/** The dashboard asks for a few; the most urgent are the ones it gets. */
	public function testTheLimitKeepsTheMostUrgent(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->remind($mine, ['title' => 'Wax', 'due_date' => '2036-01-01']);
		$this->remind($mine, ['title' => 'Oil', 'due_date' => $this->day('-1 day')]);

		$this->assertSame(['B-XY 123: Oil'], array_column($this->items(self::OWNER, 1), 'title'));
	}

	/** Named as the screen names it (src/utils/format.js `nameOf`): the make when there is no plate. */
	public function testAVehicleWithoutAPlateIsNamedByItsMake(): void {
		$golf = $this->vehicles->create(self::OWNER, ['manufacturer' => 'Volkswagen', 'model' => 'Golf']);
		$this->remind($golf, ['title' => 'Oil', 'due_date' => $this->day('-1 day')]);

		$this->assertSame(['Volkswagen Golf: Oil'], array_column($this->items(self::OWNER), 'title'));
	}

	/** Someone else's vehicle stays out, and an empty list says so. */
	public function testNobodyElsesRemindersAppear(): void {
		$theirs = $this->vehicles->create(self::MANAGER, ['plate' => 'B-XY 456']);
		$this->remind($theirs, ['title' => 'Oil', 'due_date' => $this->day('-1 day')], self::MANAGER);

		$answer = $this->widget->getItemsV2(self::STRANGER);

		$this->assertSame([], $answer->getItems());
		$this->assertSame('Nothing due', $answer->getEmptyContentMessage());
	}

	/** A sold vehicle has left the overview, and its reminders the dashboard with it. */
	public function testADisposedVehicleIsNotListed(): void {
		$sold = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 789']);
		$this->remind($sold, ['title' => 'Oil', 'due_date' => $this->day('-1 day')]);
		$this->vehicles->update(self::OWNER, $sold->getUuid(), $sold->getUpdatedAt(), ['lifecycle' => 'disposed', 'disposed_at' => '2025-01-01']);

		$this->assertSame([], $this->items(self::OWNER));
	}

	/** Registered with the server: the dashboard's own route serves the widget's items. */
	public function testTheDashboardServesTheWidget(): void {
		$password = bin2hex(random_bytes(16));
		\OCP\Server::get(IUserManager::class)->createUser(self::ACCOUNT, $password);
		$mine = $this->vehicles->create(self::ACCOUNT, ['plate' => 'B-XY 123']);
		$this->remind($mine, ['title' => 'Oil', 'due_date' => $this->day('-1 day')], self::ACCOUNT);

		$response = \OCP\Server::get(IClientService::class)->newClient()->get(
			'http://localhost/ocs/v2.php/apps/dashboard/api/v2/widget-items?format=json&widgets[]=nextfleet',
			[
				'auth' => [self::ACCOUNT, $password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$items = json_decode((string)$response->getBody(), true)['ocs']['data']['nextfleet']['items'];

		$this->assertSame(['B-XY 123: Oil'], array_column($items, 'title'));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function remind(Vehicle $vehicle, array $fields, string $userId = self::OWNER): array {
		return $this->reminders->create($userId, $vehicle->getUuid(), $fields + ['mode' => Reminder::DATE]);
	}

	private function day(string $modifier): string {
		return \OCP\Server::get(ITimeFactory::class)->now()->modify($modifier)->format('Y-m-d');
	}

	/** One grant, as the sharing UI will write it (M6). */
	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy($vehicle->getUserId());

		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function items(string $userId, int $limit = 7): array {
		/** @var list<array<string, mixed>> */
		return json_decode(json_encode($this->widget->getItemsV2($userId, null, $limit)->getItems(), JSON_THROW_ON_ERROR), true);
	}
}
