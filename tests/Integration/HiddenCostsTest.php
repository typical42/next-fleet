<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\ForgetMemberJob;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\SyncService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle whose owner keeps its costs from drivers and viewers, read through every door a cost
 * leaves by, against the real database and real accounts (docs/api.md#costs).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class HiddenCostsTest extends TestCase {
	use Accounts;
	use RunsQueuedJobs;

	private const OWNER = 'nextfleet-test-costs-owner';
	private const MANAGER = 'nextfleet-test-costs-manager';
	private const DRIVER = 'nextfleet-test-costs-driver';
	private const VIEWER = 'nextfleet-test-costs-viewer';
	private const ACCOUNTS = [self::OWNER, self::MANAGER, self::DRIVER, self::VIEWER];
	private const CREW = 'nextfleet-test-costs-crew';

	private const TABLES = ['fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_reminder_recipients', 'fleet_access'];

	/** 15 June 2025, in Berlin's summer. */
	private const AT = 1750000000;

	private VehicleService $vehicles;
	/** @var list<int> */
	private array $vehicleIds = [];

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(self::ACCOUNTS);
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(self::ACCOUNTS);
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
	}

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	protected function tearDown(): void {
		if ($this->vehicleIds === []) {
			return;
		}
		$db = \OCP\Server::get(IDBConnection::class);
		foreach ([...self::TABLES, 'fleet_vehicles'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($table === 'fleet_vehicles' ? 'id' : 'vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_audit')->where($qb->expr()->in('created_by', $qb->createNamedParameter(self::ACCOUNTS, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		$this->vehicleIds = [];
	}

	/** A vehicle starts shared, so nothing changes for one that was there before the switch. */
	public function testAVehicleSharesItsCostsUntilTheOwnerSaysOtherwise(): void {
		$vehicle = $this->vehicle(shared: true);

		$found = $this->vehicles->find(self::DRIVER, $vehicle->getUuid())->jsonSerialize();

		$this->assertTrue($found['costs_shared']);
		$this->assertContains('costs', $found['may']);
		$this->assertSame(3200000, $found['purchase_price']);
	}

	/**
	 * What each role reads of the vehicle with the switch off: the owner and the manager all of
	 * it, the driver and the viewer no price. The list says what a single read says.
	 */
	public function testTheOwnerAndTheManagerSeeTheCostsAndTheOthersDoNot(): void {
		$vehicle = $this->vehicle(shared: false);

		foreach ([self::OWNER => true, self::MANAGER => true, self::DRIVER => false, self::VIEWER => false] as $uid => $sees) {
			$found = $this->vehicles->find($uid, $vehicle->getUuid())->jsonSerialize();
			$listed = $this->listed($uid, $vehicle);
			foreach ([$found, $listed] as $answer) {
				$this->assertSame($sees, in_array('costs', $answer['may'], true), $uid);
				$this->assertSame($sees ? 3200000 : null, $answer['purchase_price'], $uid);
				$this->assertSame($sees ? 1200000 : null, $answer['residual_est'], $uid);
				$this->assertFalse($answer['costs_shared']);
			}
		}
	}

	/**
	 * Only the owner flips the switch. A manager's sheet sends it back as it was and saves; a
	 * manager who changes it is refused, and the row keeps it. A flip starts every sync over.
	 */
	public function testOnlyTheOwnerFlipsTheSwitch(): void {
		$vehicle = $this->vehicle(shared: true);
		$config = \OCP\Server::get(IAppConfig::class);
		$epoch = $config->getValueString(Application::APP_ID, SyncService::EPOCH);

		$kept = $this->vehicles->update(self::MANAGER, $vehicle->getUuid(), $this->tokenOf($vehicle), ['plate' => 'C-ST 2', 'costs_shared' => true]);
		$this->assertSame('C-ST 2', $kept->getPlate());
		$this->assertSame($epoch, $config->getValueString(Application::APP_ID, SyncService::EPOCH));

		try {
			$this->vehicles->update(self::MANAGER, $vehicle->getUuid(), $this->tokenOf($vehicle), ['costs_shared' => false]);
			$this->fail('a manager flipped the switch');
		} catch (AccessDeniedException) {
		}
		$this->assertTrue($this->vehicles->find(self::OWNER, $vehicle->getUuid())->sharesCosts());

		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $this->tokenOf($vehicle), ['costs_shared' => false]);
		$this->assertFalse($this->vehicles->find(self::OWNER, $vehicle->getUuid())->sharesCosts());
		$this->assertNotSame($epoch, $config->getValueString(Application::APP_ID, SyncService::EPOCH));
	}

	/**
	 * On a vehicle that keeps its costs, a new role changes which amounts of rows already synced
	 * the grantee may read, and none of those rows changed: every sync starts over. On one that
	 * shares them, every role reads the same, and no sync is disturbed.
	 */
	public function testANewRoleOnAVehicleThatKeepsItsCostsStartsEverySyncOver(): void {
		$config = \OCP\Server::get(IAppConfig::class);
		$grants = \OCP\Server::get(GrantService::class);
		$epoch = static fn (): string => $config->getValueString(Application::APP_ID, SyncService::EPOCH);
		$driverGrant = static fn (Vehicle $vehicle): string => (string)array_column($grants->list(self::OWNER, $vehicle->getUuid()), 'uuid', 'grantee')[self::DRIVER];

		$shared = $this->vehicle(shared: true);
		$before = $epoch();
		$grants->change(self::OWNER, $shared->getUuid(), $driverGrant($shared), ['role' => 'manager']);
		$this->assertSame($before, $epoch());

		$kept = $this->vehicle(shared: false);
		foreach ([
			'a change' => fn () => $grants->change(self::OWNER, $kept->getUuid(), $driverGrant($kept), ['role' => 'manager']),
			'a grant that recasts' => fn () => $grants->grant(self::OWNER, $kept->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']),
			'a revoke' => fn () => $grants->revoke(self::OWNER, $kept->getUuid(), $driverGrant($kept)),
		] as $what => $step) {
			$before = $epoch();
			$step();
			$this->assertNotSame($before, $epoch(), $what);
		}
	}

	/**
	 * A role lost with a group is a role lost all the same: a member taken out of the group, and
	 * the group deleted, each start every sync over on a vehicle that keeps its costs.
	 */
	public function testARoleLostWithAGroupStartsEverySyncOver(): void {
		$config = \OCP\Server::get(IAppConfig::class);
		$epoch = static fn (): string => $config->getValueString(Application::APP_ID, SyncService::EPOCH);
		$groups = \OCP\Server::get(IGroupManager::class);
		$viewer = \OCP\Server::get(IUserManager::class)->get(self::VIEWER) ?? throw new \RuntimeException('no ' . self::VIEWER);
		$crew = $groups->createGroup(self::CREW) ?? throw new \RuntimeException('no ' . self::CREW);
		$crew->addUser($viewer);
		$kept = $this->vehicle(shared: false);
		\OCP\Server::get(GrantService::class)->grant(self::OWNER, $kept->getUuid(), ['grantee' => self::CREW, 'grantee_type' => 'group', 'role' => 'manager']);

		$before = $epoch();
		$crew->removeUser($viewer);
		self::runQueued(ForgetMemberJob::class);
		$this->assertNotSame($before, $epoch(), 'a member taken out');

		$before = $epoch();
		$crew->delete();
		$this->assertNotSame($before, $epoch(), 'the group deleted');
	}

	/**
	 * The timeline: a driver reads every row, but no amount somebody else entered and no expense
	 * of somebody else's; their own whole. The manager reads it all. A single read keeps the page's rule.
	 */
	public function testTheTimelineKeepsOthersAmountsFromADriver(): void {
		$vehicle = $this->vehicle(shared: false);
		$rows = $this->costs($vehicle);
		$timeline = \OCP\Server::get(TimelineService::class);

		$driver = $this->byUuid($timeline->page(self::DRIVER, $vehicle->getUuid(), null, null)['rows']);
		$this->assertArrayNotHasKey($rows['their expense'], $driver);
		$this->assertSame(900, $driver[$rows['my expense']]['expense']->getAmount());
		$this->assertSame([null, null], [$driver[$rows['their fill-up']]['energy']->getUnitPrice(), $driver[$rows['their fill-up']]['energy']->getTotal()]);
		$this->assertSame(42000, $driver[$rows['their fill-up']]['energy']->getAmount());
		$this->assertSame(6100, $driver[$rows['my fill-up']]['energy']->getTotal());
		$this->assertNull($driver[$rows['their record']]['maintenance']->getCost());

		$manager = $this->byUuid($timeline->page(self::MANAGER, $vehicle->getUuid(), null, null)['rows']);
		$this->assertSame(1600, $manager[$rows['their expense']]['expense']->getAmount());
		$this->assertSame(7350, $manager[$rows['their fill-up']]['energy']->getTotal());
		$this->assertSame(42000, $manager[$rows['their record']]['maintenance']->getCost());

		// The chip asks the same narrowed query.
		$chip = $timeline->page(self::DRIVER, $vehicle->getUuid(), TimelineService::EXPENSE, null)['rows'];
		$this->assertSame([$rows['my expense']], array_map(static fn (array $row): string => $row['expense']->getUuid(), $chip));

		$this->assertNull($timeline->one(self::DRIVER, $vehicle->getUuid(), TimelineService::ENERGY, $rows['their fill-up'])['energy']->getTotal());
		$this->expectException(DoesNotExistException::class);
		$timeline->one(self::DRIVER, $vehicle->getUuid(), TimelineService::EXPENSE, $rows['their expense']);
	}

	/** The header and the year: what the car used stays, every sum goes. */
	public function testTheFiguresCarryNoSumForADriver(): void {
		$vehicle = $this->vehicle(shared: false);
		$this->costs($vehicle);
		$kpis = \OCP\Server::get(KpiService::class);
		$period = ['from' => self::AT - 86400, 'to' => self::AT + 86400];

		$this->assertSame(57950, $kpis->of(self::MANAGER, $vehicle->getUuid(), $period)['cost']['total']);
		$figures = $kpis->of(self::DRIVER, $vehicle->getUuid(), $period);
		$this->assertNull($figures['cost']['total']);
		$this->assertNull($figures['cost']['expenses']);
		$this->assertSame('EUR', $figures['cost']['currency']);

		$year = $kpis->year(self::VIEWER, $vehicle->getUuid(), '2025', ['tz' => 'Europe/Berlin']);
		$this->assertNull($year['year']['cost']['total']);
		$this->assertSame([], array_values(array_filter(array_column(array_column($year['months'], 'cost'), 'total'), static fn (?int $total): bool => $total !== null)));
	}

	/** The CSV files keep what the timeline keeps. */
	public function testTheCsvFilesKeepOthersAmountsFromADriver(): void {
		$vehicle = $this->vehicle(shared: false);
		$rows = $this->costs($vehicle);
		$export = \OCP\Server::get(ExportService::class);

		$expenses = $export->csv(self::DRIVER, $vehicle->getUuid(), 2025, 'expenses')['body'];
		$this->assertStringContainsString($rows['my expense'], $expenses);
		$this->assertStringNotContainsString($rows['their expense'], $expenses);
		$this->assertStringContainsString($rows['their expense'], $export->csv(self::MANAGER, $vehicle->getUuid(), 2025, 'expenses')['body']);

		$energy = $export->csv(self::DRIVER, $vehicle->getUuid(), 2025, 'energy')['body'];
		$this->assertStringNotContainsString('7350', $energy);
		$this->assertStringContainsString('6100', $energy);
		$this->assertStringNotContainsString('42000,EUR', $export->csv(self::DRIVER, $vehicle->getUuid(), 2025, 'maintenance')['body']);
	}

	/** Sync keeps what the timeline keeps, and the vehicle it lists carries no price. */
	public function testSyncKeepsOthersAmountsFromADriver(): void {
		$vehicle = $this->vehicle(shared: false);
		$rows = $this->costs($vehicle);

		$answer = \OCP\Server::get(SyncService::class)->sync(self::DRIVER, '', 500);

		$uuids = static fn (string $table): array => array_column($answer['changes'][$table], 'row', 'uuid');
		$this->assertSame([$rows['my expense']], array_keys($uuids('expenses')));
		$this->assertNull($uuids('energy')[$rows['their fill-up']]['total']);
		$this->assertSame(6100, $uuids('energy')[$rows['my fill-up']]['total']);
		$this->assertNull($uuids('maintenance')[$rows['their record']]['cost']);
		$this->assertNull(array_column($answer['vehicles'], 'purchase_price', 'uuid')[$vehicle->getUuid()]);
	}

	/** The station prices the fill-up sheet offers are other fill-ups' costs too. */
	public function testTheFillUpSheetOffersADriverNoPriceOthersPaid(): void {
		$vehicle = $this->vehicle(shared: false);
		$this->costs($vehicle);
		$energy = \OCP\Server::get(EnergyService::class);
		$moment = ['at' => self::AT, 'off' => 120];

		$prices = static fn (array $prefill): array => array_column($prefill['stations'], 'unit_price', 'station');
		$this->assertSame(['Shell' => 1650, 'Aral' => null], $prices($energy->prefill(self::DRIVER, $vehicle->getUuid(), $moment)));
		$this->assertSame(['Shell' => 1650, 'Aral' => 1750], $prices($energy->prefill(self::MANAGER, $vehicle->getUuid(), $moment)));
	}

	/**
	 * A retried create answers the row its `client_uuid` names. Naming somebody else's is no way
	 * round the rule: a fill-up or record comes back without its amounts, an expense not at all.
	 */
	public function testARetriedCreateHandsADriverNoAmountOthersEntered(): void {
		$vehicle = $this->vehicle(shared: false);
		$rows = $this->costs($vehicle);
		$fill = ['filled_at' => self::AT, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 1000, 'client_uuid' => $rows['their fill-up']];
		$record = ['done_at' => self::AT, 'done_at_off' => 120, 'title' => 'Wipers', 'client_uuid' => $rows['their record']];

		$this->assertNull($this->replayed(fn () => \OCP\Server::get(EnergyService::class)->record(self::DRIVER, $vehicle->getUuid(), $fill))['total']);
		$this->assertNull($this->replayed(fn () => \OCP\Server::get(MaintenanceService::class)->record(self::DRIVER, $vehicle->getUuid(), $record))['cost']);
		// Refused as every row this create would not have written is: whose it is stays unsaid.
		$this->expectException(\InvalidArgumentException::class);
		\OCP\Server::get(ExpenseService::class)->record(self::DRIVER, $vehicle->getUuid(), ['spent_at' => self::AT, 'spent_at_off' => 120, 'amount' => 1, 'client_uuid' => $rows['their expense']]);
	}

	/** The mileage claim puts money on the trips, so it is the costs' too. */
	public function testTheMileageClaimIsRefusedToADriverTheCostsAreKeptFrom(): void {
		$vehicle = $this->vehicle(shared: false);
		$claim = \OCP\Server::get(MileageClaimExport::class);
		\OCP\Server::get(TripService::class)->record(self::DRIVER, $vehicle->getUuid(), [
			'started_at' => self::AT, 'started_at_off' => 120, 'ended_at' => self::AT + 3600, 'ended_at_off' => 120,
			'start_odo' => 1000, 'end_odo' => 1040, 'category' => Trip::BUSINESS, 'partner' => 'ACME',
		]);

		$this->assertNotNull($claim->year(self::MANAGER, $vehicle->getUuid(), 2025));
		$this->expectException(AccessDeniedException::class);
		$claim->year(self::DRIVER, $vehicle->getUuid(), 2025);
	}

	/** A German vehicle, priced, with a manager, a driver and a viewer. */
	private function vehicle(bool $shared): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => 'C-ST 1', 'jurisdiction' => 'de', 'currency' => 'EUR', 'energy_types' => ['diesel'],
			'purchase_price' => 3200000, 'residual_est' => 1200000, 'costs_shared' => $shared,
		]);
		$this->vehicleIds[] = (int)$vehicle->getId();
		$grants = \OCP\Server::get(GrantService::class);
		foreach ([self::MANAGER => 'manager', self::DRIVER => 'driver', self::VIEWER => 'viewer'] as $uid => $role) {
			$grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $uid, 'grantee_type' => 'user', 'role' => $role]);
		}

		return $vehicle;
	}

	/**
	 * One fill-up, record and expense the owner entered, and a fill-up and expense the driver
	 * entered, all on one day.
	 *
	 * @return array<string, string> uuids by what they are
	 */
	private function costs(Vehicle $vehicle): array {
		$energy = \OCP\Server::get(EnergyService::class);
		$expenses = \OCP\Server::get(ExpenseService::class);
		$fill = ['filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'full_tank' => true];

		return [
			'their fill-up' => (string)$energy->record(self::OWNER, $vehicle->getUuid(), $fill + ['filled_at' => self::AT, 'unit_price' => 1750, 'total' => 7350, 'station' => 'Aral'])['uuid'],
			'my fill-up' => (string)$energy->record(self::DRIVER, $vehicle->getUuid(), $fill + ['filled_at' => self::AT + 60, 'unit_price' => 1650, 'total' => 6100, 'station' => 'Shell'])['uuid'],
			'their record' => (string)\OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $vehicle->getUuid(), ['done_at' => self::AT + 120, 'done_at_off' => 120, 'title' => 'Brakes', 'cost' => 42000])['uuid'],
			'their expense' => (string)$expenses->record(self::OWNER, $vehicle->getUuid(), ['spent_at' => self::AT + 180, 'spent_at_off' => 120, 'amount' => 1600, 'category' => 'parking'])['uuid'],
			'my expense' => (string)$expenses->record(self::DRIVER, $vehicle->getUuid(), ['spent_at' => self::AT + 240, 'spent_at_off' => 120, 'amount' => 900, 'category' => 'parking'])['uuid'],
		];
	}

	/**
	 * @param \Closure(): mixed $create a create that replays an earlier row
	 * @return array<string, mixed> what the replay answers
	 */
	private function replayed(\Closure $create): array {
		try {
			$create();
		} catch (AlreadyCreatedException $replay) {
			return $replay->answer;
		}
		$this->fail('the create wrote a row of its own');
	}

	/** @return array<string, mixed> the vehicle as the list answers it */
	private function listed(string $uid, Vehicle $vehicle): array {
		foreach ($this->vehicles->list($uid) as $one) {
			if ($one->getUuid() === $vehicle->getUuid()) {
				return $one->jsonSerialize();
			}
		}
		$this->fail($uid . ' does not list the vehicle');
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, array<string, mixed>>
	 */
	private function byUuid(array $rows): array {
		$by = [];
		foreach ($rows as $row) {
			$by[$row[$row['type']]->getUuid()] = $row;
		}

		return $by;
	}

	private function tokenOf(Vehicle $vehicle): int {
		return $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getUpdatedAt();
	}
}
