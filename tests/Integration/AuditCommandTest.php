<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\AuditCommand;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:audit`: one vehicle's audit trail, as an admin reads it.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class AuditCommandTest extends TestCase {
	/** Not an account: `user_id` and `created_by` are string columns. */
	private const OWNER = 'nextfleet-test-audit-owner';

	private VehicleService $vehicles;
	private TripService $trips;
	private CommandTester $command;

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->command = new CommandTester(\OCP\Server::get(AuditCommand::class));
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Every row this suite writes is the owner's. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_trips', 'fleet_audit', 'fleet_odo_readings', 'fleet_access', 'fleet_reminder_recipients'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER, IQueryBuilder::PARAM_STR)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
	}

	/** The vehicle's own rows and its trips' rows are one trail, in the order they were written. */
	public function testItPrintsTheVehicleAndItsTripsOldestFirst(): void {
		$vehicle = $this->underLogbookMode();
		$trip = $this->trip($vehicle, 1750000000);

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']), $this->command->getDisplay());

		$listed = $this->listed();
		$this->assertCount(2, $listed);
		$this->assertSame(['created_at', 'created_by', 'entity', 'uuid', 'change', 'fields'], array_keys($listed[0]));
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)$listed[0]['created_at']);
		$this->assertSame([self::OWNER, 'vehicle', $vehicle->getUuid(), 'switched', ['logbook_mode' => ['before' => false, 'after' => true]]], array_slice(array_values($listed[0]), 1));
		$this->assertSame([self::OWNER, 'trip', $trip->getUuid(), 'created'], array_slice(array_values($listed[1]), 1, 4));
		$this->assertSame(['before' => null, 'after' => 120450], $listed[1]['fields']['end_odo']);
	}

	/** A trip's uuid gives that trip's rows, the vehicle's own uuid the vehicle's. */
	public function testEntryNarrowsToTheTrailOfOneRow(): void {
		$vehicle = $this->underLogbookMode();
		$first = $this->trip($vehicle, 1750000000);
		$second = $this->trip($vehicle, 1750100000);
		$this->trips->delete(self::OWNER, $vehicle->getUuid(), $first->getUuid(), $first->getUpdatedAt());

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--entry' => $first->getUuid(), '--output' => 'json']));
		$this->assertSame([[$first->getUuid(), 'created'], [$first->getUuid(), 'voided']], $this->uuidsAndChanges());

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--entry' => $vehicle->getUuid(), '--output' => 'json']);
		$this->assertSame([[$vehicle->getUuid(), 'switched']], $this->uuidsAndChanges());

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']);
		$this->assertSame([$vehicle->getUuid(), $first->getUuid(), $second->getUuid(), $first->getUuid()], array_column($this->listed(), 'uuid'));
	}

	/** No service writes a row without fields, but a script reading `fields` as a map must get one from any row. */
	public function testFieldsIsAMapEvenWhenTheRowHasNone(): void {
		$vehicle = $this->underLogbookMode();
		$trip = $this->trip($vehicle, 1750000000);
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_audit')
			->set('diff_json', $qb->createNamedParameter('{"change":"created"}'))
			->where($qb->expr()->eq('entity', $qb->createNamedParameter('trip')))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter((int)$trip->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']);

		/** @var list<\stdClass> $rows */
		$rows = json_decode($this->command->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(['switched', 'created'], array_column($rows, 'change'));
		foreach ($rows as $row) {
			$this->assertInstanceOf(\stdClass::class, $row->fields, (string)$row->change);
		}
	}

	/** On stderr, so stdout stays one JSON document or nothing. */
	public function testAnEntryOfAnotherVehicleIsBadInput(): void {
		$vehicle = $this->underLogbookMode();
		$other = $this->underLogbookMode();
		$theirs = $this->trip($other, 1750000000);

		foreach ([$theirs->getUuid(), $other->getUuid(), 'no-such-uuid'] as $entry) {
			$this->assertSame(2, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--entry' => $entry, '--output' => 'json'], ['capture_stderr_separately' => true]), $entry);
			$this->assertSame('', $this->command->getDisplay());
			$this->assertStringContainsString($entry, $this->command->getErrorOutput());
		}
	}

	/** A day starts at midnight UTC, the zone every printed instant is in. */
	public function testSinceKeepsTheRowsFromTheStartOfThatDay(): void {
		// Before the writes, so a run across midnight still finds the trip's row on or after it.
		$today = gmdate('Y-m-d');
		$vehicle = $this->underLogbookMode();
		$trip = $this->trip($vehicle, 1750000000);
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_audit')
			->set('created_at', $qb->createNamedParameter(strtotime($today . 'T00:00:00Z') - 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('entity', $qb->createNamedParameter('vehicle')))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter((int)$vehicle->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--since' => $today, '--output' => 'json']));
		$this->assertSame([$trip->getUuid()], array_column($this->listed(), 'uuid'));

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--since' => gmdate('Y-m-d', strtotime($today . 'T00:00:00Z') - 1), '--output' => 'json']);
		$this->assertSame([$vehicle->getUuid(), $trip->getUuid()], array_column($this->listed(), 'uuid'));
	}

	public function testASinceThatIsNoDateIsBadInput(): void {
		$vehicle = $this->vehicle();

		foreach (['yesterday', '2026-02-31', '2026-1-5'] as $since) {
			$this->assertSame(2, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--since' => $since], ['capture_stderr_separately' => true]), $since);
			$this->assertStringContainsString($since, $this->command->getErrorOutput());
		}
	}

	/** A field per line, values as JSON writes them, so a string and a number never look alike. */
	public function testPlainIsATableWithAFieldPerLine(): void {
		$vehicle = $this->vehicle();
		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['plate' => 'NF-AU 2', 'logbook_mode' => true]);

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid()]));

		$display = $this->command->getDisplay();
		$this->assertMatchesRegularExpression('/\|\s*created_at\s*\|\s*created_by\s*\|\s*entity\s*\|\s*uuid\s*\|\s*change\s*\|\s*fields\s*\|/', $display);
		$this->assertMatchesRegularExpression('/\|\s*' . self::OWNER . '\s*\|\s*vehicle\s*\|\s*' . $vehicle->getUuid() . '\s*\|\s*switched\s*\|\s*plate: "NF-AU 1" → "NF-AU 2"\s*\|/u', $display);
		$this->assertMatchesRegularExpression('/\|\s*logbook_mode: false → true\s*\|/u', $display);
	}

	public function testAnEmptyTrailSaysSo(): void {
		$vehicle = $this->vehicle();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid()]));
		$this->assertSame('No audit rows for this vehicle.', trim($this->command->getDisplay()));

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']);
		$this->assertSame([], $this->listed());
	}

	/** In the trash, a vehicle keeps its trail. */
	public function testAVehicleInTheTrashStillAnswers(): void {
		$vehicle = $this->underLogbookMode();
		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), \OCP\Server::get(VehicleMapper::class)->findByUuid($vehicle->getUuid())->getUpdatedAt());

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']));
		$this->assertSame([[$vehicle->getUuid(), 'switched']], $this->uuidsAndChanges());
	}

	public function testAnUnknownVehicleOrOutputIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['vehicle' => 'no-such-uuid', '--output' => 'json'], ['capture_stderr_separately' => true]));
		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('no-such-uuid', $this->command->getErrorOutput());

		$this->assertSame(2, $this->command->execute(['vehicle' => $this->vehicle()->getUuid(), '--output' => 'xml']));
	}

	/** Off the mode a vehicle's write records nothing; a trail can be empty. */
	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'NF-AU 1']);
	}

	/** Switched on as the vehicle sheet does, which is the vehicle's first audit row. */
	private function underLogbookMode(): Vehicle {
		$vehicle = $this->vehicle();

		return $this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['logbook_mode' => true]);
	}

	private function trip(Vehicle $vehicle, int $startedAt): Trip {
		return $this->trips->record(self::OWNER, $vehicle->getUuid(), [
			'started_at' => $startedAt,
			'started_at_off' => 0,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 0,
			'category' => Trip::BUSINESS,
			'end_odo' => 120450,
		]);
	}

	/** @return list<array{mixed, mixed}> */
	private function uuidsAndChanges(): array {
		return array_map(static fn (array $row): array => [$row['uuid'], $row['change']], $this->listed());
	}

	/** @return list<array<string, mixed>> */
	private function listed(): array {
		/** @var list<array<string, mixed>> */
		return json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
	}
}
