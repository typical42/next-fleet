<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\CheckCommand;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\Pending;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:check`: each check against a row broken on purpose, behind the services' back,
 * since no service writes one.
 *
 * It writes to the instance it runs against (docs/development.md#testing). The instance may hold
 * findings of its own, so a run over every vehicle is read for this suite's rows only.
 */
class CheckCommandTest extends TestCase {
	/** Not an account: a vehicle's `user_id` is a string column. Every row here is written as it. */
	private const OWNER = 'nextfleet-test-check-owner';
	/** An account and a group, since a grantee has to exist to be granted. */
	private const ANNA = 'nextfleet-test-check-anna';
	private const CREW = 'nextfleet-test-check-crew';
	/** Neither an account nor a group. */
	private const NOBODY = 'nextfleet-test-check-nobody';
	/** Neither, and marked pending as an erasure and as a group's revokes. */
	private const ERASING = 'nextfleet-test-check-erasing';
	/** A pseudonym from before 0.3.0 that no account carries, and one an account does. */
	private const OLD = 'erased-nfcheckold0000000000';
	private const KEPT = 'erased-nfcheckkept000000000';
	/** Far past any id the instance hands out. */
	private const NO_VEHICLE = 999999999999;

	private VehicleService $vehicles;
	private CommandTester $command;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		\OCP\Server::get(IUserManager::class)->createUser(self::ANNA, bin2hex(random_bytes(16)));
		\OCP\Server::get(IGroupManager::class)->createGroup(self::CREW);
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		\OCP\Server::get(IUserManager::class)->get(self::ANNA)?->delete();
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
	}

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->command = new CommandTester(\OCP\Server::get(CheckCommand::class));
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/**
	 * The rows this suite writes, gone for real, an orphan's included: all are the owner's, or
	 * renamed to a pseudonym. Then the account named like one, whose deletion would erase them.
	 */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = ['fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_access', 'fleet_reminder_recipients', 'fleet_audit'];
		foreach ($tables as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('created_by', $qb->createNamedParameter([self::OWNER, self::OLD, self::KEPT], IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
		\OCP\Server::get(IUserManager::class)->get(self::KEPT)?->delete();
	}

	public function testASoundVehicleIsNothingFound(): void {
		$vehicle = $this->vehicle();

		$this->assertSame(0, $this->command->execute(['--vehicle' => $vehicle->getUuid()]), $this->command->getDisplay());

		$this->assertSame("No findings.\n", $this->command->getDisplay());
	}

	/** On stderr, so stdout stays one JSON document or nothing. */
	public function testAnUnknownVehicleIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['--vehicle' => 'no-such-uuid', '--output' => 'json'], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('no-such-uuid', $this->command->getErrorOutput());
	}

	public function testAnUnknownOutputIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['--output' => 'xml']));
	}

	/** Both caches, each against its own chain (rule 4). */
	public function testACachedCounterOffItsChainIsAFinding(): void {
		$vehicle = $this->vehicle(['second_unit' => 'h']);
		$this->read($vehicle, 120000);
		$this->read($vehicle, 5000, OdoReading::SECOND);
		$this->update('fleet_vehicles', (int)$vehicle->getId(), ['odo_value' => 999, 'second_value' => null]);

		$this->assertSame(1, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']));

		$found = $this->found('cache');
		$this->assertSame([
			['check' => 'cache', 'table' => 'fleet_vehicles', 'row' => $vehicle->getUuid(), 'vehicle' => $vehicle->getUuid()],
			['check' => 'cache', 'table' => 'fleet_vehicles', 'row' => $vehicle->getUuid(), 'vehicle' => $vehicle->getUuid()],
		], array_map(self::where(...), $found));
		$this->assertStringContainsString('odo_value 999', $found[0]['reason']);
		$this->assertStringContainsString('120000', $found[0]['reason']);
		$this->assertStringContainsString('second_value empty', $found[1]['reason']);
		$this->assertStringContainsString('5000', $found[1]['reason']);
	}

	/** A flag nothing contradicts, and a contradiction with its flag gone (rule 3). */
	public function testAFlagTheChainWouldNotSetIsAFinding(): void {
		$vehicle = $this->vehicle();
		$this->read($vehicle, 120000, readAt: 1750000000);
		$sound = $this->read($vehicle, 120500, readAt: 1750100000);
		$lower = $this->read($vehicle, 119000, readAt: 1750200000);
		$this->update('fleet_odo_readings', (int)$sound->getId(), ['flagged' => true]);
		$this->update('fleet_odo_readings', (int)$lower->getId(), ['flagged' => false]);

		$this->assertSame(1, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']));

		$found = $this->found('flag');
		$this->assertSame([
			['check' => 'flag', 'table' => 'fleet_odo_readings', 'row' => $sound->getUuid(), 'vehicle' => $vehicle->getUuid()],
			['check' => 'flag', 'table' => 'fleet_odo_readings', 'row' => $lower->getUuid(), 'vehicle' => $vehicle->getUuid()],
		], array_map(self::where(...), $found));
		$this->assertStringContainsString('flagged, but the chain does not question it', $found[0]['reason']);
		$this->assertStringContainsString('not flagged, but the chain questions it', $found[1]['reason']);
	}

	/**
	 * A trip has exactly one Reading, which goes where the trip goes (rule 5): either way round,
	 * none at all, or a twin.
	 */
	public function testATripReadingApartFromItsTripIsAFinding(): void {
		$vehicle = $this->vehicle();
		$voided = $this->trip($vehicle, 1750000000, 120000);
		$live = $this->trip($vehicle, 1750100000, 120100);
		$unread = $this->trip($vehicle, 1750200000, 120200);
		$twinned = $this->trip($vehicle, 1750300000, 120300);
		$this->update('fleet_trips', (int)$voided->getId(), ['deleted_at' => 1760000000]);
		$standing = $this->readingOf($vehicle, $voided);
		$gone = $this->readingOf($vehicle, $live);
		$this->update('fleet_odo_readings', (int)$gone->getId(), ['deleted_at' => 1760000000]);
		$this->deleteRow('fleet_odo_readings', (int)$this->readingOf($vehicle, $unread)->getId());
		$first = $this->readingOf($vehicle, $twinned);
		$twin = new OdoReading();
		$twin->setVehicleId((int)$vehicle->getId());
		$twin->setCreatedBy(self::OWNER);
		$twin->setReadAt($first->getReadAt());
		$twin->setReadAtOff(0);
		$twin->setValue($first->getValue());
		$twin->setKind(OdoReading::READING);
		$twin->setOrigin(OdometerService::OBSERVED);
		$twin->setFlagged(false);
		$twin->setSourceType(OdoReading::TRIP);
		$twin->setSourceId((int)$twinned->getId());
		$twin->setCounter(OdoReading::MAIN);
		$twin = \OCP\Server::get(OdoReadingMapper::class)->insert($twin);

		$this->assertSame(1, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']));

		$reasons = [];
		foreach ($this->found('entry') as $finding) {
			$this->assertSame($vehicle->getUuid(), $finding['vehicle']);
			$reasons[$finding['table'] . ' ' . $finding['row']] = $finding['reason'];
		}
		$this->assertSame([
			'fleet_odo_readings ' . $standing->getUuid(),
			'fleet_odo_readings ' . $gone->getUuid(),
			'fleet_odo_readings ' . $first->getUuid(),
			'fleet_odo_readings ' . $twin->getUuid(),
			'fleet_trips ' . $unread->getUuid(),
		], array_keys($reasons));
		$this->assertStringContainsString('live, but its trip ' . $voided->getUuid() . ' is deleted', $reasons['fleet_odo_readings ' . $standing->getUuid()]);
		$this->assertStringContainsString('deleted, but its trip ' . $live->getUuid() . ' is live', $reasons['fleet_odo_readings ' . $gone->getUuid()]);
		$this->assertStringContainsString('one of 2 live Readings its trip ' . $twinned->getUuid() . ' wrote on the main counter', $reasons['fleet_odo_readings ' . $twin->getUuid()]);
		$this->assertStringContainsString('live and states the main counter, but wrote no Reading on it', $reasons['fleet_trips ' . $unread->getUuid()]);
	}

	/**
	 * A fill-up has a Reading per counter it states and none for one it does not: a counter
	 * emptied by an edit leaves a deleted Reading behind, by design.
	 */
	public function testAFillUpReadingApartFromWhatTheFillUpStatesIsAFinding(): void {
		$vehicle = $this->vehicle();
		$energy = \OCP\Server::get(EnergyService::class);
		$emptied = $energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(1750000000, 120000));
		$energy->update(self::OWNER, $vehicle->getUuid(), $emptied['uuid'], $emptied['updated_at'], $this->fillUp(1750000000, null));
		$unstated = $energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(1750100000, 120100));
		$unread = $energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(1750200000, 120200));
		$this->update('fleet_energy', $this->idOf('fleet_energy', $unstated['uuid']), ['odo' => null]);
		$readings = \OCP\Server::get(OdoReadingMapper::class);
		[$gone] = $readings->findAnyForSource((int)$vehicle->getId(), OdoReading::ENERGY, $this->idOf('fleet_energy', $unread['uuid']));
		$this->update('fleet_odo_readings', (int)$gone->getId(), ['deleted_at' => 1760000000]);
		$none = $energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(1750300000, 120300));
		[$lost] = $readings->findAnyForSource((int)$vehicle->getId(), OdoReading::ENERGY, $this->idOf('fleet_energy', $none['uuid']));
		$this->deleteRow('fleet_odo_readings', (int)$lost->getId());

		$this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']);

		$found = $this->found('entry');
		$this->assertSame(['fleet_odo_readings', 'fleet_odo_readings', 'fleet_energy'], array_column($found, 'table'));
		$this->assertStringContainsString('live, but its fill-up ' . $unstated['uuid'] . ' states no main counter', $found[0]['reason']);
		$this->assertStringContainsString('deleted, but its fill-up ' . $unread['uuid'] . ' is live', $found[1]['reason']);
		$this->assertSame($none['uuid'], $found[2]['row']);
	}

	/** Each of the twins, since which one to revoke is the admin's call. */
	public function testTwoLiveGrantsToOneGranteeAreAFinding(): void {
		$vehicle = $this->vehicle();
		[$grant] = \OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::ANNA, 'grantee_type' => 'user', 'role' => 'driver']);
		$twin = new Access();
		$twin->setVehicleId((int)$vehicle->getId());
		$twin->setGrantee(self::ANNA);
		$twin->setGranteeType(Access::USER);
		$twin->setRole('viewer');
		$twin->setCreatedBy(self::OWNER);
		$twin = \OCP\Server::get(AccessMapper::class)->insert($twin);

		$this->assertSame(1, $this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']));

		$found = $this->found('duplicate');
		$this->assertSame([
			['check' => 'duplicate', 'table' => 'fleet_access', 'row' => $grant['uuid'], 'vehicle' => $vehicle->getUuid()],
			['check' => 'duplicate', 'table' => 'fleet_access', 'row' => $twin->getUuid(), 'vehicle' => $vehicle->getUuid()],
		], array_map(self::where(...), $found));
		$this->assertStringContainsString('one of 2 live grants to the user ' . self::ANNA, $found[0]['reason']);
	}

	/**
	 * A grantee gone with nothing under way to revoke the grant. An erasure or a group's revokes
	 * marked pending will, and an erased account's pseudonym is how an erasure leaves a grant.
	 * Only today's: one from before 0.3.0 is a uid an account could still take.
	 */
	public function testALiveGrantToAGoneGranteeIsAFinding(): void {
		$vehicle = $this->vehicle();
		$grants = \OCP\Server::get(GrantService::class);
		$gone = [];
		foreach ([
			[self::ANNA, Access::USER, self::NOBODY, true],
			[self::CREW, Access::GROUP, self::NOBODY, true],
			[self::ANNA, Access::USER, self::ERASING, false],
			[self::CREW, Access::GROUP, self::ERASING, false],
			[self::ANNA, Access::USER, ErasureService::PREFIX . 'abcdefghij0123456789', false],
			// No account holds it and VehicleAccess does not refuse it, so an account made under it
			// would take the grant. The pseudonym check, which names it too, is skipped for one vehicle.
			[self::ANNA, Access::USER, self::OLD, true],
		] as [$grantee, $type, $renamed, $finding]) {
			$list = $grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => $type, 'role' => 'viewer']);
			$grant = end($list) ?: throw new \RuntimeException('no grant');
			$this->update('fleet_access', $this->idOf('fleet_access', $grant['uuid']), ['grantee' => $renamed]);
			if ($finding) {
				$gone[] = $grant['uuid'];
			}
		}
		$pending = \OCP\Server::get(Pending::class);
		$pending->begin(Pending::ERASURE, self::ERASING);
		$pending->begin(Pending::GROUP, self::ERASING);

		try {
			$this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']);
		} finally {
			$pending->end(Pending::ERASURE, self::ERASING);
			$pending->end(Pending::GROUP, self::ERASING);
		}

		$found = $this->found('grantee');
		$this->assertSame($gone, array_column($found, 'row'));
		$this->assertStringContainsString('no backend knows the user ' . self::NOBODY, $found[0]['reason']);
		$this->assertStringContainsString('no backend knows the group ' . self::NOBODY, $found[1]['reason']);
	}

	/**
	 * There are no foreign keys. A deleted vehicle's rows stay on purpose ("Nothing purges yet"),
	 * so only a vehicle_id naming no row at all is an orphan.
	 */
	public function testARowOfNoVehicleIsAFindingAndOneOfADeletedVehicleIsNot(): void {
		$expenses = \OCP\Server::get(ExpenseService::class);
		$kept = $this->vehicle();
		$orphan = $expenses->record(self::OWNER, $kept->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 0, 'amount' => 64000]);
		$this->update('fleet_expenses', $this->idOf('fleet_expenses', $orphan['uuid']), ['vehicle_id' => self::NO_VEHICLE]);
		$trashed = $this->vehicle(['plate' => 'NF-CK 2']);
		$child = $expenses->record(self::OWNER, $trashed->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 0, 'amount' => 100]);
		$trashed = $this->vehicles->delete(self::OWNER, $trashed->getUuid(), $trashed->getUpdatedAt());

		$this->assertSame(1, $this->command->execute(['--output' => 'json']));

		$found = array_values(array_filter($this->found('orphan'), static fn (array $finding): bool => in_array($finding['row'], [$orphan['uuid'], $child['uuid']], true)));
		$this->assertSame([['check' => 'orphan', 'table' => 'fleet_expenses', 'row' => $orphan['uuid'], 'vehicle' => null]], array_map(self::where(...), $found));
		$this->assertStringContainsString('vehicle_id ' . self::NO_VEHICLE . ' names no vehicle', $found[0]['reason']);
	}

	/**
	 * The upgrade renames an old pseudonym; one a live account carries it keeps and names in its
	 * output, so here it is a warning. A check of one vehicle leaves both out: they are the
	 * instance's.
	 */
	public function testAnOldPseudonymIsAFindingAndOneAnAccountCarriesAWarning(): void {
		\OCP\Server::get(IUserManager::class)->createUser(self::KEPT, bin2hex(random_bytes(16)));
		$vehicle = $this->vehicle();
		$old = $this->trip($vehicle, 1750000000, 120000);
		$kept = $this->trip($vehicle, 1750100000, 120100);
		$this->update('fleet_trips', (int)$old->getId(), ['created_by' => self::OLD]);
		$this->update('fleet_trips', (int)$kept->getId(), ['created_by' => self::KEPT]);

		$this->assertSame(1, $this->command->execute(['--output' => 'json']));

		$ours = static fn (array $finding): bool => str_contains($finding['reason'], 'nfcheck');
		$found = array_values(array_filter($this->found('pseudonym'), $ours));
		$warned = array_values(array_filter($this->found('pseudonym', 'warnings'), $ours));
		$this->assertCount(1, $found);
		$this->assertStringContainsString(self::OLD, $found[0]['reason']);
		$this->assertCount(1, $warned);
		$this->assertStringContainsString(self::KEPT, $warned[0]['reason']);

		$this->command->execute([]);
		$this->assertStringContainsString('Warning: the account ' . self::KEPT, $this->command->getDisplay());

		$this->command->execute(['--vehicle' => $vehicle->getUuid(), '--output' => 'json']);
		$this->assertSame([], $this->found('pseudonym'));
		$this->assertSame([], $this->found('pseudonym', 'warnings'));
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(array $fields = []): Vehicle {
		return $this->vehicles->create(self::OWNER, $fields + ['plate' => 'NF-CK 1']);
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

	private function trip(Vehicle $vehicle, int $startedAt, int $endOdo): Trip {
		return \OCP\Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'started_at' => $startedAt,
			'started_at_off' => 0,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 0,
			'category' => Trip::BUSINESS,
			'end_odo' => $endOdo,
		]);
	}

	private function readingOf(Vehicle $vehicle, Trip $trip): OdoReading {
		return \OCP\Server::get(OdoReadingMapper::class)->findAnyForTrip((int)$vehicle->getId(), (int)$trip->getId())
			?? throw new \RuntimeException('the trip wrote no Reading');
	}

	/** @return array<string, mixed> */
	private function fillUp(int $filledAt, ?int $odo): array {
		return ['filled_at' => $filledAt, 'filled_at_off' => 0, 'energy' => 'diesel', 'amount' => 42000, 'total' => 7350, 'full_tank' => true, 'odo' => $odo];
	}

	private function deleteRow(string $table, int $id): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function idOf(string $table, string $uuid): int {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return (int)$qb->executeQuery()->fetchOne();
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
	 * One check's findings in the last JSON run.
	 *
	 * @return list<array{check: string, table: ?string, row: ?string, vehicle: ?string, reason: string}>
	 */
	private function found(string $check, string $key = 'findings'): array {
		/** @var array{findings: list<array{check: string, table: ?string, row: ?string, vehicle: ?string, reason: string}>, warnings: list<array{check: string, table: ?string, row: ?string, vehicle: ?string, reason: string}>} $run */
		$run = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

		return array_values(array_filter($run[$key], static fn (array $finding): bool => $finding['check'] === $check));
	}

	/**
	 * Where a finding points, its wording left out.
	 *
	 * @param array{check: string, table: ?string, row: ?string, vehicle: ?string, reason: string} $finding
	 * @return array{check: string, table: ?string, row: ?string, vehicle: ?string}
	 */
	private static function where(array $finding): array {
		unset($finding['reason']);

		return $finding;
	}
}
