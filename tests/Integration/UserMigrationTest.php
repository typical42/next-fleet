<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The personal data export as an admin runs it, through Nextcloud's user_migration app and its
 * archive (docs/architecture.md#personal-data-export). UserExportTest covers the migrator's own
 * rules, a transfer's audit row among them; this suite covers what reaches the archive, and what
 * importing it does. It skips without user_migration (docs/development.md#testing).
 *
 * One fixture and one archive for the class: the server caches a user folder per uid, and an
 * account deleted and made again under the same uid cannot write to it (Accounts).
 */
class UserMigrationTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-migration-owner';
	private const DRIVER = 'nextfleet-test-migration-driver';
	private const STRANGER = 'nextfleet-test-migration-stranger';
	private const FRESH = 'nextfleet-test-migration-fresh';
	private const ACCOUNTS = [self::OWNER, self::DRIVER, self::STRANGER, self::FRESH];
	/** The columns that name an account, as ErasureTest::PEOPLE_COLUMNS lists them. */
	private const PEOPLE = ['user_id', 'created_by', 'grantee'];

	/** @var list<int> */
	private static array $vehicleIds = [];
	/** @var list<int> */
	private static array $reminderIds = [];
	private static ?string $archive = null;

	public static function setUpBeforeClass(): void {
		$apps = \OCP\Server::get(IAppManager::class);
		if (!$apps->isInstalled('user_migration')) {
			self::markTestSkipped('user_migration is not installed; `occ app:install user_migration` runs this suite');
		}
		$apps->loadApp('user_migration');

		self::forget();
		// PHPUnit skips tearDownAfterClass when this throws, and the next run knows no vehicle ids.
		try {
			$users = \OCP\Server::get(IUserManager::class);
			foreach (self::ACCOUNTS as $uid) {
				$users->createUser($uid, bin2hex(random_bytes(16)));
			}
			self::driverInEveryTable();
			self::$archive = self::export(self::DRIVER);
		} catch (\Throwable $e) {
			self::forget();
			throw $e;
		}
	}

	public static function tearDownAfterClass(): void {
		self::forget();
	}

	/** Rows first, by vehicle: once an account is deleted its uid is on none of them. */
	private static function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if (self::$reminderIds !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_reminder_receipts')->where($qb->expr()->in('reminder_id', $qb->createNamedParameter(self::$reminderIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		if (self::$vehicleIds !== []) {
			foreach (self::fleetTables() as $table) {
				if (in_array($table, ['fleet_vehicles', 'fleet_audit', 'fleet_reminder_receipts'], true)) {
					continue;
				}
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter(self::$vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			// The trips' audit rows hang on the trip, not on the vehicle.
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_audit')->where($qb->expr()->in('created_by', $qb->createNamedParameter(self::ACCOUNTS, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter(self::$vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		self::$vehicleIds = [];
		self::$reminderIds = [];
		if (self::$archive !== null) {
			unlink(self::$archive);
			self::$archive = null;
		}

		self::deleteAccounts(self::ACCOUNTS);
	}

	/** A driver who used every part of the app; the owner's and the stranger's rows stay out. */
	public function testTheArchiveHoldsEveryRowNamingTheAccountAndNoOther(): void {
		$files = $this->nextfleetFiles((string)self::$archive);

		$this->assertEqualsCanonicalizing(array_map(static fn (string $table): string => 'nextfleet/' . $table . '.json', self::fleetTables()), array_keys($files), 'a table is missing');
		$this->assertEquals([
			// Their own car only: the shared one is the owner's.
			'nextfleet/fleet_vehicles.json' => 1,
			// The grant to them, and theirs to the owner.
			'nextfleet/fleet_access.json' => 2,
			// The trip's end, the reading, the fill-up's.
			'nextfleet/fleet_odo_readings.json' => 3,
			'nextfleet/fleet_trips.json' => 1,
			'nextfleet/fleet_energy.json' => 1,
			'nextfleet/fleet_maintenance.json' => 1,
			// The deleted one too.
			'nextfleet/fleet_expenses.json' => 2,
			'nextfleet/fleet_reminders.json' => 1,
			'nextfleet/fleet_reminder_receipts.json' => 1,
			// On the shared car's list, and on their own car's.
			'nextfleet/fleet_reminder_recipients.json' => 2,
			'nextfleet/fleet_documents.json' => 1,
			// Their trip under Logbook Mode.
			'nextfleet/fleet_audit.json' => 1,
			'nextfleet/fleet_bookings.json' => 1,
		], array_map('count', $files));
		foreach ($files as $name => $rows) {
			foreach ($rows as $row) {
				$this->assertContains(self::DRIVER, array_intersect_key($row, array_flip(self::PEOPLE)), "$name holds a row that does not name the account");
			}
		}
	}

	/**
	 * docs/legal.md: export only. The import says so and writes no row, neither under the new
	 * account nor as the archive stored it.
	 */
	public function testImportingTheArchiveIntoAFreshAccountRestoresNoRow(): void {
		$before = $this->rowsNaming([self::DRIVER, self::FRESH]);
		$output = new BufferedOutput();

		/** @psalm-suppress UndefinedClass user_migration is an app, not in vendor */
		\OCP\Server::get(\OCA\UserMigration\Service\UserMigrationService::class)
			->import(new \OCA\UserMigration\ImportSource((string)self::$archive), self::user(self::FRESH), $output);

		$this->assertStringContainsString('NextFleet does not restore from an export', $output->fetch());
		$this->assertSame($before, $this->rowsNaming([self::DRIVER, self::FRESH]));
	}

	/**
	 * The owner shares a car with the driver, who logs every kind of entry on it, books it, files
	 * a paper and gets a reminder; the driver shares their own car back. A stranger logs on theirs.
	 */
	private static function driverInEveryTable(): void {
		$shared = self::vehicle(self::OWNER, ['plate' => 'B-XY 123', 'engine' => 'diesel', 'logbook_mode' => true]);
		$uuid = $shared->getUuid();
		$grants = \OCP\Server::get(GrantService::class);
		$grants->grant(self::OWNER, $uuid, ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'manager']);
		\OCP\Server::get(RecipientService::class)->add(self::OWNER, $uuid, self::DRIVER);
		self::trip(self::OWNER, $shared, 1750000000, 12000);
		// Under the mode, so the trip leaves an audit row by the driver.
		self::trip(self::DRIVER, $shared, 1750010000, 12040);
		\OCP\Server::get(OdometerService::class)->record(self::DRIVER, $uuid, ['read_at' => 1750050000, 'read_at_off' => 120, 'value' => 12080]);
		\OCP\Server::get(EnergyService::class)->record(self::DRIVER, $uuid, ['filled_at' => 1750100000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'odo' => 12100]);
		\OCP\Server::get(MaintenanceService::class)->record(self::DRIVER, $uuid, ['done_at' => 1750200000, 'done_at_off' => 120, 'title' => 'Oil change']);
		$expenses = \OCP\Server::get(ExpenseService::class);
		$expenses->record(self::DRIVER, $uuid, ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400]);
		$deleted = $expenses->record(self::DRIVER, $uuid, ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 1200]);
		$expenses->delete(self::DRIVER, $uuid, $deleted['uuid'], $deleted['updated_at']);
		$created = \OCP\Server::get(ReminderService::class)->create(self::DRIVER, $uuid, ['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 20000]);
		$reminder = \OCP\Server::get(ReminderMapper::class)->findAnyByUuid((string)$created['uuid']);
		self::$reminderIds[] = (int)$reminder->getId();
		\OCP\Server::get(ReminderReceiptMapper::class)->claim($reminder, 'due_date', 'app', self::DRIVER, time());
		$file = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::DRIVER)->newFolder(bin2hex(random_bytes(6)))->newFile('receipt.txt', 'receipt');
		\OCP\Server::get(DocumentService::class)->attach(self::DRIVER, $uuid, ['file_id' => $file->getId(), 'kind' => 'receipt']);
		$bookings = \OCP\Server::get(BookingService::class);
		$booking = $bookings->book(self::DRIVER, $uuid, ['starts_at' => time() + 3600, 'starts_at_off' => 120, 'ends_at' => time() + 3 * 3600, 'ends_at_off' => 120]);
		$bookings->checkOut(self::DRIVER, $uuid, (string)$booking['uuid'], ['odo' => 12100, 'notes' => 'Scratch on the tailgate', 'at_off' => 120]);

		$own = self::vehicle(self::DRIVER, ['plate' => 'B-DR 1']);
		$grants->grant(self::DRIVER, $own->getUuid(), ['grantee' => self::OWNER, 'grantee_type' => 'user', 'role' => 'viewer']);

		$theirs = self::vehicle(self::STRANGER, ['plate' => 'B-ST 1', 'logbook_mode' => true]);
		self::trip(self::STRANGER, $theirs, 1750000000, 5000);
	}

	/** @return string the path of the archive user_migration wrote, as `occ user:export` writes it */
	private static function export(string $uid): string {
		$archive = \OCP\Server::get(ITempManager::class)->getTemporaryFile('.zip');
		self::assertIsString($archive);
		$stream = fopen($archive, 'wb');
		self::assertIsResource($stream);

		/** @psalm-suppress UndefinedClass user_migration is an app, not in vendor */
		\OCP\Server::get(\OCA\UserMigration\Service\UserMigrationService::class)
			->export(new \OCA\UserMigration\ExportDestination($stream, $archive), self::user($uid), null, new BufferedOutput());
		fclose($stream);

		return $archive;
	}

	/** @return array<string, list<array<string, mixed>>> each nextfleet/ file in the archive, decoded */
	private function nextfleetFiles(string $archive): array {
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($archive));
		$files = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_starts_with($name, 'nextfleet/')) {
				/** @var list<array<string, mixed>> */
				$files[$name] = json_decode((string)$zip->getFromIndex($i), true, 512, JSON_THROW_ON_ERROR);
			}
		}
		$zip->close();

		return $files;
	}

	/**
	 * @param list<string> $uids
	 * @return array<string, int> per table, the rows whose account column holds one of the uids
	 */
	private function rowsNaming(array $uids): array {
		$db = \OCP\Server::get(IDBConnection::class);
		$schema = $db->createSchema();
		$prefix = \OCP\Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$counts = [];
		foreach (self::fleetTables() as $table) {
			$columns = array_filter(self::PEOPLE, static fn (string $column): bool => $schema->getTable($prefix . $table)->hasColumn($column));
			$this->assertNotSame([], $columns, "$table names no account in a column this suite knows");
			$qb = $db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'n'))->from($table);
			foreach ($columns as $column) {
				$qb->orWhere($qb->expr()->in($column, $qb->createNamedParameter($uids, $qb::PARAM_STR_ARRAY)));
			}
			$counts[$table] = (int)$qb->executeQuery()->fetchOne();
		}

		return $counts;
	}

	/** @return list<string> the app's tables in the live schema, without the server's prefix */
	private static function fleetTables(): array {
		$prefix = \OCP\Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$names = array_map(static fn (Table $table): string => $table->getName(), \OCP\Server::get(IDBConnection::class)->createSchema()->getTables());

		return array_values(array_map(
			static fn (string $name): string => substr($name, strlen($prefix)),
			array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix . 'fleet_')),
		));
	}

	private static function user(string $uid): IUser {
		$user = \OCP\Server::get(IUserManager::class)->get($uid);
		self::assertNotNull($user);

		return $user;
	}

	/** @param array<string, mixed> $fields */
	private static function vehicle(string $owner, array $fields): Vehicle {
		$vehicle = \OCP\Server::get(VehicleService::class)->create($owner, $fields);
		self::$vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private static function trip(string $author, Vehicle $vehicle, int $startedAt, int $endOdo): void {
		\OCP\Server::get(TripService::class)->record($author, $vehicle->getUuid(), [
			'started_at' => $startedAt,
			'started_at_off' => 120,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 120,
			'end_odo' => $endOdo,
			'category' => Trip::BUSINESS,
		]);
	}
}
