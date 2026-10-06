<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The CSV export against the real database: which rows a year's file holds and what a
 * spreadsheet reads out of them.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ExportTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-mallory';
	/** An account, since a grantee has to exist on the instance. */
	private const DRIVER = 'nextfleet-test-export-ben';

	private ExportService $export;
	private GrantService $grants;
	private TripService $trips;
	private EnergyService $energy;
	private MaintenanceService $maintenance;
	private ExpenseService $expenses;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::DRIVER)?->delete();
		$users->createUser(self::DRIVER, bin2hex(random_bytes(16)))?->setDisplayName('Ben Fahrer');
	}

	public static function tearDownAfterClass(): void {
		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();
	}

	protected function setUp(): void {
		$this->export = \OCP\Server::get(ExportService::class);
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->energy = \OCP\Server::get(EnergyService::class);
		$this->maintenance = \OCP\Server::get(MaintenanceService::class);
		$this->expenses = \OCP\Server::get(ExpenseService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = [
			'fleet_vehicles' => 'user_id',
			'fleet_trips' => 'created_by',
			'fleet_audit' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_energy' => 'created_by',
			'fleet_maintenance' => 'created_by',
			'fleet_expenses' => 'created_by',
			'fleet_access' => 'created_by',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter([self::OWNER, self::DRIVER], $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	private function vehicle(): string {
		return $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 127',
			'jurisdiction' => 'de',
			'energy_types' => ['diesel'],
			'currency' => 'EUR',
		])->getUuid();
	}

	/** @param array<string, mixed> $fields */
	private function trip(string $uuid, int $startedAt, array $fields): Trip {
		return $this->trips->record(self::OWNER, $uuid, $fields + [
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 60,
			'category' => Trip::BUSINESS,
		]);
	}

	/**
	 * What a spreadsheet reads out of the file: one map per row, keyed by the header.
	 *
	 * @return list<array<string, string>>
	 */
	private function read(string $csv): array {
		$this->assertStringStartsWith("\u{FEFF}", $csv);
		$stream = fopen('php://memory', 'r+');
		$this->assertNotFalse($stream);
		fwrite($stream, substr($csv, 3));
		rewind($stream);

		$header = fgetcsv($stream, escape: '');
		$this->assertIsArray($header);
		$rows = [];
		while (($row = fgetcsv($stream, escape: '')) !== false) {
			$rows[] = array_combine($header, $row);
		}

		return $rows;
	}

	/**
	 * A year's trips are those that set off in it on the local clock, as the Fahrtenbuch counts
	 * them. A voided one stays in the file, marked: a gap is what an auditor asks about.
	 */
	public function testTheYearsTripsAreThoseThatSetOffInItVoidedOnesMarked(): void {
		$uuid = $this->vehicle();
		$this->trip($uuid, gmmktime(8, 0, 0, 12, 20, 2024), ['start_odo' => 1000, 'end_odo' => 1100]);
		// 23:30 UTC on New Year's Eve is half past midnight in Berlin: 2025's.
		$entered = $this->trip($uuid, gmmktime(23, 30, 0, 12, 31, 2024), [
			'start_odo' => 1100,
			'end_odo' => 1250,
			'from_label' => 'Berlin',
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
		]);
		$voided = $this->trip($uuid, gmmktime(8, 0, 0, 2, 3, 2025), ['start_odo' => 1250, 'end_odo' => 1300, 'category' => Trip::PRIVATE]);
		$this->trips->delete(self::OWNER, $uuid, $voided->getUuid(), $voided->getUpdatedAt());
		$this->trip($uuid, gmmktime(8, 0, 0, 12, 31, 2025), ['distance' => 40, 'category' => Trip::COMMUTE]);

		$file = $this->export->csv(self::OWNER, $uuid, 2025, 'trips');

		$this->assertSame('B-XY_127-2025-trips.csv', $file['name']);
		$rows = $this->read($file['body']);
		$this->assertSame(['2025-01-01 00:30', '2025-02-03 09:00', '2025-12-31 09:00'], array_column($rows, 'started'));
		$this->assertSame([
			'started' => '2025-01-01 00:30',
			'started_offset_min' => '60',
			'ended' => '2025-01-01 02:00',
			'ended_offset_min' => '60',
			'start_odo' => '1100',
			'end_odo' => '1250',
			'distance' => '',
			'odo_unit' => 'km',
			'from' => 'Berlin',
			'to' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => 'business',
			'reconciled' => '0',
			'voided' => '0',
			'created_at' => gmdate('Y-m-d H:i:s', $entered->getCreatedAt()),
			// Nobody else ever reached this vehicle, so naming its owner says nothing.
			'entered_by' => '',
		], array_diff_key($rows[0], ['uuid' => null]));
		$this->assertSame(['0', '1', '0'], array_column($rows, 'voided'));
		$this->assertSame($voided->getUuid(), $rows[1]['uuid']);
		$this->assertSame('40', $rows[2]['distance']);
	}

	/** By display name, as the timeline and the Fahrtenbuch do, and still after the revoke. */
	public function testOnceAccessWasGivenEachTripSaysWhoEnteredIt(): void {
		$uuid = $this->vehicle();
		$grants = $this->grants->grant(self::OWNER, $uuid, ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->trip($uuid, gmmktime(8, 0, 0, 3, 1, 2025), ['start_odo' => 1000, 'end_odo' => 1012]);
		$this->trips->record(self::DRIVER, $uuid, [
			'started_at' => gmmktime(8, 0, 0, 3, 2, 2025),
			'started_at_off' => 60,
			'ended_at' => gmmktime(9, 0, 0, 3, 2, 2025),
			'ended_at_off' => 60,
			'start_odo' => 1012,
			'end_odo' => 1040,
			'category' => Trip::PRIVATE,
		]);
		$this->grants->revoke(self::OWNER, $uuid, $grants[0]['uuid']);

		$rows = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'trips')['body']);

		// The owner here has no account, so their uid stands for the name.
		$this->assertSame([self::OWNER, 'Ben Fahrer'], array_column($rows, 'entered_by'));
	}

	/** By the trips' rule: empty on a vehicle never shared. */
	public function testFillUpsMaintenanceAndExpensesSayWhoEnteredEachRow(): void {
		$uuid = $this->vehicle();
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => gmmktime(9, 0, 0, 2, 1, 2025), 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => 10000]);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => gmmktime(9, 0, 0, 3, 1, 2025), 'done_at_off' => 60, 'title' => 'Oil change', 'cost' => 19000, 'odo' => 10400]);
		$this->expenses->record(self::OWNER, $uuid, ['spent_at' => gmmktime(9, 0, 0, 4, 1, 2025), 'spent_at_off' => 120, 'category' => 'insurance', 'amount' => 30000]);
		foreach (['energy', 'maintenance', 'expenses'] as $table) {
			$this->assertSame([''], array_column($this->read($this->export->csv(self::OWNER, $uuid, 2025, $table)['body']), 'entered_by'), $table . ', never shared');
		}

		$this->grants->grant(self::OWNER, $uuid, ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->energy->record(self::DRIVER, $uuid, ['filled_at' => gmmktime(9, 0, 0, 2, 2, 2025), 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 30000, 'total' => 4500, 'full_tank' => true, 'odo' => 10300]);
		$this->maintenance->record(self::DRIVER, $uuid, ['done_at' => gmmktime(9, 0, 0, 3, 2, 2025), 'done_at_off' => 60, 'title' => 'Wipers', 'cost' => 2000, 'odo' => 10500]);
		$this->expenses->record(self::DRIVER, $uuid, ['spent_at' => gmmktime(9, 0, 0, 4, 2, 2025), 'spent_at_off' => 120, 'category' => 'parking', 'amount' => 300]);

		foreach (['energy', 'maintenance', 'expenses'] as $table) {
			$rows = $this->read($this->export->csv(self::OWNER, $uuid, 2025, $table)['body']);
			$this->assertSame([self::OWNER, 'Ben Fahrer'], array_column($rows, 'entered_by'), $table);
		}
	}

	/** New Year's Eve 23:30 UTC is next year in Berlin: each file counts its rows on the clock they were entered on. */
	public function testEachFileTakesTheYearOnTheEntrysOwnClock(): void {
		$uuid = $this->vehicle();
		foreach ([2024 => 10000, 2025 => 20000] as $year => $odo) {
			$at = gmmktime(23, 30, 0, 12, 31, $year);
			$this->trip($uuid, $at, ['start_odo' => $odo, 'end_odo' => $odo + 10]);
			$this->energy->record(self::OWNER, $uuid, ['filled_at' => $at, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => $odo + 20]);
			$this->maintenance->record(self::OWNER, $uuid, ['done_at' => $at, 'done_at_off' => 60, 'title' => 'Oil change', 'cost' => 19000]);
			$this->expenses->record(self::OWNER, $uuid, ['spent_at' => $at, 'spent_at_off' => 60, 'category' => 'parking', 'amount' => 300]);
		}

		foreach (['trips' => 'started', 'energy' => 'filled', 'maintenance' => 'done', 'expenses' => 'spent'] as $table => $column) {
			$rows = $this->read($this->export->csv(self::OWNER, $uuid, 2025, $table)['body']);
			$this->assertSame(['2025-01-01 00:30'], array_column($rows, $column), $table);
		}
	}

	/** A purpose is the user's, and a colleague's Excel must read it as text. */
	public function testATripsPurposeCannotRunAsAFormula(): void {
		$uuid = $this->vehicle();
		$this->trip($uuid, gmmktime(8, 0, 0, 3, 1, 2025), ['start_odo' => 1000, 'end_odo' => 1012, 'purpose' => '=HYPERLINK("http://evil.example")']);

		$rows = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'trips')['body']);

		$this->assertSame('\'=HYPERLINK("http://evil.example")', $rows[0]['purpose']);
	}

	/** Each money column beside its currency, every figure the integer stored. */
	public function testFillUpsMaintenanceAndExpensesAreAFileEach(): void {
		$uuid = $this->vehicle();
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => gmmktime(9, 0, 0, 2, 1, 2025), 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => 10000, 'station' => 'Aral']);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => gmmktime(9, 0, 0, 3, 1, 2025), 'done_at_off' => 60, 'title' => 'Oil change', 'vendor' => 'Werkstatt Meyer', 'cost' => 19000, 'odo' => 10400]);
		$this->expenses->record(self::OWNER, $uuid, ['spent_at' => gmmktime(9, 0, 0, 4, 1, 2025), 'spent_at_off' => 120, 'category' => 'insurance', 'amount' => 30000]);
		$deleted = $this->expenses->record(self::OWNER, $uuid, ['spent_at' => gmmktime(9, 0, 0, 5, 1, 2025), 'spent_at_off' => 120, 'category' => 'tax', 'amount' => 5000]);
		$this->expenses->delete(self::OWNER, $uuid, $deleted['uuid'], $deleted['updated_at']);

		$energy = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'energy')['body']);
		$maintenance = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'maintenance')['body']);
		$expenses = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'expenses')['body']);

		$this->assertCount(1, $energy);
		$this->assertSame(['2025-02-01 10:00', 'diesel', '40000', '6000', 'EUR', '1', 'Aral', '10000', 'km'], [
			$energy[0]['filled'], $energy[0]['energy'], $energy[0]['amount'], $energy[0]['total'], $energy[0]['currency'],
			$energy[0]['full_tank'], $energy[0]['station'], $energy[0]['odo'], $energy[0]['odo_unit'],
		]);
		$this->assertSame(['2025-03-01 10:00', 'Oil change', 'Werkstatt Meyer', '19000', 'EUR'], [
			$maintenance[0]['done'], $maintenance[0]['title'], $maintenance[0]['vendor'], $maintenance[0]['cost'], $maintenance[0]['currency'],
		]);
		// A deleted expense is gone; only a trip is voided rather than deleted.
		$this->assertSame([['2025-04-01 11:00', '120', 'insurance', '30000', 'EUR']], array_map(
			static fn (array $row): array => [$row['spent'], $row['spent_offset_min'], $row['category'], $row['amount'], $row['currency']],
			$expenses,
		));
	}

	/** A bulk read takes the check a single row does (docs/security.md). */
	public function testAStrangerExportsNothing(): void {
		$uuid = $this->vehicle();

		$this->expectException(AccessDeniedException::class);
		$this->export->csv(self::STRANGER, $uuid, 2025, 'trips');
	}
}
