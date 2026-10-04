<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\ImportCommand;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\VehicleService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:import` against real accounts and a file in their Files: the same service and
 * checks as the screen (docs/architecture.md#import).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ImportCommandTest extends TestCase {
	private const OWNER = 'nextfleet-test-importcmd-owner';
	private const DRIVER = 'nextfleet-test-importcmd-driver';
	private const ACCOUNTS = [self::OWNER, self::DRIVER];
	private const FIXTURE = __DIR__ . '/../Fixture/import/lubelogger-fuel.csv';

	private TimelineService $timeline;
	private CommandTester $command;
	private ?int $vehicleId = null;

	protected function setUp(): void {
		$this->timeline = \OCP\Server::get(TimelineService::class);
		$this->command = new CommandTester(\OCP\Server::get(ImportCommand::class));
	}

	/** The accounts live for the whole class, for the reason DocumentTest gives. */
	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->get($uid)?->delete();
		}
	}

	protected function tearDown(): void {
		if ($this->vehicleId === null) {
			return;
		}
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_energy', 'fleet_odo_readings', 'fleet_access', 'fleet_reminder_recipients'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($this->vehicleId, $qb::PARAM_INT)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->vehicleId, $qb::PARAM_INT)));
		$qb->executeStatement();
		$this->vehicleId = null;
	}

	/** The fixture's two readable fill-ups: printed by the dry run, written by the import. */
	public function testADryRunPreviewsWhatTheImportThenWrites(): void {
		$vehicle = $this->vehicle();
		$path = $this->file(self::OWNER);

		$this->assertSame(0, $this->occ(self::OWNER, $vehicle, $path, ['--dry-run' => true]));
		$this->assertStringContainsString('Rows: 2 new, 0 duplicate, 2 unreadable; an import creates 2.', $this->command->getDisplay());
		$this->assertStringContainsString('FuelConsumed → amount', $this->command->getDisplay());
		$this->assertSame([], $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);

		$this->assertSame(0, $this->occ(self::OWNER, $vehicle, $path));
		$this->assertStringContainsString('Imported 2 entries.', $this->command->getDisplay());
		$this->assertCount(2, $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
	}

	/** Importing is `edit`; a driver's own file does not change that. */
	public function testADriverIsRefused(): void {
		$vehicle = $this->vehicle();
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee(self::DRIVER);
		$grant->setGranteeType(Access::USER);
		$grant->setRole('driver');
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);

		$this->assertSame(1, $this->occ(self::DRIVER, $vehicle, $this->file(self::DRIVER)));
		$this->assertSame([], $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function occ(string $uid, Vehicle $vehicle, string $path, array $options = []): int {
		return $this->command->execute($options + [
			'user' => $uid,
			'vehicle' => $vehicle->getUuid(),
			'path' => $path,
			'--importer' => 'lubelogger',
			'--record-type' => 'fuel',
			'--units' => 'km,l',
		]);
	}

	private function vehicle(): Vehicle {
		$vehicle = \OCP\Server::get(VehicleService::class)->create(self::OWNER, ['plate' => 'B-IC 1', 'currency' => 'EUR', 'energy_types' => ['diesel']]);
		$this->vehicleId = (int)$vehicle->getId();

		return $vehicle;
	}

	/** The fixture in a folder of its own, since the accounts outlive a test; its path. */
	private function file(string $uid): string {
		$folder = bin2hex(random_bytes(6));
		\OCP\Server::get(IRootFolder::class)->getUserFolder($uid)->newFolder($folder)->newFile('fuel.csv', (string)file_get_contents(self::FIXTURE));

		return $folder . '/fuel.csv';
	}
}
