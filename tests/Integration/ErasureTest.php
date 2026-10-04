<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Types\Type;
use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\PendingJob;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Repair\ErasedPseudonyms;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\LookalikeNotices;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\Pending;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\SyncEpoch;
use OCA\NextFleet\Service\SyncService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Notification\IManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deleting a Nextcloud account pseudonymises its rows and deletes none
 * (docs/adr/0008-erasing-a-driver-pseudonymises.md). The account is real and is deleted the way
 * an admin deletes it, so the listener is reached through the server's own event.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ErasureTest extends TestCase {
	use CountsQueries;

	private const OWNER = 'nextfleet-test-erase-owner';
	private const DRIVER = 'nextfleet-test-erase-driver';
	/** No account: an erasure is asked for by uid, and a vehicle's `user_id` is a string column. */
	private const GHOST = 'nextfleet-test-erase-ghost';

	/** Every column that names an account, by table. */
	private const PEOPLE_COLUMNS = [
		'fleet_vehicles' => ['user_id', 'created_by'],
		'fleet_odo_readings' => ['created_by'],
		'fleet_trips' => ['created_by'],
		'fleet_energy' => ['created_by'],
		'fleet_maintenance' => ['created_by'],
		'fleet_expenses' => ['created_by'],
		'fleet_reminders' => ['created_by'],
		'fleet_reminder_receipts' => ['user_id', 'created_by'],
		'fleet_reminder_recipients' => ['user_id', 'created_by'],
		'fleet_documents' => ['created_by'],
		'fleet_audit' => ['created_by'],
		'fleet_access' => ['grantee', 'created_by'],
		'fleet_bookings' => ['user_id', 'created_by'],
	];

	private VehicleService $vehicles;
	private TripService $trips;
	private ExpenseService $expenses;
	private RecipientService $recipients;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->expenses = \OCP\Server::get(ExpenseService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
		$this->forget();

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/**
	 * The accounts and the rows this suite invents, gone for real. Rows go first and by vehicle:
	 * once an account is deleted its uid is on none of them.
	 */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (array_keys(self::PEOPLE_COLUMNS) as $table) {
			if ($this->vehicleIds === [] || in_array($table, ['fleet_vehicles', 'fleet_audit', 'fleet_reminder_receipts'], true)) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		if ($this->vehicleIds !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/** Done when: deleting a Nextcloud user pseudonymises their rows and deletes none. */
	public function testADeletedDriversRowsStayUnderOnePseudonym(): void {
		$shared = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->grant($shared, self::DRIVER, 'manager');
		$trip = $this->trip(self::DRIVER, $shared);
		$expense = $this->expenses->record(self::DRIVER, $shared->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400]);
		$booking = $this->takenBooking(self::DRIVER, $shared, 'Scratch on the tailgate');
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		$before = $this->rowCounts();

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		// Their own vehicle listed them as its recipient, and that entry is the one that goes.
		$before['fleet_reminder_recipients']--;
		$this->assertSame($before, $this->rowCounts(), 'a row was deleted');
		$this->assertSame([], $this->rowsNaming(self::DRIVER));
		$pseudonyms = array_unique([
			$this->column('fleet_trips', 'created_by', $trip->getId()),
			$this->column('fleet_expenses', 'created_by', $this->idOf('fleet_expenses', (string)$expense['uuid'])),
			$this->column('fleet_vehicles', 'user_id', $own->getId()),
			$this->column('fleet_vehicles', 'created_by', $own->getId()),
			$this->granteeOn($shared),
			$this->column('fleet_bookings', 'user_id', $this->idOf('fleet_bookings', $booking)),
			$this->column('fleet_bookings', 'created_by', $this->idOf('fleet_bookings', $booking)),
		]);
		$this->assertCount(1, $pseudonyms, 'one erasure, one pseudonym');
		$this->assertNotSame('', $pseudonyms[0]);
		$this->assertStringNotContainsString(self::DRIVER, $pseudonyms[0]);
		$this->assertSame(self::OWNER, $this->column('fleet_vehicles', 'user_id', $shared->getId()));
		// The handover note is the owner's record of their car, as a trip is.
		$this->assertSame('Scratch on the tailgate', $this->column('fleet_bookings', 'out_notes', $this->idOf('fleet_bookings', $booking)));
	}

	/**
	 * A driver who used every part of the app is named in every column that can name an account,
	 * and after the erasure in none. The fixture is checked first, so a column it stopped reaching
	 * fails here rather than passing unvisited.
	 */
	public function testADriverNamedInEveryColumnIsNamedInNoneAfterward(): void {
		$shared = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'engine' => 'diesel', 'logbook_mode' => true]);
		$this->vehicleIds[] = (int)$shared->getId();
		$this->grant($shared, self::DRIVER, 'manager');
		$uuid = $shared->getUuid();
		// Under the mode, so the trip leaves an audit row by the driver.
		$this->trip(self::DRIVER, $shared);
		\OCP\Server::get(EnergyService::class)->record(self::DRIVER, $uuid, ['filled_at' => 1750100000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'odo' => 12100]);
		\OCP\Server::get(MaintenanceService::class)->record(self::DRIVER, $uuid, ['done_at' => 1750200000, 'done_at_off' => 120, 'title' => 'Oil change']);
		$this->expenses->record(self::DRIVER, $uuid, ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400]);
		$reminder = \OCP\Server::get(ReminderService::class)->create(self::DRIVER, $uuid, ['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 20000]);
		// Sent to the driver: a receipt names its recipient.
		\OCP\Server::get(ReminderReceiptMapper::class)->claim(\OCP\Server::get(ReminderMapper::class)->findAnyByUuid((string)$reminder['uuid']), 'due_date', 'app', self::DRIVER, time());
		$paper = new Document();
		$paper->setVehicleId((int)$shared->getId());
		$paper->setFileId(1);
		$paper->setKind('receipt');
		$paper->setCreatedBy(self::DRIVER);
		\OCP\Server::get(DocumentMapper::class)->insert($paper);
		$this->takenBooking(self::DRIVER, $shared, 'Scratch on the tailgate');
		// Their own car lists them as its recipient, and they give somebody access to it.
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		\OCP\Server::get(GrantService::class)->grant(self::DRIVER, $own->getUuid(), ['grantee' => self::OWNER, 'grantee_type' => 'user', 'role' => 'viewer']);
		$every = [];
		foreach (self::PEOPLE_COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				$every[] = $table . '.' . $column;
			}
		}
		$this->assertSame($every, $this->rowsNaming(self::DRIVER), 'the fixture misses a column');

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		$this->assertSame([], $this->rowsNaming(self::DRIVER));
	}

	/**
	 * PEOPLE_COLUMNS is what the erasure tests look at, so a column that names an account and is
	 * missing from it is one no test sees erased. Account-shaped is how the schema keeps a uid,
	 * `string(64)`, or a name that says whose; two columns are that long for another reason.
	 */
	public function testEveryColumnThatCanNameAnAccountIsOneThisSuiteChecks(): void {
		$notAccounts = ['fleet_vehicles.manufacturer', 'fleet_vehicles.model'];
		$prefix = \OCP\Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$found = [];
		foreach (\OCP\Server::get(IDBConnection::class)->createSchema()->getTables() as $table) {
			if (!str_starts_with($table->getName(), $prefix . 'fleet_')) {
				continue;
			}
			$name = substr($table->getName(), strlen($prefix));
			foreach ($table->getColumns() as $column) {
				$shaped = Type::lookupName($column->getType()) === Types::STRING && $column->getLength() === 64;
				$named = (bool)preg_match('/(^|_)(by|user|uid|grantee|owner)(_id)?$/', $column->getName());
				if (($shaped || $named) && !in_array($name . '.' . $column->getName(), $notAccounts, true)) {
					$found[$name][] = $column->getName();
				}
			}
		}
		$expected = array_map(static function (array $columns): array {
			sort($columns);
			return $columns;
		}, self::PEOPLE_COLUMNS);
		$found = array_map(static function (array $columns): array {
			sort($columns);
			return $columns;
		}, $found);
		ksort($expected);
		ksort($found);

		$this->assertSame($expected, $found);
	}

	/**
	 * Most accounts never used the app: erasing one asks each table once whether it names the
	 * uid, and writes nothing.
	 */
	public function testErasingAnAccountNamedNowhereOnlyAsksEachTable(): void {
		$tables = count(\OCP\Server::get(AccountTables::class)->all());
		$config = \OCP\Server::get(IAppConfig::class);
		$epoch = $config->getValueString(Application::APP_ID, SyncService::EPOCH);
		$erasure = \OCP\Server::get(ErasureService::class);

		$queries = self::queriesOf(fn () => $erasure->erase(self::GHOST));

		// Beside the probes: the vehicles it owns and the lists it is on, to hold first; the
		// pending marker, set and removed; the audit's transfer rows.
		$this->assertLessThanOrEqual($tables + 5, $queries);
		$this->assertSame($epoch, $config->getValueString(Application::APP_ID, SyncService::EPOCH));
	}

	/** A deleted account receives nothing, so it leaves every reminder list. */
	public function testADeletedAccountLeavesTheRecipientLists(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::DRIVER);

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		$this->assertSame([self::OWNER], array_column($this->recipients->list(self::OWNER, $vehicle->getUuid()), 'user_id'));
	}

	/**
	 * Nobody owns an erased owner's vehicle, so nobody may keep using it: it closes, and every grant
	 * on it is revoked by revoking's rules. The rows stay for the retention docs/legal.md states.
	 */
	public function testAnErasedOwnersVehiclesCloseWithEveryGrantOnThem(): void {
		// Without the notifications app booted a notification has nowhere to go (ReminderJobTest).
		\OCP\Server::get(IAppManager::class)->loadApps();
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		[$grant] = \OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::DRIVER);
		$this->trip(self::DRIVER, $vehicle);
		$this->assertSame(1, $this->noticesOf($grant['uuid']));
		$before = $this->rowCounts();

		\OCP\Server::get(IUserManager::class)->get(self::OWNER)?->delete();

		// The owner's own entry goes with the account, the driver's with the car they no longer see.
		$before['fleet_reminder_recipients'] -= 2;
		$this->assertSame($before, $this->rowCounts(), 'a row was deleted');
		$this->assertNotSame('', $this->column('fleet_vehicles', 'deleted_at', $vehicle->getId()));
		$this->assertNotSame('', $this->column('fleet_access', 'deleted_at', $this->idOf('fleet_access', $grant['uuid'])));
		$this->assertSame([], \OCP\Server::get(VehicleAccess::class)->reachable(self::DRIVER));
		$this->assertSame(0, $this->noticesOf($grant['uuid']), 'the grant\'s notice is litter now');
	}

	/** A vehicle in the trash loses its grants too: nobody is left to restore it, and an undo would bring them back. */
	public function testAnErasedOwnersVehicleInTheTrashLosesItsGrants(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		[$grant] = \OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$vehicle = $this->vehicles->find(self::OWNER, $vehicle->getUuid());
		$deleted = $this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		\OCP\Server::get(IUserManager::class)->get(self::OWNER)?->delete();

		$this->assertNotSame('', $this->column('fleet_access', 'deleted_at', $this->idOf('fleet_access', $grant['uuid'])));
		$this->assertSame((string)$deleted->getDeletedAt(), $this->column('fleet_vehicles', 'deleted_at', $vehicle->getId()), 'deleted once');
	}

	/** Nextcloud lets a uid be taken again; whoever takes it inherits nothing of the old account. */
	public function testAnAccountRecreatedUnderTheSameUidReachesNothing(): void {
		$shared = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->grant($shared, self::DRIVER, 'manager');
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');

		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::DRIVER)?->delete();
		$users->createUser(self::DRIVER, bin2hex(random_bytes(16)));

		$access = \OCP\Server::get(VehicleAccess::class);
		foreach ([$shared, $own] as $vehicle) {
			$fresh = \OCP\Server::get(VehicleMapper::class)->findAnyByUuid($vehicle->getUuid());
			$this->assertFalse($access->may(self::DRIVER, VehicleAccess::VIEW, $fresh));
		}
		$this->assertSame([], $access->reachable(self::DRIVER));
	}

	/** `:` is outside what Nextcloud allows in a uid, so no account made later can claim the rows. */
	public function testThePseudonymIsNoUidAnAccountCouldTake(): void {
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');

		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();

		$pseudonym = $this->column('fleet_vehicles', 'user_id', $own->getId());
		$this->assertMatchesRegularExpression('/^erased:[a-z0-9]{20}$/', $pseudonym);
		$this->expectException(\InvalidArgumentException::class);
		\OCP\Server::get(IUserManager::class)->validateUserId($pseudonym);
	}

	/**
	 * An erasure before 0.3.0 wrote `erased-`, which a new account could take. The upgrade renames
	 * it to `erased:` with the same suffix, so one former driver stays one; a group of that name
	 * is no erased account and keeps it.
	 */
	public function testTheRepairStepRenamesAnOldPseudonymOnce(): void {
		$suffix = str_repeat('k3x9q', 4);
		$old = 'erased-' . $suffix;
		$shared = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->grant($shared, self::DRIVER, 'driver');
		$this->trip(self::DRIVER, $shared);
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		$this->rename(self::DRIVER, $old);
		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();
		$group = new Access();
		$group->setVehicleId((int)$shared->getId());
		$group->setGrantee($old);
		$group->setGranteeType(Access::GROUP);
		$group->setRole('viewer');
		$group->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($group);
		$named = $this->rowsNaming($old);
		$config = \OCP\Server::get(IAppConfig::class);
		$epoch = $config->getValueString(Application::APP_ID, SyncService::EPOCH);

		\OCP\Server::get(ErasedPseudonyms::class)->run($this->createMock(IOutput::class));

		$this->assertSame(['fleet_access.grantee'], $this->rowsNaming($old), 'only the group keeps the name');
		$this->assertSame($named, $this->rowsNaming('erased:' . $suffix));
		$this->assertSame('erased:' . $suffix, $this->column('fleet_vehicles', 'user_id', $own->getId()));
		$this->assertNotSame($epoch, $config->getValueString(Application::APP_ID, SyncService::EPOCH), 'clients hold the old name');

		$epoch = $config->getValueString(Application::APP_ID, SyncService::EPOCH);
		\OCP\Server::get(ErasedPseudonyms::class)->run($this->createMock(IOutput::class));

		$this->assertSame($named, $this->rowsNaming('erased:' . $suffix));
		$this->assertSame($epoch, $config->getValueString(Application::APP_ID, SyncService::EPOCH), 'nothing to rename, nothing to reset');
	}

	/**
	 * A live account under an old pseudonym may be a real person, and renaming its rows would erase
	 * them with no way back. Its rows stay, and the admin is told which account to look at.
	 */
	public function testTheRepairStepKeepsALiveAccountsRowsAndNamesIt(): void {
		\OCP\Server::get(IAppManager::class)->loadApps();
		$live = 'erased-' . str_repeat('l1v3x', 4);
		$users = \OCP\Server::get(IUserManager::class);
		$users->createUser($live, bin2hex(random_bytes(16)));
		$admins = array_map(static fn (IUser $admin): string => $admin->getUID(), \OCP\Server::get(IGroupManager::class)->get('admin')?->getUsers() ?? []);
		$this->assertNotSame([], $admins, 'the instance has an admin to tell');
		try {
			$own = $this->vehicle($live, 'B-LV 1');
			$output = $this->createMock(IOutput::class);
			$output->expects($this->exactly(2))->method('warning')->with($this->stringContains($live));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->exactly(2))->method('warning')->with($this->anything(), $this->equalTo(['app' => Application::APP_ID, 'account' => $live]));
			$step = new ErasedPseudonyms(\OCP\Server::get(ErasureService::class), $logger, \OCP\Server::get(LookalikeNotices::class));

			// `occ maintenance:repair` runs it again, and the admins' lists must not fill up.
			$step->run($output);
			$step->run($output);

			$this->assertSame($live, $this->column('fleet_vehicles', 'user_id', $own->getId()));
			$this->assertSame(count($admins), $this->noticesOf($live, LookalikeNotices::OBJECT), 'every admin is told, once');
		} finally {
			$users->get($live)?->delete();
			\OCP\Server::get(LookalikeNotices::class)->withdraw($live);
		}
	}

	/**
	 * An erasure a database error cut short rolls back whole, and the uid stays pending until the
	 * job finishes it. Once finished it is not pending: rows a later writer leaves under the same
	 * name are nobody's erasure.
	 */
	public function testAnErasureCutShortIsFinishedByTheJob(): void {
		$own = $this->vehicle(self::GHOST, 'B-GH 1');

		try {
			$this->breaking()->erase(self::GHOST);
			$this->fail('The erasure did not break');
		} catch (Exception) {
		}
		$this->assertSame(self::GHOST, $this->column('fleet_vehicles', 'user_id', $own->getId()));

		$this->runJob();
		$this->assertMatchesRegularExpression('/^erased:/', $this->column('fleet_vehicles', 'user_id', $own->getId()));

		$later = $this->vehicle(self::GHOST, 'B-GH 2');
		$this->runJob();
		$this->assertSame(self::GHOST, $this->column('fleet_vehicles', 'user_id', $later->getId()));
	}

	/** An account made again under the uid before the job came may be a real person: its rows stay. */
	public function testAPendingErasureOfALiveAccountLeavesItsRows(): void {
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		try {
			$this->breaking()->erase(self::DRIVER);
			$this->fail('The erasure did not break');
		} catch (Exception) {
		}

		$this->runJob();

		$this->assertSame(self::DRIVER, $this->column('fleet_vehicles', 'user_id', $own->getId()));
	}

	/**
	 * Every vehicle an erasure holds, the account's own and those of the lists it is on, in one
	 * order: two erasures at once that took them crosswise would deadlock.
	 */
	public function testAnErasureHoldsItsVehiclesInIdOrder(): void {
		$listed = $this->vehicle(self::OWNER, 'B-XY 123');
		$this->recipients->add(self::OWNER, $listed->getUuid(), self::DRIVER);
		$own = $this->vehicle(self::DRIVER, 'B-DR 1');
		$this->assertGreaterThan($listed->getId(), $own->getId());
		$vehicles = new class(\OCP\Server::get(IDBConnection::class), \OCP\Server::get(ITimeFactory::class), \OCP\Server::get(ISecureRandom::class)) extends VehicleMapper {
			/** @var list<int> */
			public array $held = [];

			public function hold(int $vehicleId): void {
				$this->held[] = $vehicleId;
				parent::hold($vehicleId);
			}
		};

		$this->erasure(\OCP\Server::get(ReminderRecipientMapper::class), $vehicles)->erase(self::DRIVER);

		$this->assertSame([$listed->getId(), $own->getId()], $vehicles->held);
	}

	/** The service as the container builds it, with a reminder list whose erasure breaks. */
	private function breaking(): ErasureService {
		$recipients = new class(\OCP\Server::get(IDBConnection::class), \OCP\Server::get(ITimeFactory::class), \OCP\Server::get(ISecureRandom::class)) extends ReminderRecipientMapper {
			public function deleteAccount(string $userId): void {
				throw new Exception('the database broke');
			}
		};

		return $this->erasure($recipients, \OCP\Server::get(VehicleMapper::class));
	}

	/** The service as the container builds it, around the two mappers given. */
	private function erasure(ReminderRecipientMapper $recipients, VehicleMapper $vehicles): ErasureService {
		return new ErasureService(
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(ISecureRandom::class),
			$recipients,
			$vehicles,
			\OCP\Server::get(AccountTables::class),
			\OCP\Server::get(SyncEpoch::class),
			\OCP\Server::get(IUserManager::class),
			\OCP\Server::get(GrantService::class),
			\OCP\Server::get(VehicleService::class),
			\OCP\Server::get(GrantNotices::class),
			\OCP\Server::get(Pending::class),
			\OCP\Server::get(LoggerInterface::class),
		);
	}

	private function runJob(): void {
		\OCP\Server::get(PendingJob::class)->start(\OCP\Server::get(IJobList::class));
	}

	/**
	 * Every column of PEOPLE_COLUMNS that names `$uid`, as an erasure before 0.3.0 left it. Not a
	 * recipient: that erasure deleted the entry, and the account's deletion still does.
	 */
	private function rename(string $uid, string $pseudonym): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (self::PEOPLE_COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				if ($table === 'fleet_reminder_recipients' && $column === 'user_id') {
					continue;
				}
				$qb = $db->getQueryBuilder();
				$qb->update($table)->set($column, $qb->createNamedParameter($pseudonym))
					->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
				$qb->executeStatement();
			}
		}
	}

	private function vehicle(string $owner, string $plate): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => $plate]);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function trip(string $author, Vehicle $vehicle): Trip {
		return $this->trips->record($author, $vehicle->getUuid(), [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 12000,
			'category' => Trip::BUSINESS,
		]);
	}

	/** @return string the uuid of a booking the booker has checked out under, with a note */
	private function takenBooking(string $booker, Vehicle $vehicle, string $notes): string {
		$bookings = \OCP\Server::get(BookingService::class);
		$booking = $bookings->book($booker, $vehicle->getUuid(), [
			'starts_at' => time() + 3600,
			'starts_at_off' => 120,
			'ends_at' => time() + 3 * 3600,
			'ends_at_off' => 120,
		]);
		$bookings->checkOut($booker, $vehicle->getUuid(), (string)$booking['uuid'], ['odo' => 12000, 'notes' => $notes, 'at_off' => 120]);

		return (string)$booking['uuid'];
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/** @return array<string, int> rows per table, whoever wrote them */
	private function rowCounts(): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$counts = [];
		foreach (array_keys(self::PEOPLE_COLUMNS) as $table) {
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'n'))->from($table);
			$counts[$table] = (int)$qb->executeQuery()->fetchOne();
		}

		return $counts;
	}

	/** @return list<string> `table.column` wherever the account is still named */
	private function rowsNaming(string $uid): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$found = [];
		foreach (self::PEOPLE_COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				$qb = $db->getQueryBuilder();
				$qb->select($qb->func()->count('*', 'n'))->from($table)
					->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
				if ((int)$qb->executeQuery()->fetchOne() > 0) {
					$found[] = $table . '.' . $column;
				}
			}
		}

		return $found;
	}

	private function column(string $table, string $column, int|string|null $id): string {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select($column)->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, $qb::PARAM_INT)));

		return (string)$qb->executeQuery()->fetchOne();
	}

	private function idOf(string $table, string $uuid): int {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/** How many notifications the store holds for one object, a grant unless told, to anyone. */
	private function noticesOf(string $objectId, string $type = GrantNotices::OBJECT): int {
		$manager = \OCP\Server::get(IManager::class);

		return $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)->setObject($type, $objectId));
	}

	private function granteeOn(Vehicle $vehicle): string {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('grantee')->from('fleet_access')->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));

		return (string)$qb->executeQuery()->fetchOne();
	}
}
