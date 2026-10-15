<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TypedText;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every column that holds typed text takes as many characters as its field allows, whatever
 * letters they are. The app bounds characters; Oracle bounds bytes unless the column is wide
 * enough for four per character (docs/architecture.md#data-model).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TypedTextTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-typed';

	/** Table => field => the length its sheet allows, in characters. */
	private const FIELDS = [
		'fleet_vehicles' => [
			'plate' => 32, 'display_name' => 64, 'manufacturer' => 64, 'model' => 64,
			'vin' => 32, 'color' => 32, 'jurisdiction' => 8,
		],
		'fleet_trips' => ['from_label' => 255, 'to_label' => 255, 'purpose' => 255, 'partner' => 255],
		'fleet_energy' => ['station' => 255],
		'fleet_maintenance' => ['title' => 255, 'vendor' => 255],
		'fleet_reminders' => ['title' => 255],
	];

	private VehicleService $vehicles;
	private TimelineService $timeline;

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->timeline = \OCP\Server::get(TimelineService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles', 'fleet_reminder_recipients', 'fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_reminders', 'fleet_audit'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * Two bytes a character, and four: the most UTF-8 spends on one.
	 *
	 * @return array<string, array{string}>
	 */
	public static function letters(): array {
		return ['two bytes' => ['ü'], 'four bytes' => ['🚗']];
	}

	/** The columns this suite writes are the ones Oracle holds at 4n. */
	public function testWritesEveryColumnOfTypedText(): void {
		$this->assertSame(TypedText::COLUMNS, self::FIELDS);
	}

	#[DataProvider('letters')]
	public function testAVehicleHoldsEveryTextFieldAtFullLength(string $letter): void {
		$fields = $this->full($letter, 'fleet_vehicles');

		$uuid = $this->vehicles->create(self::OWNER, $fields)->getUuid();

		$this->assertHolds($fields, $this->vehicles->find(self::OWNER, $uuid)->jsonSerialize());
	}

	#[DataProvider('letters')]
	public function testATripHoldsEveryTextFieldAtFullLength(string $letter): void {
		$uuid = $this->vehicle();
		$fields = $this->full($letter, 'fleet_trips');

		$trip = \OCP\Server::get(TripService::class)->record(self::OWNER, $uuid, $fields + [
			'started_at' => 1750000000, 'started_at_off' => 120,
			'ended_at' => 1750005400, 'ended_at_off' => 120,
			'category' => Trip::BUSINESS, 'start_odo' => 1000, 'end_odo' => 1042,
		]);

		$this->assertRead($fields, 'trip', $uuid, $trip->getUuid());
	}

	#[DataProvider('letters')]
	public function testAFillUpHoldsItsStationAtFullLength(string $letter): void {
		$uuid = $this->vehicle();
		$fields = $this->full($letter, 'fleet_energy');

		$written = \OCP\Server::get(EnergyService::class)->record(self::OWNER, $uuid, $fields + [
			'filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000,
		]);

		$this->assertRead($fields, 'energy', $uuid, (string)$written['uuid']);
	}

	#[DataProvider('letters')]
	public function testAMaintenanceRecordHoldsItsTitleAndVendorAtFullLength(string $letter): void {
		$uuid = $this->vehicle();
		$fields = $this->full($letter, 'fleet_maintenance');

		$written = \OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $uuid, $fields + [
			'done_at' => 1750000000, 'done_at_off' => 120,
		]);

		$this->assertRead($fields, 'maintenance', $uuid, (string)$written['uuid']);
	}

	#[DataProvider('letters')]
	public function testAReminderHoldsItsTitleAtFullLength(string $letter): void {
		$uuid = $this->vehicle();
		$fields = $this->full($letter, 'fleet_reminders');
		$reminders = \OCP\Server::get(ReminderService::class);

		$written = $reminders->create(self::OWNER, $uuid, $fields + ['mode' => Reminder::DATE, 'due_date' => '2027-03-31']);

		$listed = array_values(array_filter($reminders->list(self::OWNER, $uuid), static fn (array $row): bool => $row['uuid'] === $written['uuid']));
		$this->assertCount(1, $listed);
		$this->assertSame($fields['title'], $listed[0]['title']);
	}

	private function vehicle(): string {
		return $this->vehicles->create(self::OWNER, ['plate' => 'NF-TT 1'])->getUuid();
	}

	/**
	 * The entry as the timeline reads it back from the database.
	 *
	 * @param array<string, string> $fields column => what was sent
	 */
	private function assertRead(array $fields, string $type, string $vehicleUuid, string $entryUuid): void {
		$entry = $this->timeline->one(self::OWNER, $vehicleUuid, $type, $entryUuid)[$type];
		$this->assertIsObject($entry);
		$this->assertInstanceOf(\JsonSerializable::class, $entry);
		$this->assertHolds($fields, (array)$entry->jsonSerialize());
	}

	/**
	 * @param array<string, string> $fields
	 * @param array<array-key, mixed> $row
	 */
	private function assertHolds(array $fields, array $row): void {
		$read = array_intersect_key($row, $fields);
		ksort($fields);
		ksort($read);
		$this->assertSame($fields, $read);
	}

	/**
	 * Each of the table's fields filled to its length with the letter. MariaDB without utf8mb4
	 * stores no four-byte character at all, which is Nextcloud's setting, not a column too short.
	 *
	 * @return array<string, string> column => that many of the letter
	 */
	private function full(string $letter, string $table): array {
		if (strlen($letter) === 4 && !\OCP\Server::get(IDBConnection::class)->supports4ByteText()) {
			$this->markTestSkipped('the database stores no four-byte characters');
		}

		return array_map(static fn (int $length): string => str_repeat($letter, $length), self::FIELDS[$table]);
	}
}
