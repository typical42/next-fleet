<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The CSV export against the real database, written the way the app writes it. What is checked
 * is which rows a year's file holds and what a spreadsheet reads out of them.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ExportTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-mallory';

	private ExportService $export;
	private TripService $trips;
	private EnergyService $energy;
	private MaintenanceService $maintenance;
	private ExpenseService $expenses;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->export = $container->get(ExportService::class);
		$this->trips = $container->get(TripService::class);
		$this->energy = $container->get(EnergyService::class);
		$this->maintenance = $container->get(MaintenanceService::class);
		$this->expenses = $container->get(ExpenseService::class);
		$this->vehicles = $container->get(VehicleService::class);
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
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
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
	 * A year's trips are those that set off in it where they set off, the way the Fahrtenbuch
	 * counts them. A voided one is in the file and says so: a gap in an export is exactly what an
	 * auditor asks about.
	 */
	public function testTheYearsTripsAreThoseThatSetOffInItVoidedOnesMarked(): void {
		$uuid = $this->vehicle();
		$this->trip($uuid, gmmktime(8, 0, 0, 12, 20, 2024), ['start_odo' => 1000, 'end_odo' => 1100]);
		// 23:30 UTC on New Year's Eve is half past midnight in Berlin: 2025's.
		$this->trip($uuid, gmmktime(23, 30, 0, 12, 31, 2024), [
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
		], array_diff_key($rows[0], ['uuid' => null]));
		$this->assertSame(['0', '1', '0'], array_column($rows, 'voided'));
		$this->assertSame($voided->getUuid(), $rows[1]['uuid']);
		$this->assertSame('40', $rows[2]['distance']);
	}

	/** A purpose is the user's, and a colleague's Excel must read it as text. */
	public function testATripsPurposeCannotRunAsAFormula(): void {
		$uuid = $this->vehicle();
		$this->trip($uuid, gmmktime(8, 0, 0, 3, 1, 2025), ['start_odo' => 1000, 'end_odo' => 1012, 'purpose' => '=HYPERLINK("http://evil.example")']);

		$rows = $this->read($this->export->csv(self::OWNER, $uuid, 2025, 'trips')['body']);

		$this->assertSame('\'=HYPERLINK("http://evil.example")', $rows[0]['purpose']);
	}

	/** One file per table, each money column beside its currency, every figure the integer stored. */
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
