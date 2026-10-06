<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\TransferService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use OCA\NextFleet\UserMigration\FleetMigrator;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The personal data export (Art. 15, 20 GDPR) through Nextcloud's user migration: every row that
 * names the account, a JSON file per table, and nothing else.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class UserExportTest extends TestCase {
	private const OWNER = 'nextfleet-test-export-owner';
	private const DRIVER = 'nextfleet-test-export-driver';
	private const TABLES = [
		'fleet_vehicles', 'fleet_access', 'fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses',
		'fleet_reminders', 'fleet_reminder_receipts', 'fleet_reminder_recipients', 'fleet_documents', 'fleet_audit', 'fleet_bookings',
	];

	private VehicleService $vehicles;
	private TripService $trips;
	private FleetMigrator $migrator;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->migrator = \OCP\Server::get(FleetMigrator::class);
		$this->forget();

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Rows first, by vehicle: once an account is deleted its uid is on none of them. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (self::TABLES as $table) {
				if (in_array($table, ['fleet_vehicles', 'fleet_audit', 'fleet_reminder_receipts'], true)) {
					continue;
				}
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_audit')
				->where($qb->expr()->eq('entity', $qb->createNamedParameter(Audit::VEHICLE)))
				->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
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

	/** Grants and list entries that name the account count too. */
	public function testTheExportHoldsEveryRowNamingTheAccountAndNoOther(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		[$grant] = \OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		\OCP\Server::get(RecipientService::class)->add(self::OWNER, $vehicle->getUuid(), self::DRIVER);
		$this->trip(self::OWNER, $vehicle, 1750000000, 12000);
		$driven = $this->trip(self::DRIVER, $vehicle, 1750010000, 12040);
		$expense = \OCP\Server::get(ExpenseService::class)->record(self::DRIVER, $vehicle->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400]);

		$files = $this->export(self::DRIVER);

		$this->assertEqualsCanonicalizing(array_map(static fn (string $table): string => 'nextfleet/' . $table . '.json', $this->fleetTables()), array_keys($files), 'a table is missing');
		$this->assertSame([$driven->getUuid()], array_column($files['nextfleet/fleet_trips.json'], 'uuid'));
		$this->assertSame([$expense['uuid']], array_column($files['nextfleet/fleet_expenses.json'], 'uuid'));
		$this->assertSame([$grant['uuid']], array_column($files['nextfleet/fleet_access.json'], 'uuid'));
		$this->assertSame([self::DRIVER], array_column($files['nextfleet/fleet_reminder_recipients.json'], 'user_id'));
		$this->assertSame([], $files['nextfleet/fleet_vehicles.json'], 'the vehicle is the owner\'s');
		// Every column as stored, so the file is the row and not a reading of it.
		$this->assertSame(12040, $files['nextfleet/fleet_trips.json'][0]['end_odo']);
		$this->assertSame(self::DRIVER, $files['nextfleet/fleet_trips.json'][0]['created_by']);
	}

	/** A date is the day the table holds, not PHP's reading of it; the export is a bulk read and logged as one. */
	public function testADateIsExportedAsStoredAndTheExportIsLogged(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'first_reg' => '2020-03-01']);
		$this->vehicleIds[] = (int)$vehicle->getId();
		$logger = new SpyLogger();
		$this->migrator = new FleetMigrator(
			\OCP\Server::get(AccountTables::class),
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(TripMapper::class),
			\OCP\Server::get(VehicleAccess::class),
			\OCP\Server::get(IFactory::class)->get(Application::APP_ID),
			$logger,
		);

		$files = $this->export(self::OWNER);

		$this->assertSame('2020-03-01', $files['nextfleet/fleet_vehicles.json'][0]['first_reg']);
		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'User data export',
			'context' => ['app' => 'nextfleet', 'user' => self::OWNER, 'rows' => array_sum(array_map('count', $files))],
		]], $logger->lines);
	}

	/** A transfer's audit row names both owners inside its diff, not in a column, and is theirs to see. */
	public function testATransferNamingTheAccountIsInTheExport(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		\OCP\Server::get(TransferService::class)->transfer($vehicle->getUuid(), self::DRIVER);

		$audit = $this->export(self::OWNER)['nextfleet/fleet_audit.json'];

		$this->assertCount(1, $audit);
		$this->assertSame(Audit::TRANSFERRED_BY, $audit[0]['created_by']);
		$this->assertSame([self::OWNER, self::DRIVER], $audit[0]['diff_json']['fields']['user_id']);
	}

	/**
	 * A row that mirrors a vehicle's shared state is the account's only while it may see the
	 * vehicle: handed on, the vehicle is the new owner's, and the copy must not show its VIN or
	 * notes as they are now (Art. 15(4) GDPR). What the account wrote of its own doing stays whole.
	 */
	public function testARowOnAVehicleTheAccountNoLongerSeesIsWithheld(): void {
		$vehicle = $this->vehicle(self::OWNER, 'B-XY 123');
		$driven = $this->trip(self::OWNER, $vehicle, 1750000000, 12000);
		\OCP\Server::get(TransferService::class)->transfer($vehicle->getUuid(), self::DRIVER);
		\OCP\Server::get(VehicleService::class)->update(self::DRIVER, $vehicle->getUuid(), $this->vehicles->find(self::DRIVER, $vehicle->getUuid())->getUpdatedAt(), ['vin' => 'WVWZZZ1KZBW000001']);
		[$viewer] = array_values(array_filter(
			\OCP\Server::get(GrantService::class)->list(self::DRIVER, $vehicle->getUuid()),
			static fn (array $grant): bool => $grant['grantee'] === self::OWNER,
		));
		\OCP\Server::get(GrantService::class)->revoke(self::DRIVER, $vehicle->getUuid(), $viewer['uuid']);

		$files = $this->export(self::OWNER);

		$this->assertSame([[
			'uuid' => $vehicle->getUuid(),
			'created_by' => self::OWNER,
			'created_at' => $vehicle->getCreatedAt(),
			'withheld' => 'The rest of this row belongs to a vehicle you can no longer see.',
		]], $files['nextfleet/fleet_vehicles.json']);
		$this->assertSame([$driven->getUuid()], array_column($files['nextfleet/fleet_trips.json'], 'uuid'));
		$this->assertSame(12000, $files['nextfleet/fleet_trips.json'][0]['end_odo']);
	}

	/** NextFleet does not restore from an export, and says so rather than failing the whole import. */
	public function testAnImportRestoresNothingAndSaysSo(): void {
		$output = new BufferedOutput();

		$this->migrator->import($this->user(self::DRIVER), $this->createMock(IImportSource::class), $output);

		$this->assertStringContainsString('does not restore', $output->fetch());
		$this->assertTrue($this->migrator->canImport($this->createMock(IImportSource::class)));
	}

	/** @return array<string, list<array<string, mixed>>> each file the export wrote, decoded */
	private function export(string $uid): array {
		$destination = new class implements IExportDestination {
			/** @var array<string, string> */
			public array $files = [];

			public function addFileContents(string $path, string $content): void {
				$this->files[$path] = $content;
			}

			public function addFileAsStream(string $path, $stream): void {
				$this->files[$path] = (string)stream_get_contents($stream);
			}

			public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
				throw new \LogicException('not expected');
			}

			public function setMigratorVersions(array $versions): void {
			}

			public function close(): void {
			}
		};

		$this->migrator->export($this->user($uid), $destination, new BufferedOutput());

		return array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $destination->files);
	}

	/** @return list<string> the app's tables in the live schema, without the server's prefix */
	private function fleetTables(): array {
		$prefix = \OCP\Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$names = array_map(static fn (Table $table): string => $table->getName(), \OCP\Server::get(IDBConnection::class)->createSchema()->getTables());

		return array_values(array_map(
			static fn (string $name): string => substr($name, strlen($prefix)),
			array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix . 'fleet_')),
		));
	}

	private function user(string $uid): IUser {
		$user = \OCP\Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);

		return $user;
	}

	private function vehicle(string $owner, string $plate): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => $plate]);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function trip(string $author, Vehicle $vehicle, int $startedAt, int $endOdo): Trip {
		return $this->trips->record($author, $vehicle->getUuid(), [
			'started_at' => $startedAt,
			'started_at_off' => 120,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 120,
			'end_odo' => $endOdo,
			'category' => Trip::BUSINESS,
		]);
	}
}
