<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\CheckCommand;
use OCA\NextFleet\Command\RecomputeCommand;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:recompute`: a chain broken behind the services' back, settled again.
 *
 * It writes to the instance it runs against (docs/development.md#testing); `--all` settles every
 * vehicle there, so a run over all of them is read for this suite's rows only.
 */
class RecomputeCommandTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-recompute-owner';

	private CommandTester $command;

	/** An account, since a vehicle whose owner no backend knows is a finding and refused a restore. */
	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::OWNER]);
		\OCP\Server::get(IUserManager::class)->createUser(self::OWNER, bin2hex(random_bytes(16)));
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::OWNER]);
	}

	protected function setUp(): void {
		$this->command = new CommandTester(\OCP\Server::get(RecomputeCommand::class));
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_odo_readings', 'fleet_audit'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
	}

	/**
	 * Both caches and both ways a flag can be wrong, each printed before → after. A flag moves
	 * its Reading's `updated_at`, as a write's settling does; a cache leaves the vehicle's alone.
	 */
	public function testADriftedChainIsSettledAndEachChangePrinted(): void {
		$vehicle = $this->vehicle(['second_unit' => 'h']);
		$this->read($vehicle, 120000, readAt: 1750000000);
		$sound = $this->read($vehicle, 120500, readAt: 1750100000);
		$lower = $this->read($vehicle, 119000, readAt: 1750200000);
		$this->read($vehicle, 5000, OdoReading::SECOND);
		$this->update('fleet_odo_readings', (int)$sound->getId(), ['flagged' => true, 'updated_at' => 1750100000]);
		$this->update('fleet_odo_readings', (int)$lower->getId(), ['flagged' => false, 'updated_at' => 1750200000]);
		$this->update('fleet_vehicles', (int)$vehicle->getId(), ['odo_value' => 999, 'second_value' => null]);
		$updatedAt = $this->stored($vehicle)->getUpdatedAt();

		$this->assertSame(0, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']), $this->command->getDisplay());

		$uuid = $vehicle->getUuid();
		$this->assertSame([
			['vehicle' => $uuid, 'table' => 'fleet_odo_readings', 'row' => $sound->getUuid(), 'column' => 'flagged', 'before' => true, 'after' => false],
			['vehicle' => $uuid, 'table' => 'fleet_odo_readings', 'row' => $lower->getUuid(), 'column' => 'flagged', 'before' => false, 'after' => true],
			['vehicle' => $uuid, 'table' => 'fleet_vehicles', 'row' => $uuid, 'column' => 'odo_value', 'before' => 999, 'after' => 119000],
			['vehicle' => $uuid, 'table' => 'fleet_vehicles', 'row' => $uuid, 'column' => 'second_value', 'before' => null, 'after' => 5000],
		], $this->changes());
		$this->assertCheckFindsNothing($vehicle);
		$this->assertGreaterThan(1750100000, $this->reading($vehicle, $sound)->getUpdatedAt());
		$this->assertGreaterThan(1750200000, $this->reading($vehicle, $lower)->getUpdatedAt());
		$this->assertSame($updatedAt, $this->stored($vehicle)->getUpdatedAt());
	}

	/** Found, so exit 1, as check's finding is; and left for a run without the flag. */
	public function testADryRunPrintsTheChangesAndMakesNone(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, 120000);
		$this->update('fleet_vehicles', (int)$vehicle->getId(), ['odo_value' => 999]);

		$this->assertSame(1, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--dry-run' => true, '--output' => 'json']));

		$this->assertSame([
			['vehicle' => $vehicle->getUuid(), 'table' => 'fleet_vehicles', 'row' => $vehicle->getUuid(), 'column' => 'odo_value', 'before' => 999, 'after' => 120000],
		], $this->changes());
		$this->assertSame(999, $this->stored($vehicle)->getOdoValue());
	}

	public function testASoundVehicleIsNothingChanged(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, 120000);

		$this->assertSame(0, $this->command->execute(['--vehicle' => $vehicle->getUuid()]));
		$this->assertSame("Nothing changed.\n", $this->command->getDisplay());

		$this->assertSame(0, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--dry-run' => true]));
		$this->assertSame("Nothing would change.\n", $this->command->getDisplay());
	}

	/** `--all` reads the list first; a write that moves a cache after that is no drift. */
	public function testADryRunComparesTheCachesAsTheyStandNow(): void {
		$listed = $this->vehicle();
		$this->read($listed, 120000);

		$this->assertSame([], \OCP\Server::get(OdometerService::class)->recompute($listed, true));
	}

	/** A deleted vehicle too: an undo brings it back as it is. */
	public function testAllSettlesEveryVehicleDeletedOnesIncluded(): void {
		$live = $this->vehicle();
		$this->read($live, 120000);
		$trashed = $this->vehicle(['plate' => 'NF-RC 2']);
		$this->read($trashed, 64000);
		$fleet = \OCP\Server::get(VehicleService::class);
		$fleet->delete(self::OWNER, $trashed->getUuid(), $this->stored($trashed)->getUpdatedAt());
		$this->update('fleet_vehicles', (int)$live->getId(), ['odo_value' => 1]);
		$this->update('fleet_vehicles', (int)$trashed->getId(), ['odo_value' => 2]);

		$this->assertSame(0, $this->command->execute(['--all' => true, '--output' => 'json']));

		$ours = array_values(array_filter($this->changes(), static fn (array $change): bool => in_array($change['vehicle'], [$live->getUuid(), $trashed->getUuid()], true)));
		$this->assertSame([[$live->getUuid(), 1, 120000], [$trashed->getUuid(), 2, 64000]], array_map(
			static fn (array $change): array => [$change['vehicle'], $change['before'], $change['after']],
			$ours,
		));
		$this->assertSame(120000, $this->stored($live)->getOdoValue());
		$this->assertSame(64000, $this->stored($trashed)->getOdoValue());
	}

	/** On stderr, so stdout stays one JSON document or nothing. */
	public function testNeitherOrBothScopesOrAnUnknownVehicleIsBadInput(): void {
		foreach ([[], ['--all' => true, '--vehicle' => 'x'], ['--vehicle' => 'no-such-uuid']] as $scope) {
			$this->assertSame(2, $this->command->execute($scope + ['--output' => 'json'], ['capture_stderr_separately' => true]), json_encode($scope) ?: '');
			$this->assertSame('', $this->command->getDisplay());
			$this->assertNotSame('', $this->command->getErrorOutput());
		}
	}

	private function assertCheckFindsNothing(Vehicle $vehicle): void {
		$check = new CommandTester(\OCP\Server::get(CheckCommand::class));
		$this->assertSame(0, $check->execute(['--vehicle' => $vehicle->getUuid()]), $check->getDisplay());
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(array $fields = []): Vehicle {
		return \OCP\Server::get(VehicleService::class)->create(self::OWNER, $fields + ['plate' => 'NF-RC 1']);
	}

	/** @param OdoReading::MAIN|OdoReading::SECOND $counter */
	private function read(Vehicle $vehicle, int $value, string $counter = OdoReading::MAIN, int $readAt = 1750000000): OdoReading {
		return \OCP\Server::get(OdometerService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'read_at' => $readAt,
			'read_at_off' => 0,
			'value' => $value,
			'counter' => $counter,
		]);
	}

	private function stored(Vehicle $vehicle): Vehicle {
		return \OCP\Server::get(VehicleMapper::class)->findAnyById((int)$vehicle->getId());
	}

	private function reading(Vehicle $vehicle, OdoReading $reading): OdoReading {
		return \OCP\Server::get(OdoReadingMapper::class)->findAnyOnVehicle((int)$vehicle->getId(), $reading->getUuid());
	}

	/**
	 * A row changed as no service would change it.
	 *
	 * @param array<string, int|string|bool|null> $columns
	 */
	private function update(string $table, int $id, array $columns): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($columns as $column => $value) {
			$qb->set($column, $qb->createNamedParameter($value, match (true) {
				$value === null => IQueryBuilder::PARAM_NULL,
				is_bool($value) => IQueryBuilder::PARAM_BOOL,
				is_int($value) => IQueryBuilder::PARAM_INT,
				default => IQueryBuilder::PARAM_STR,
			}));
		}
		$qb->executeStatement();
	}

	/**
	 * The changes the last JSON run printed.
	 *
	 * @return list<array{vehicle: string, table: string, row: string, column: string, before: int|bool|null, after: int|bool|null}>
	 */
	private function changes(): array {
		/** @var list<array{vehicle: string, table: string, row: string, column: string, before: int|bool|null, after: int|bool|null}> */
		return json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
	}
}
