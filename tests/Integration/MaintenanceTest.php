<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A Maintenance Record against the real database: the row, the Readings its counters write, and
 * what the sheet is prefilled with. The counter rules are the fill-up's (EnergyTest).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class MaintenanceTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private MaintenanceService $maintenance;
	private OdometerService $odometer;
	private VehicleService $vehicles;
	private ReminderService $reminders;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->maintenance = $container->get(MaintenanceService::class);
		$this->reminders = $container->get(ReminderService::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_reminders' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/** Rule 5 for maintenance: the counter it was given is an Observed Reading at `done_at`. */
	public function testARecordWithACounterWritesAnObservedReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['odo' => 120450]));

		$this->assertSame('Oil change', $written['title']);
		$this->assertSame(18990, $written['cost']);
		$readings = $this->odometer->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(1, $readings);
		$this->assertSame(120450, $readings[0]->getValue());
		$this->assertSame(1750000000, $readings[0]->getReadAt());
		$this->assertSame(120, $readings[0]->getReadAtOff());
		$this->assertSame(OdometerService::OBSERVED, $readings[0]->getOrigin());
		$this->assertSame(OdoReading::MAINTENANCE, $readings[0]->getSourceType());
		$this->assertSame(120450, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/** A counter is optional and never prefilled: without one the odometer is left as it was. */
	public function testARecordWithoutACounterWritesNoReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work());

		$this->assertNull($written['odo']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
	}

	/** Rule 4: a truck's hours at the workshop are a Reading on the hour chain, cached on its own. */
	public function testEachCounterGivenIsAReadingOnItsOwnChain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'vehicle_type' => 'truck', 'second_unit' => 'h']);

		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['odo' => 300000, 'second_odo' => 5120]));

		$chains = [];
		foreach ($this->odometer->list(self::OWNER, $vehicle->getUuid()) as $reading) {
			$chains[$reading->getCounter()] = $reading->getValue();
		}
		$this->assertSame([OdoReading::MAIN => 300000, OdoReading::SECOND => 5120], $chains);
		$this->assertSame(5120, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getSecondValue());
	}

	/** An hour counter on a vehicle that counts none is a field for a chain that is not there. */
	public function testAnHourCounterOnAVehicleWithoutOneIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['second_odo' => 5120]));
	}

	/**
	 * The title is the one field the sheet requires; everything else may be left out, and a
	 * record without a cost is still saved.
	 */
	public function testOnlyTheTitleIsRequired(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Wipers']);

		$this->assertNull($written['cost']);
		$this->assertNull($written['type']);
		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['title' => ' ']));
	}

	/** Every field the sheet offers is stored as given. */
	public function testTheFieldsAreStoredAsGiven(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work([
			'type' => 'service',
			'vendor' => 'ATU Nord',
			'vat_rate' => 1900,
			'notes' => 'Filter too',
		]));

		$this->assertSame('service', $written['type']);
		$this->assertSame('ATU Nord', $written['vendor']);
		$this->assertSame(1900, $written['vat_rate']);
		$this->assertSame('Filter too', $written['notes']);
	}

	/** A type outside the five is a client that did not use the sheet's list. */
	public function testAnUnknownTypeIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['type' => 'wash']));
	}

	/** The VAT is the jurisdiction's rate on the day the work was done, as for a fill-up. */
	public function testThePrefillStatesTheJurisdictionsRateOnTheDay(): void {
		$german = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de']);
		$generic = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'jurisdiction' => 'generic']);

		$this->assertSame(1600, $this->maintenance->prefill(self::OWNER, $german->getUuid(), ['at' => 1609453800, 'off' => 60])['vat_rate']);
		$this->assertNull($this->maintenance->prefill(self::OWNER, $generic->getUuid(), ['at' => 1750000000, 'off' => 120])['vat_rate']);
	}

	/** A moment the prefill cannot read is a client that did not send one. */
	public function testThePrefillWantsAMoment(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->prefill(self::OWNER, $vehicle->getUuid(), ['at' => 1750000000]);
	}

	/** The vendors this vehicle has used, each once, the latest first; another vehicle's are not. */
	public function testThePrefillOffersThisVehiclesVendorsLatestFirst(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['vendor' => 'ATU Nord']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750086400, 'vendor' => 'Reifen Müller']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750172800, 'vendor' => 'ATU Nord']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750259200]));
		$this->maintenance->record(self::OWNER, $other->getUuid(), $this->work(['vendor' => 'Bosch Service']));

		$this->assertSame(
			['ATU Nord', 'Reifen Müller'],
			$this->maintenance->prefill(self::OWNER, $mine->getUuid(), ['at' => 1750300000, 'off' => 120])['vendors'],
		);
	}

	/**
	 * An edit rewrites the record in place and its Reading follows it; the counter rules are the
	 * fill-up's (EnergyTest), so one case proves the record reaches them.
	 */
	public function testAnEditMovesTheRecordAndItsReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['odo' => 120450]));

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work([
			'title' => 'Oil and filter',
			'done_at' => 1750003600,
			'odo' => 120540,
		]));

		$this->assertSame('Oil and filter', $edited['title']);
		$readings = $this->odometer->list(self::OWNER, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(120540, $readings[0]->getValue());
		$this->assertSame(1750003600, $readings[0]->getReadAt());
		$this->assertSame(120540, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** A delete takes the record's Reading with it, and undo brings both back. */
	public function testDeleteTakesTheReadingAlongAndUndoBringsItBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['odo' => 120450]));

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertNotNull($deleted['deleted_at']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $uuid));
		$this->assertNull($this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertNull($back['deleted_at']);
		$this->assertCount(1, $this->odometer->list(self::OWNER, $uuid));
		$this->assertSame(120450, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** An undo on a token that is not the delete's changes nothing. */
	public function testAnUndoOnAStaleTokenIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work());
		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->expectException(StaleUpdateException::class);
		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
	}

	/**
	 * Rule 4: the next occurrence runs from what actually happened - the record's own day and
	 * counter - not from the due it was planned for.
	 */
	public function testARecordThatClosesAReminderSchedulesTheNextFromItself(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);

		// 2026-05-28 22:26 UTC is already the 29th where the work was done.
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'done_at_off' => 240, 'odo' => 120450, 'closes' => $oil['uuid']]));

		$this->assertSame($oil['uuid'], $written['closes']);
		$next = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2027-05-29', $next['due_date']);
		$this->assertSame(135450, $next['due_odo']);
		$this->assertSame(2, $next['occurrence']);
		$this->assertSame(Reminder::PLANNED, $next['state']);
	}

	/**
	 * Deleting the record takes back the occurrence it closed, and undo closes it again. The
	 * planned due is not kept, so the occurrence reopens at the day and km of the withdrawn work.
	 */
	public function testDeletingTheRecordReopensTheReminderAndUndoClosesItAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'odo' => 120450, 'closes' => $oil['uuid']]));

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$reopened = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2026-05-28', $reopened['due_date']);
		$this->assertSame(120450, $reopened['due_odo']);
		$this->assertSame(1, $reopened['occurrence']);
		$this->assertSame(Reminder::OVERDUE, $reopened['state']);

		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$again = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2027-05-28', $again['due_date']);
		$this->assertSame(135450, $again['due_odo']);
		$this->assertSame(2, $again['occurrence']);
	}

	/**
	 * A record whose occurrence was since moved on by something else - a dismissal here - takes
	 * nothing back: the reminder no longer stands where the record put it.
	 */
	public function testDeletingARecordTheReminderMovedOnFromChangesNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'odo' => 120450, 'closes' => $oil['uuid']]));
		$next = $this->reminder($uuid, $oil['uuid']);
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $next['updated_at']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$after = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2028-05-28', $after['due_date']);
		$this->assertSame(3, $after['occurrence']);
	}

	/** An edit that moves the work moves the next occurrence with it; one that unlinks takes it back. */
	public function testAnEditRedoesTheOccurrenceFromWhereTheWorkNowIs(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'odo' => 120450, 'closes' => $oil['uuid']]));

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => 1780086400, 'odo' => 120600, 'closes' => $oil['uuid']]));

		$next = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2027-05-29', $next['due_date']);
		$this->assertSame(135600, $next['due_odo']);
		$this->assertSame(2, $next['occurrence']);

		$unlinked = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $edited['updated_at'], $this->work(['done_at' => 1780086400, 'odo' => 120600]));

		$this->assertNull($unlinked['closes']);
		$this->assertSame(1, $this->reminder($uuid, $oil['uuid'])['occurrence']);
	}

	/** A reminder that does not recur is done, and a done one is not closed a second time. */
	public function testAOneOffIsDoneAndClosedOnlyOnce(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$once = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Towbar', 'mode' => Reminder::DATE, 'due_date' => '2026-12-31']);

		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['closes' => $once['uuid']]));

		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $once['uuid'])['state']);
		// Saving the record again keeps the link it has.
		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['title' => 'Towbar fitted', 'closes' => $once['uuid']]));
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $once['uuid'])['state']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $uuid, $this->work(['closes' => $once['uuid']]));
	}

	/**
	 * Another record closed the reminder while this one was deleted, so its undo cannot close it
	 * again - and comes back closing nothing, or deleting it once more would reopen the other's.
	 */
	public function testAnUndoThatCannotCloseAgainComesBackClosingNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$once = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Towbar', 'mode' => Reminder::DATE, 'due_date' => '2026-12-31']);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['closes' => $once['uuid']]));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->maintenance->record(self::OWNER, $uuid, $this->work(['title' => 'Towbar fitted', 'closes' => $once['uuid']]));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertNull($back['closes']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $back['updated_at']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $once['uuid'])['state']);
	}

	/**
	 * Without a counter the next due km would be the old one, due at once; a reminder on another
	 * vehicle is not this record's to close.
	 *
	 * @return iterable<string, array{bool, bool}>
	 */
	public static function unclosable(): iterable {
		yield 'a km recurrence without a counter' => [false, false];
		yield 'another vehicle' => [true, true];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unclosable')]
	public function testARecordThatCannotCloseTheReminderIsRefused(bool $withCounter, bool $elsewhere): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		$oil = $this->oilChange(($elsewhere ? $other : $vehicle)->getUuid());

		try {
			$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(($withCounter ? ['odo' => 120450] : []) + ['closes' => $oil['uuid']]));
			$this->fail('the record was saved');
		} catch (\InvalidArgumentException) {
		}

		$this->assertSame(1, $this->reminder(($elsewhere ? $other : $vehicle)->getUuid(), $oil['uuid'])['occurrence']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * An oil change due 2025-09-30 or at 125 000 km, every 12 months or 15 000 km.
	 *
	 * @return array<string, mixed>
	 */
	private function oilChange(string $vehicleUuid): array {
		return $this->reminders->create(self::OWNER, $vehicleUuid, [
			'template_key' => 'oil_change',
			'due_date' => '2025-09-30',
			'due_odo' => 125000,
		]);
	}

	/** @return array<string, mixed> the reminder as the banner lists it */
	private function reminder(string $vehicleUuid, string $reminderUuid): array {
		foreach ($this->reminders->list(self::OWNER, $vehicleUuid) as $reminder) {
			if ($reminder['uuid'] === $reminderUuid) {
				return $reminder;
			}
		}
		$this->fail('reminder ' . $reminderUuid . ' is not listed');
	}

	/**
	 * A Maintenance Record as the sheet sends it, with what a test does not care about filled in.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function work(array $fields = []): array {
		return $fields + [
			'done_at' => 1750000000,
			'done_at_off' => 120,
			'title' => 'Oil change',
			'cost' => 18990,
		];
	}
}
