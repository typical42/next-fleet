<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceipt;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\AfterCommit;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderEngine;
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
	private const DRIVER = 'nextfleet-test-carol';

	private MaintenanceService $maintenance;
	private OdometerService $odometer;
	private VehicleService $vehicles;
	private ReminderService $reminders;

	protected function setUp(): void {
		$this->maintenance = \OCP\Server::get(MaintenanceService::class);
		$this->reminders = \OCP\Server::get(ReminderService::class);
		$this->odometer = \OCP\Server::get(OdometerService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'created_by', 'fleet_odo_readings' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_reminders' => 'created_by', 'fleet_reminder_receipts' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter([self::OWNER, self::DRIVER], $qb::PARAM_STR_ARRAY)));
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

	/** Everything but the title may be left out, the cost included. */
	public function testOnlyTheTitleIsRequired(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Wipers']);

		$this->assertNull($written['cost']);
		$this->assertNull($written['type']);
		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['title' => ' ']));
	}

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

	/** The Reading follows the edit; one case is enough, the counter rules are EnergyTest's. */
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
		$this->assertGreaterThan($deleted['updated_at'], $back['updated_at']);
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

		// 22:26 UTC is already the 29th where the work was done. The next due lies far enough ahead
		// that "a month before" has not come yet when the suite runs.
		$doneAt = (new \DateTimeImmutable('2035-05-28 22:26', new \DateTimeZone('UTC')))->getTimestamp();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => $doneAt, 'done_at_off' => 240, 'odo' => 120450, 'closes' => $oil['uuid']]));

		$this->assertSame($oil['uuid'], $written['closes']);
		$next = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2036-05-29', $next['due_date']);
		$this->assertSame(135450, $next['due_odo']);
		$this->assertSame(2, $next['occurrence']);
		$this->assertSame(Reminder::PLANNED, $next['state']);
	}

	public function testADriversRecordClosesAReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$this->grantDriver((int)$vehicle->getId());

		$written = $this->maintenance->record(self::DRIVER, $uuid, $this->work(['done_at' => 1780000000, 'done_at_off' => 240, 'odo' => 120450, 'closes' => $oil['uuid']]));

		$this->assertSame($oil['uuid'], $written['closes']);
		$this->assertSame(2, $this->reminder($uuid, $oil['uuid'])['occurrence']);
	}

	private function grantDriver(int $vehicleId): void {
		$grant = new Access();
		$grant->setVehicleId($vehicleId);
		$grant->setGrantee(self::DRIVER);
		$grant->setGranteeType(Access::USER);
		$grant->setRole('driver');
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/**
	 * Deleting the record takes back the occurrence it closed, at the due it was planned for, and
	 * undo closes it again. The record still holds that due, so a second delete puts it back too.
	 */
	public function testDeletingTheRecordPutsBackThePlannedDueAndUndoClosesItAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertSame($oil['uuid'], $deleted['closes']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * A record whose occurrence was since moved on by something else - a dismissal here - takes
	 * nothing back: the reminder no longer stands where the record put it. The delete answer still
	 * names the reminder, as for a delete that took the close back: the client cannot tell them apart.
	 */
	public function testDeletingARecordTheReminderMovedOnFromChangesNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'odo' => 120450, 'closes' => $oil['uuid']]));
		$next = $this->reminder($uuid, $oil['uuid']);
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $next['updated_at']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertSame($oil['uuid'], $deleted['closes']);
		$after = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2028-05-28', $after['due_date']);
		$this->assertSame(3, $after['occurrence']);
	}

	/** A reminder deleted since stays as it was deleted: taking the record back revives nothing. */
	public function testDeletingARecordWhoseReminderIsDeletedChangesNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => 1780000000, 'odo' => 120450, 'closes' => $oil['uuid']]));
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$after = \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($oil['uuid']);
		$this->assertSame($gone['deleted_at'], $after->getDeletedAt());
		$this->assertSame($gone['updated_at'], $after->getUpdatedAt());
		$this->assertSame(2, $after->getOccurrence());
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

	/** An edit that unticks the reminder puts back the due the record closed, and the record keeps nothing. */
	public function testAnEditThatClosesNothingPutsBackThePlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500]));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($written['uuid']));
		$this->assertNull($this->handedTo($written['uuid']));
	}

	/** A record that closes no reminder keeps nothing. */
	public function testARecordThatClosesNothingKeepsNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->oilChange($uuid, '2027-03-01', 60000);

		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500]));

		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($written['uuid']));
		$this->assertNull($this->handedTo($written['uuid']));
	}

	/**
	 * An edit that moves the record to another reminder puts back the first one's planned due, and
	 * deleting it then puts back the other one's.
	 */
	public function testAnEditThatClosesAnotherReminderPutsBackEachPlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$other = $this->oilChange($uuid, '2027-04-15', 62000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		$moved = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $other['uuid']]));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $other['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $moved['updated_at']);

		$this->assertDue(['2027-04-15', 62000, 1], $uuid, $other['uuid']);
	}

	/**
	 * An edit that unticks the reminder, or picks another, while the reminder is deleted.
	 *
	 * @return iterable<string, array{bool}> whether the edit picks another reminder
	 */
	public static function editsOffADeletedReminder(): iterable {
		yield 'unticked' => [false];
		yield 'another picked' => [true];
	}

	/**
	 * The reminder was deleted after the close. An edit that no longer closes it takes the close
	 * back on it all the same, its token kept, so its undo brings back the planned due and its
	 * snooze. The edit's own Undo cannot tick a deleted reminder again, and is refused.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('editsOffADeletedReminder')]
	public function testAnEditOffADeletedReminderTakesTheCloseBackOnIt(bool $another): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$other = $this->oilChange($uuid, '2027-04-15', 62000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], '2031-06-01');
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		WatchedWithdrawals::watch();
		try {
			$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500] + ($another ? ['closes' => $other['uuid']] : [])));
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		// The other reminder's close withdraws its own notice; the deleted one has none.
		$this->assertSame($another ? [false] : [], $withdrawals);
		$this->assertSame($another ? ['2027-04-15', 62000, null, 1, null, null, null, '2028-02-10', 73500, null] : [null, null, null, null, null, null, null, null, null, null], $this->keptBy($written['uuid']));
		$after = \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($oil['uuid']);
		$this->assertSame($gone['updated_at'], $after->getUpdatedAt());
		$this->assertSame($gone['deleted_at'], $after->getDeletedAt());
		try {
			$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $edited['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
			$this->fail('the deleted reminder was ticked again');
		} catch (\InvalidArgumentException) {
		}

		$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2031-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame($edited['updated_at'], $this->tokenOf($written['uuid']));
	}

	/**
	 * An edit of the day moves the close to the new day. The occurrence it started keeps its
	 * snooze, the record keeps what it kept, and a delete still puts back the planned due.
	 */
	public function testAnEditOfTheDayMovesTheClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], '2031-06-01');
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2032-04-01');

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertDue(['2028-02-12', 73700, 2], $uuid, $oil['uuid']);
		$this->assertSame('2032-04-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, '2031-06-01', 1, null, null, null, '2028-02-12', 73700, null], $this->keptBy($written['uuid']));
		$this->assertSame($edited['updated_at'], $this->tokenOf($written['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2032-04-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The reminder is deleted when the record's day is corrected. The close moves on the deleted
	 * reminder too, and its token stays, so its undo still holds; deleting the record then puts back
	 * the planned due.
	 */
	public function testAnEditOfTheDayMovesTheCloseOnADeletedReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));
		$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);

		$this->assertDue(['2028-02-12', 73700, 2], $uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * Another time on the same day, or another counter for a reminder by date only, moves no base
	 * the reminder counts from: the reminder is not written.
	 */
	public function testAnEditThatMovesNoBaseLeavesTheReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $check['uuid']]));
		$token = $this->reminder($uuid, $check['uuid'])['updated_at'];

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10') + 3600, 'odo' => 58500, 'closes' => $check['uuid']]));
		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $edited['updated_at'], $this->work(['done_at' => self::on('2027-02-10') + 3600, 'odo' => 58900, 'closes' => $check['uuid']]));

		$this->assertSame($token, $this->reminder($uuid, $check['uuid'])['updated_at']);
		$this->assertDue(['2028-02-10', null, 2], $uuid, $check['uuid']);
	}

	/**
	 * The counter moved, but the close set no km: the km was added after it. The due does not
	 * change, so the reminder is not written, and a sheet open on it keeps a valid token.
	 */
	public function testAnEditThatMovesNoDueLeavesTheReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $written] = $this->checkUpGivenAKmAfterAClose($uuid);
		$token = $this->reminder($uuid, $check['uuid'])['updated_at'];

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 59000, 'closes' => $check['uuid']]));

		$this->assertSame($token, $this->reminder($uuid, $check['uuid'])['updated_at']);
		$this->assertDue(['2028-02-10', 74000, 2], $uuid, $check['uuid']);
	}

	/** The same for a day moved where the close set no date: the date was added after it. */
	public function testAnEditThatMovesNoDateLeavesTheReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $written] = $this->oilByKmGivenADateAfterAClose($uuid);
		$token = $this->reminder($uuid, $oil['uuid'])['updated_at'];

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		$this->assertSame($token, $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertDue(['2028-03-15', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame([null, 73500], $this->nextDueBy($written['uuid']));
	}

	/**
	 * After the interval changed, only the counter is corrected: the km is counted from it again
	 * with the new interval, and the date stays where the close put it. Delete, then Undo: the
	 * reminder is due again at that date and the new km.
	 */
	public function testACounterCorrectedAloneKeepsTheDateTheCloseSet(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->changeInterval($uuid, $oil['uuid']);

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58600, 'closes' => $oil['uuid']]));

		$this->assertSame(['2028-02-10', 78600], $this->nextDueBy($written['uuid']));
		$this->assertDue(['2028-02-10', 78600, 2], $uuid, $oil['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue(['2028-02-10', 78600, 2], $uuid, $oil['uuid']);
	}

	/** An edit of the title alone leaves what the record kept, so a delete still puts back the planned due. */
	public function testAnEditOfTheTitleKeepsThePlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $fields);

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], ['title' => 'Oil and filter'] + $fields);
		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/** A reminder by km counts from the record's counter, so an edit may not take it away. */
	public function testAnEditThatDropsTheCounterOfAKmReminderIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		try {
			$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $oil['uuid']]));
			$this->fail('the edit was saved');
		} catch (\InvalidArgumentException) {
		}

		$this->assertSame($written['updated_at'], $this->tokenOf($written['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
	}

	/** The same for a record from before, which stored no due to tell whether its close set a km. */
	public function testAnEditThatDropsTheCounterOfARecordFromBeforeIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']])));

		try {
			$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $oil['uuid']]));
			$this->fail('the edit was saved');
		} catch (\InvalidArgumentException) {
		}

		$this->assertSame($written['updated_at'], $this->tokenOf($written['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
	}

	/** A close no longer in effect moves nothing, so it needs no counter. */
	public function testAnEditThatDropsTheCounterOfACloseNoLongerInEffectIsSaved(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$dismissed = $this->reminder($uuid, $oil['uuid']);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $oil['uuid']]));

		$this->assertNull(self::record($written['uuid'])->getOdo());
		$this->assertSame($dismissed, $this->reminder($uuid, $oil['uuid']));
	}

	/**
	 * The snooze the record closed and the one set on the next occurrence. Delete, then Undo: the
	 * record keeps the first, the reminder gets the second back.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function closedSnoozes(): iterable {
		yield 'the closed one earlier' => ['2031-03-01'];
		yield 'the closed one later' => ['2034-03-01'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('closedSnoozes')]
	public function testAnUndoGivesTheNextOccurrenceItsSnoozeBack(string $closed): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], $closed);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2032-06-01');
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, $closed, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));
		$this->assertSame($back['updated_at'], $this->tokenOf($written['uuid']));
	}

	/**
	 * A snooze set while the record was deleted is what its undo closes, so the record keeps it,
	 * the next occurrence gets its own back, and a second delete puts the first back.
	 */
	public function testAnUndoKeepsASnoozeSetWhileTheRecordWasDeleted(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2032-06-01');
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2033-06-01');

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, '2033-06-01', 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2033-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The snooze the record closed and the one set on the next occurrence since: the later day
	 * keeps both.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function twoSnoozes(): iterable {
		yield 'the next one later' => ['2035-01-01', '2036-06-01'];
		yield 'the closed one later' => ['2036-06-01', '2035-01-01'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('twoSnoozes')]
	public function testDeletingTheRecordKeepsTheLaterSnooze(string $closed, string $next): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], $closed);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], $next);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$reopened = $this->reminder($uuid, $oil['uuid']);
		$this->assertSame('2036-06-01', $reopened['snoozed_until']);
		$this->assertSame(Reminder::SNOOZED, $reopened['state']);
	}

	/** A record from before it kept the due it closed: the due comes back at its own day and km. */
	public function testARecordThatKeptNothingPutsBackItsOwnDayAndKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']])));

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertDue(['2027-02-10', 58500, 1], $uuid, $oil['uuid']);

		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
	}

	/**
	 * A record from before and a new one close the reminder from the same day and km. The new close
	 * is on top, so deleting the record from before takes nothing back, and its Undo keeps the link.
	 */
	public function testARecordThatKeptNothingUnderANewDuplicateTakesNothingBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]);
		$old = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $fields));
		$new = $this->maintenance->record(self::OWNER, $uuid, $fields);
		$newKept = $this->keptBy($new['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $old['uuid'], $old['updated_at']);

		$this->assertDue(['2028-02-10', 73500, 3], $uuid, $oil['uuid']);
		$this->assertFalse($this->tookBack($old['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $old['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($old['uuid']));
		$this->assertNull($this->tookBack($old['uuid']));
		$this->assertDue(['2028-02-10', 73500, 3], $uuid, $oil['uuid']);
		$this->assertSame($newKept, $this->keptBy($new['uuid']));
	}

	/**
	 * The new close is on top of the one from before, though both give the same due. Correcting the
	 * day of the record from before moves nothing, so deleting the new record still puts back what
	 * it kept.
	 */
	public function testAnEditOfARecordThatKeptNothingUnderANewDuplicateLeavesTheReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]);
		$old = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $fields));
		$new = $this->maintenance->record(self::OWNER, $uuid, $fields);
		$newKept = $this->keptBy($new['uuid']);
		$reminderToken = $this->reminder($uuid, $oil['uuid'])['updated_at'];

		$this->maintenance->update(self::OWNER, $uuid, $old['uuid'], $old['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertDue(['2028-02-10', 73500, 3], $uuid, $oil['uuid']);
		$this->assertSame($reminderToken, $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertSame($newKept, $this->keptBy($new['uuid']));
		$this->assertSame(array_fill(0, 10, null), $this->keptBy($old['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $new['uuid'], $this->tokenOf($new['uuid']));

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
	}

	/**
	 * Another record closed the reminder again while this one was deleted, from the same day and
	 * km, so its close is on top now. The undo cannot close it a second time and comes back closing
	 * nothing, so deleting the other record still puts back the planned due.
	 */
	public function testAnUndoUnderAnotherCloseOfTheSameOccurrenceComesBackClosingNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $fields);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$again = $this->maintenance->record(self::OWNER, $uuid, $fields);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertNull($back['closes']);
		$this->assertSame(array_fill(0, 10, null), $this->keptBy($first['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $again['uuid'], $this->tokenOf($again['uuid']));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * A one-off has no due to put back: it is done, then open again, its due and snooze as they
	 * stand. The record keeps them all the same; a due edited after the close is not overwritten.
	 */
	public function testDeletingTheRecordReopensAOneOffWithItsSnooze(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$once = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Towbar', 'mode' => Reminder::DATE, 'due_date' => '2036-12-31']);
		$this->reminders->snooze(self::OWNER, $uuid, $once['uuid'], $once['updated_at'], '2036-06-01');
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['closes' => $once['uuid']]));
		$done = $this->reminder($uuid, $once['uuid']);
		$this->assertSame(Reminder::DONE, $done['state']);
		$this->assertSame(['2036-12-31', null, '2036-06-01', 1, null, null, null, null, null, null], $this->keptBy($written['uuid']));
		$this->reminders->update(self::OWNER, $uuid, $once['uuid'], $done['updated_at'], ['title' => 'Towbar', 'mode' => Reminder::DATE, 'due_date' => '2036-10-31']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$reopened = $this->reminder($uuid, $once['uuid']);
		$this->assertSame(Reminder::SNOOZED, $reopened['state']);
		$this->assertSame(['2036-10-31', '2036-06-01', 1], [$reopened['due_date'], $reopened['snoozed_until'], $reopened['occurrence']]);
	}

	/**
	 * A write that closes a reminder writes the record twice. The answer carries the second
	 * token, or the client's next write would be refused as stale.
	 */
	public function testEachAnswerIsTheTokenForTheNextWrite(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$other = $this->oilChange($uuid, '2027-04-15', 62000);
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500]);

		$answer = $this->maintenance->record(self::OWNER, $uuid, $fields + ['closes' => $oil['uuid']]);
		$answer = $this->maintenance->update(self::OWNER, $uuid, $answer['uuid'], $answer['updated_at'], ['odo' => 58600, 'closes' => $oil['uuid']] + $fields);
		$answer = $this->maintenance->update(self::OWNER, $uuid, $answer['uuid'], $answer['updated_at'], ['closes' => $other['uuid']] + $fields);
		$answer = $this->maintenance->delete(self::OWNER, $uuid, $answer['uuid'], $answer['updated_at']);
		$answer = $this->maintenance->restore(self::OWNER, $uuid, $answer['uuid'], $answer['updated_at']);
		$answer = $this->maintenance->update(self::OWNER, $uuid, $answer['uuid'], $answer['updated_at'], $fields);

		$this->assertNull($answer['closes']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertDue(['2027-04-15', 62000, 1], $uuid, $other['uuid']);
	}

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
		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($first['uuid']));
		$this->assertNull($this->handedTo($first['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $back['updated_at']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $once['uuid'])['state']);
	}

	/**
	 * Two records of the same work close the reminder twice. Deleting the first leaves the reminder
	 * closed by the second, which takes over what the first kept; deleting that one too puts back
	 * the planned due. Neither write moves the other record's token.
	 */
	public function testADuplicateRecordTakesOverWhatTheFirstKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], '2032-06-01');
		$fields = $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $fields);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2031-05-01');
		$second = $this->maintenance->record(self::OWNER, $uuid, $fields);
		$this->assertSame(['2028-02-10', 73500, '2031-05-01', 2, null, null, null, '2028-02-10', 73500, null], $this->keptBy($second['uuid']));
		$token = $this->tokenOf($second['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertDue(['2028-02-10', 73500, 3], $uuid, $oil['uuid']);
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, null, null, 1, '2028-02-10', 73500, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 1, '2031-05-01', $second['uuid'], null, '2028-02-10', 73500, true], $this->keptBy($first['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));

		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2033-03-01');
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $token);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2033-03-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, '2033-03-01', null, 1, '2028-02-10', 73500, true], $this->keptBy($second['uuid']));
	}

	/**
	 * A record closes the occurrence an earlier one started. Deleting the earlier one moves nothing
	 * on the reminder, and the later one takes over its planned due.
	 */
	public function testDeletingTheLowerOfTwoClosesHandsItsDueUp(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$token = $this->tokenOf($second['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($oil['uuid'], $deleted['closes']);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $token);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The middle record of three hands the planned due of the first close up to the last, with the
	 * occurrence of that due. Deleting the last record then returns the reminder to that occurrence,
	 * so the first close is on top again, and deleting it puts back the planned due.
	 */
	public function testThreeDeletesInTurnPutBackThePlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->assertSame(['2028-02-10', 73500, '2033-03-01', 3, null, null, 2, '2030-01-15', 102000, null], $this->keptBy($third['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $this->tokenOf($third['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * Deleted from the bottom, each record hands its due to the one right above it, not to the
	 * last: deleting the last record then puts back the planned due.
	 */
	public function testThreeDeletesFromTheBottomPutBackThePlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->assertSame($second['uuid'], $this->handedTo($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertSame($third['uuid'], $this->handedTo($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2033-03-01', 3, null, null, 1, '2030-01-15', 102000, null], $this->keptBy($third['uuid']));
		$this->assertDue(['2030-01-15', 102000, 4], $uuid, $oil['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $this->tokenOf($third['uuid']));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The three deletes undone in the order Undo offers them, the latest first: every record keeps
	 * what it kept before the first delete, and the reminder stands where it stood.
	 */
	public function testThreeDeletesUndoneInTurnPutTheStackBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$records = $this->threeCloses($uuid);
		$oil = array_shift($records);
		$before = array_map(fn (array $record): array => $this->keptBy($record['uuid']), $records);
		$reminder = $this->reminder($uuid, $oil['uuid']);
		$deleted = [];
		foreach ([1, 2, 0] as $i) {
			$deleted[$i] = $this->maintenance->delete(self::OWNER, $uuid, $records[$i]['uuid'], $this->tokenOf($records[$i]['uuid']));
		}

		foreach ([0, 2, 1] as $i) {
			$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $records[$i]['uuid'], $deleted[$i]['updated_at'])['closes']);
		}

		$this->assertSame($before, array_map(fn (array $record): array => $this->keptBy($record['uuid']), $records));
		$after = $this->reminder($uuid, $oil['uuid']);
		foreach (['due_date', 'due_odo', 'occurrence', 'snoozed_until', 'state'] as $key) {
			$this->assertSame($reminder[$key], $after[$key], $key);
		}
	}

	/**
	 * The record above took over the planned due with its occurrence, then its own day is
	 * corrected: the close moves, and the record still keeps that due and occurrence.
	 */
	public function testAnEditOfTheDayKeepsTheOccurrenceOfADueTakenOver(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$edited = $this->maintenance->update(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']), $this->work(['done_at' => self::on('2028-02-03'), 'odo' => 73000, 'closes' => $oil['uuid']]));

		$this->assertDue(['2029-02-03', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-03', 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $edited['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The last of three records took over the due of the first close, with its occurrence. The first
	 * record's day is corrected: the last one keeps the due the new day gives, and still its
	 * occurrence, so deleting both puts back the planned due.
	 */
	public function testAnEditOfTheLowerDayKeepsTheOccurrenceOfTheDueAbove(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);

		$edited = $this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertSame(['2028-02-12', 73700, '2033-03-01', 3, null, null, 2, '2030-01-15', 102000, null], $this->keptBy($third['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $this->tokenOf($third['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $edited['updated_at']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * Handing up leaves the reminder alone, so it runs on a deleted one too: undoing the
	 * reminder's delete then finds the planned due on the record that still closes it.
	 */
	public function testTheLowerCloseHandsItsDueUpOnADeletedReminder(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 1, null, $second['uuid'], null, '2028-02-10', 73500, true], $this->keptBy($first['uuid']));
		$this->assertSame($gone['updated_at'], \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($oil['uuid'])->getUpdatedAt());

		$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The lower record's day and counter are corrected: the record above keeps the due the new base
	 * gives, and the reminder stays where the record above put it.
	 */
	public function testAnEditOfTheLowerCloseMovesWhatTheRecordAboveKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$token = $this->tokenOf($second['uuid']);
		$reminderToken = $this->reminder($uuid, $oil['uuid'])['updated_at'];

		$this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertSame(['2028-02-12', 73700, null, 2, null, null, null, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-12', 73700, null], $this->keptBy($first['uuid']));
		$this->assertSame(['2028-02-12', 73700], $this->nextDueBy($first['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));
		$this->assertSame($reminderToken, $this->reminder($uuid, $oil['uuid'])['updated_at']);
	}

	/**
	 * "A month before" of the second occurrence went out for 2028-02-10, before the record above
	 * closed it. The lower record's day is corrected, so that occurrence is due 2028-02-12 once the
	 * record above is deleted: the date's points ring again there, the km's stay sent, and the
	 * occurrence the reminder stands at is left alone.
	 */
	public function testAnEditOfTheLowerDayLetsTheDatePointsAboveRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::MAIL);
		$this->sent($oil['uuid'], 2, ReminderEngine::ODO, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 3, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);

		$this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2028-02-12', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame(['app ' . ReminderEngine::ODO, 'mail ~' . ReminderEngine::MONTH_BEFORE], $this->receipts($oil['uuid'], 2));
		$this->assertSame(['app ' . ReminderEngine::MONTH_BEFORE], $this->receipts($oil['uuid'], 3));
	}

	/**
	 * "A month before" and the km warning went out for 2028-02-10. The record's day is corrected,
	 * so the reminder is due 2028-02-12. The notifier drops the standing notice for any due that
	 * moved, so the km warning rings again in the app as well. The mail's km point stays sent.
	 */
	public function testAnEditOfTheDayLetsTheStandingNoticeRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 2, ReminderEngine::ODO, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 2, ReminderEngine::ODO, ReminderReceipt::MAIL);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		$this->assertDue(['2028-02-12', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame(['mail ' . ReminderEngine::ODO], $this->receipts($oil['uuid'], 2));
	}

	/** The same for a corrected counter: the standing date notice rings again. */
	public function testAnEditOfTheCounterLetsTheStandingNoticeRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertDue(['2028-02-10', 73700, 2], $uuid, $oil['uuid']);
		$this->assertSame([], $this->receipts($oil['uuid'], 2));
	}

	/**
	 * The record's day is corrected while the reminder is deleted. Its delete took back the notice,
	 * not what was sent: after its undo, the date's points ring again for the new due.
	 */
	public function testAnEditOfTheDayOnADeletedReminderLetsTheDatePointsRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 2, ReminderEngine::ODO, ReminderReceipt::APP);
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);

		$this->assertDue(['2028-02-12', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame(['app ' . ReminderEngine::ODO], $this->receipts($oil['uuid'], 2));
	}

	/**
	 * A record from before kept nothing, so an edit moves its close and keeps nothing either:
	 * deleting it then puts back its new day and km.
	 */
	public function testARecordThatKeptNothingEditedThenDeletedPutsBackItsNewDayAndKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']])));

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertDue(['2028-02-12', 73700, 2], $uuid, $oil['uuid']);
		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($written['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->assertDue(['2027-02-12', 58700, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The record above on the same day as the lower one, a duplicate, or a year on: the day, the
	 * counter, and the due it then closes.
	 *
	 * @return iterable<string, array{string, int, array{string, int, int}}>
	 */
	public static function recordsAbove(): iterable {
		yield 'a duplicate' => ['2027-02-10', 58500, ['2028-02-10', 73500, 3]];
		yield 'a year on' => ['2028-02-01', 73000, ['2029-02-01', 88000, 3]];
	}

	/**
	 * Deleting the lower record handed what it kept to the record above. Its undo gives that record
	 * back what it kept before, so deleting both in turn puts back each due again.
	 *
	 * @param array{string, int, int} $due
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('recordsAbove')]
	public function testAnUndoAfterHandingUpPutsTheStackBack(string $day, int $odo, array $due): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], '2032-06-01');
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2031-05-01');
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on($day), 'odo' => $odo, 'closes' => $oil['uuid']]));
		$token = $this->tokenOf($second['uuid']);
		$reminderToken = $this->reminder($uuid, $oil['uuid'])['updated_at'];
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->assertSame($second['uuid'], $this->handedTo($first['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($first['uuid']));
		$this->assertSame(['2028-02-10', 73500, '2031-05-01', 2, null, null, null, $due[0], $due[1], null], $this->keptBy($second['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));
		$this->assertSame($reminderToken, $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertDue($due, $uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $token);
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $back['updated_at']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The person set the due back to the planned one and snoozed it before the second close, so the
	 * record above kept what the lower one kept, but nothing handed it up. Deleting the lower
	 * record hands nothing up, and its undo gives nothing back: the record above keeps what was
	 * entered, and the lower record its link and what it kept.
	 */
	public function testAnUndoOfNothingHandedUpLeavesTheRecordAbove(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$set = $this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2027-03-01', 'due_odo' => 60000, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $set['updated_at'], '2033-01-01');
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-20'), 'odo' => 59000, 'closes' => $oil['uuid']]));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertFalse($this->tookBack($first['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2033-01-01', 2, null, null, null, '2028-02-20', 74000, null], $this->keptBy($second['uuid']));
		$this->assertDue(['2028-02-20', 74000, 3], $uuid, $oil['uuid']);
	}

	/**
	 * Two people each delete one record and each undo their own delete, the earlier one first: the
	 * driver's oil change of occurrence 2, then the owner's of occurrence 1. The Undo of the upper
	 * record replays the owner's later delete, so the lower record stays deleted as it would be had
	 * the upper one never been: it hands its values up. The reminder moved, so its token moves. The
	 * second Undo then puts the stack back exactly.
	 */
	public function testTwoPeopleUndoingTheirOwnDeletesPutTheStackBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->grantDriver((int)$vehicle->getId());
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$snoozeThenClose = function (string $user, string $snooze, string $day, int $odo) use ($uuid, $oil): array {
			$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], $snooze);

			return $this->maintenance->record($user, $uuid, $this->work(['done_at' => self::on($day), 'odo' => $odo, 'closes' => $oil['uuid']]));
		};
		$lower = $snoozeThenClose(self::OWNER, '2031-03-01', '2027-02-10', 58500);
		$upper = $snoozeThenClose(self::DRIVER, '2032-06-01', '2028-02-01', 73000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2033-03-01');
		$stack = [$this->keptBy($lower['uuid']), $this->keptBy($upper['uuid'])];
		$this->assertSame(['2027-03-01', 60000, '2031-03-01', 1, null, null, null, '2028-02-10', 73500, null], $stack[0]);
		$this->assertSame(['2028-02-10', 73500, '2032-06-01', 2, null, null, null, '2029-02-01', 88000, null], $stack[1]);

		$deletedUpper = $this->maintenance->delete(self::DRIVER, $uuid, $upper['uuid'], $upper['updated_at']);
		$this->deletedEarlier($upper['uuid']);
		$deletedLower = $this->maintenance->delete(self::OWNER, $uuid, $lower['uuid'], $lower['updated_at']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$deletedAt = self::record($lower['uuid'])->getDeletedAt();
		$reminderToken = $this->reminder($uuid, $oil['uuid'])['updated_at'];

		$back = $this->maintenance->restore(self::DRIVER, $uuid, $upper['uuid'], $deletedUpper['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertNotSame($reminderToken, $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertSame('2033-03-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($upper['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2031-03-01', 1, '2032-06-01', $upper['uuid'], null, '2028-02-10', 73500, true], $this->keptBy($lower['uuid']));
		$this->assertSame($deletedLower['updated_at'], $this->tokenOf($lower['uuid']));
		$this->assertSame($deletedAt, self::record($lower['uuid'])->getDeletedAt());

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $lower['uuid'], $deletedLower['updated_at'])['closes']);

		$this->assertSame($stack, [$this->keptBy($lower['uuid']), $this->keptBy($upper['uuid'])]);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame('2033-03-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The middle record, then the first, hand what they kept to the last, and the two deletes are
	 * undone in the order they were made. The middle record's Undo replays the first's delete, so
	 * the first hands its values up to the middle one, as had only it been deleted. Its own Undo,
	 * on the token its delete gave, then puts the stack back.
	 */
	public function testTwoHandsUpUndoneInDeleteOrderPutTheStackBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);
		$stack = array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]);
		$deletedSecond = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->deletedEarlier($second['uuid']);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deletedSecond['updated_at'])['closes']);

		$this->assertSame(['2027-03-01', 60000, '2031-03-01', 1, '2032-06-01', $second['uuid'], null, '2028-02-10', 73500, true], $this->keptBy($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame($stack[2], $this->keptBy($third['uuid']));
		$this->assertSame($deletedFirst['updated_at'], $this->tokenOf($first['uuid']));
		$this->assertDue(['2030-01-15', 102000, 4], $uuid, $oil['uuid']);

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at'])['closes']);

		$this->assertSame($stack, array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]));
		$this->assertDue(['2030-01-15', 102000, 4], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The lower record hands what it kept to the record above, which is deleted too, so the
	 * reminder gets the lower record's values back. Undone lower record first: the Undo replays the
	 * later delete, so the record above stays deleted with what it kept, as had only it been
	 * deleted. Its own Undo then closes the reminder again.
	 */
	public function testAnUndoAfterTheRecordAboveWasDeletedKeepsItsDelete(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$stack = [$this->keptBy($first['uuid']), $this->keptBy($second['uuid'])];
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($first['uuid']);
		$deletedSecond = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame($stack[0], $this->keptBy($first['uuid']));
		$this->assertSame(['2028-02-10', 73500, null, 2, null, null, null, '2029-02-01', 88000, true], $this->keptBy($second['uuid']));
		$this->assertSame($deletedSecond['updated_at'], $this->tokenOf($second['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deletedSecond['updated_at'])['closes']);

		$this->assertSame($stack, [$this->keptBy($first['uuid']), $this->keptBy($second['uuid'])]);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
	}

	/**
	 * The replay closes the reminder again and takes the close back once more, but the reminder
	 * moves on once: its notice is withdrawn once, after the commit.
	 */
	public function testAReplayedUndoWithdrawsTheNoticeOnce(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($first['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		WatchedWithdrawals::watch();
		try {
			$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at']);
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame([false], $withdrawals);
	}

	/**
	 * The later delete reopens the reminder, the replay closes it again and that delete reopens it
	 * once more: the reminder ends where it stood. It is not written, so a sheet open on it still
	 * saves, and nothing is withdrawn.
	 */
	public function testAReplayThatLeavesTheReminderAsItWasKeepsItsToken(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, , $third] = $this->threeCloses($uuid);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($first['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $third['updated_at']);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$reminderToken = $this->reminder($uuid, $oil['uuid'])['updated_at'];
		$kept = $this->keptBy($third['uuid']);

		WatchedWithdrawals::watch();
		try {
			$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at']);
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame($kept, $this->keptBy($third['uuid']));
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame($reminderToken, $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertSame([], $withdrawals);
	}

	/**
	 * The replay leaves the reminder at the occurrence it stood at, due elsewhere: the points that
	 * moved ring again, and one withdrawal runs after the commit.
	 * No sequence of deletes gets there: the replay is exact, and each occurrence has one due. So the
	 * middle record's occurrence is set one step back by hand. Its undo then gives the record above a
	 * new due, but leaves the occurrence that record returns to as it was.
	 */
	public function testAReplayThatMovesTheDueWithinTheOccurrenceLetsItsPointsRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, , $second, $third] = $this->closesWithoutSnooze($uuid, 3);
		$deletedSecond = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->closedOccurrenceSetTo($second['uuid'], 1);
		$this->deletedEarlier($second['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $third['updated_at']);
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 2, ReminderEngine::MONTH_BEFORE, ReminderReceipt::MAIL);

		WatchedWithdrawals::watch();
		try {
			$this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deletedSecond['updated_at']);
		} finally {
			$withdrawals = WatchedWithdrawals::stop();
		}

		$this->assertDue(['2029-02-01', 88000, 2], $uuid, $oil['uuid']);
		$this->assertSame(['mail ~' . ReminderEngine::MONTH_BEFORE], $this->receipts($oil['uuid'], 2));
		$this->assertSame([false], $withdrawals);
	}

	/**
	 * The replay moves what a live record above kept within the occurrence it returns to: once the
	 * reminder returns there, the points that moved ring again.
	 * No sequence of deletes gets there (testAReplayThatMovesTheDueWithinTheOccurrenceLetsItsPointsRingAgain()),
	 * so the third record's occurrence is set one step back by hand. The first record's replayed delete hands its values to the second
	 * one, which is not the record whose kept due moved.
	 */
	public function testAReplayThatMovesWhatTheRecordAboveKeptLetsItsPointsRingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, , $third, $fourth] = $this->closesWithoutSnooze($uuid, 4);
		$deletedThird = $this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $third['updated_at']);
		$this->closedOccurrenceSetTo($third['uuid'], 2);
		$this->deletedEarlier($third['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->assertSame(['2029-02-01', 88000, null, 4, null, null, 3, '2031-01-10', 116000, null], $this->keptBy($fourth['uuid']));
		$this->sent($oil['uuid'], 3, ReminderEngine::MONTH_BEFORE, ReminderReceipt::APP);
		$this->sent($oil['uuid'], 3, ReminderEngine::MONTH_BEFORE, ReminderReceipt::MAIL);

		$this->maintenance->restore(self::OWNER, $uuid, $third['uuid'], $deletedThird['updated_at']);

		$this->assertSame(['2030-01-15', 102000, null, 4, null, null, 3, '2031-01-10', 116000, null], $this->keptBy($fourth['uuid']));
		$this->assertDue(['2031-01-10', 116000, 5], $uuid, $oil['uuid']);
		$this->assertSame(['mail ~' . ReminderEngine::MONTH_BEFORE], $this->receipts($oil['uuid'], 3));
	}

	/**
	 * The reminder was deleted after the later delete reopened it. A replay would close the deleted
	 * reminder again, and the later record's second delete could not take that back. So the record
	 * is restored alone: the later record stays deleted as it is.
	 */
	public function testAnUndoUnderADeletedReminderReplaysNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($first['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, true], $kept);
		$token = $this->tokenOf($second['uuid']);

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at'])['closes']);

		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));
		$reminder = \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($oil['uuid']);
		$this->assertSame(['2028-02-10', 73500, 2], [$reminder->getDueDate()?->format('Y-m-d'), $reminder->getDueOdo(), $reminder->getOccurrence()]);
	}

	/**
	 * A delete from before `took_back` was stored is not replayed: what its take-back did is not
	 * known. The record above stays deleted as it is, its token too.
	 */
	public function testAnUndoLeavesALaterDeleteFromBefore(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($first['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_maintenance')
			->set('took_back', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($second['uuid'])));
		$this->assertSame(1, $qb->executeStatement());
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, null], $kept);
		$token = $this->tokenOf($second['uuid']);

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at'])['closes']);

		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
	}

	/**
	 * The reminder moved on after both deletes, so the later delete's Undo would no longer close it.
	 * The replay stops, and the record is restored alone, as when nothing came after its delete:
	 * the later record stays deleted as it is.
	 */
	public function testAnUndoThatCannotReplayTheLaterDeleteRestoresTheRecordAlone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$deletedSecond = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->deletedEarlier($second['uuid']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->assertDue(['2028-03-01', 75000, 2], $uuid, $oil['uuid']);
		$kept = $this->keptBy($first['uuid']);
		$token = $this->tokenOf($first['uuid']);
		$deletedAt = self::record($first['uuid'])->getDeletedAt();

		$this->assertNull($this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deletedSecond['updated_at'])['closes']);

		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, true], $kept);
		$this->assertSame($kept, $this->keptBy($first['uuid']));
		$this->assertSame($token, $this->tokenOf($first['uuid']));
		$this->assertSame($deletedAt, self::record($first['uuid'])->getDeletedAt());
		$this->assertDue(['2028-03-01', 75000, 2], $uuid, $oil['uuid']);
	}

	/**
	 * The middle record, the first and the last are deleted in that order, a second apart. The middle
	 * record's Undo replays both later deletes, so it leaves what they give had only they been
	 * made: the first hands its values up to the middle one, and the last takes its close back to
	 * there. Undoing the last and then the first puts the stack back.
	 */
	public function testAnUndoReplaysTwoLaterDeletesInTheOrderTheyWereMade(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);
		$stack = array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]);
		$reminder = $this->reminder($uuid, $oil['uuid']);
		$deletedSecond = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$deletedFirst = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$deletedThird = $this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $third['updated_at']);
		$this->deletedEarlier($second['uuid'], 2);
		$this->deletedEarlier($first['uuid']);
		$deletedAt = [self::record($first['uuid'])->getDeletedAt(), self::record($third['uuid'])->getDeletedAt()];

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deletedSecond['updated_at'])['closes']);

		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', 60000, '2031-03-01', 1, '2032-06-01', $second['uuid'], null, '2028-02-10', 73500, true], $this->keptBy($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, '2032-06-01', 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2029-02-01', 88000, '2033-03-01', 3, '2034-01-01', null, null, '2030-01-15', 102000, true], $this->keptBy($third['uuid']));
		$this->assertSame([$deletedFirst['updated_at'], $deletedThird['updated_at']], [$this->tokenOf($first['uuid']), $this->tokenOf($third['uuid'])]);
		$this->assertSame($deletedAt, [self::record($first['uuid'])->getDeletedAt(), self::record($third['uuid'])->getDeletedAt()]);

		$this->maintenance->restore(self::OWNER, $uuid, $third['uuid'], $deletedThird['updated_at']);
		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deletedFirst['updated_at']);

		$this->assertSame($stack, array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]));
		$after = $this->reminder($uuid, $oil['uuid']);
		foreach (['due_date', 'due_odo', 'occurrence', 'snoozed_until', 'state'] as $key) {
			$this->assertSame($reminder[$key], $after[$key], $key);
		}
	}

	/**
	 * The three records are deleted from the bottom, a second apart. The first record's Undo
	 * replays the two later deletes and makes them again in their order: the middle record hands
	 * its values up to the last, which takes them back to the first record's close. Undoing the
	 * last and then the middle one puts the stack back.
	 */
	public function testAnUndoDeletesTheLaterRecordsAgainInTheOrderTheyWereMade(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);
		$stack = array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]);
		$deleted = array_map(fn (array $record): array => $this->maintenance->delete(self::OWNER, $uuid, $record['uuid'], $this->tokenOf($record['uuid'])), [$first, $second, $third]);
		$this->deletedEarlier($first['uuid'], 2);
		$this->deletedEarlier($second['uuid']);
		$deletedAt = [self::record($second['uuid'])->getDeletedAt(), self::record($third['uuid'])->getDeletedAt()];

		$this->assertSame($oil['uuid'], $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted[0]['updated_at'])['closes']);

		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame($stack[0], $this->keptBy($first['uuid']));
		$this->assertSame(['2028-02-10', 73500, '2032-06-01', 2, '2033-03-01', $third['uuid'], null, '2029-02-01', 88000, true], $this->keptBy($second['uuid']));
		$this->assertSame(['2028-02-10', 73500, '2033-03-01', 3, '2034-01-01', null, 2, '2030-01-15', 102000, true], $this->keptBy($third['uuid']));
		$this->assertSame([$deleted[1]['updated_at'], $deleted[2]['updated_at']], [$this->tokenOf($second['uuid']), $this->tokenOf($third['uuid'])]);
		$this->assertSame($deletedAt, [self::record($second['uuid'])->getDeletedAt(), self::record($third['uuid'])->getDeletedAt()]);

		$this->maintenance->restore(self::OWNER, $uuid, $third['uuid'], $deleted[2]['updated_at']);
		$this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deleted[1]['updated_at']);

		$this->assertSame($stack, array_map($this->keptBy(...), [$first['uuid'], $second['uuid'], $third['uuid']]));
		$this->assertDue(['2030-01-15', 102000, 4], $uuid, $oil['uuid']);
		$this->assertSame('2034-01-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/**
	 * The last record, the middle and the first are deleted in that order, a second apart, and the
	 * reminder is dismissed before the first's delete, which so takes nothing back. The last
	 * record's Undo replays the first's delete, then cannot replay the middle one's: the replay
	 * rolls back, and the last record is restored alone. Both later records stay deleted as they were.
	 */
	public function testAReplayRefusedAfterAnotherLaterDeleteLeavesBothAsTheyWere(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second, $third] = $this->threeCloses($uuid);
		$deletedThird = $this->maintenance->delete(self::OWNER, $uuid, $third['uuid'], $third['updated_at']);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->deletedEarlier($third['uuid'], 2);
		$this->deletedEarlier($second['uuid']);
		$this->assertFalse($this->tookBack($first['uuid']));
		$this->assertTrue($this->tookBack($second['uuid']));
		$reminder = $this->reminder($uuid, $oil['uuid']);
		$later = fn (): array => array_map(fn (string $recordUuid): array => [$this->keptBy($recordUuid), $this->tokenOf($recordUuid), self::record($recordUuid)->getDeletedAt()], [$first['uuid'], $second['uuid']]);
		$before = $later();

		$this->assertNull($this->maintenance->restore(self::OWNER, $uuid, $third['uuid'], $deletedThird['updated_at'])['closes']);

		$this->assertSame($before, $later());
		$after = $this->reminder($uuid, $oil['uuid']);
		foreach (['due_date', 'due_odo', 'occurrence', 'snoozed_until', 'state', 'updated_at'] as $key) {
			$this->assertSame($reminder[$key], $after[$key], $key);
		}
	}

	/**
	 * Restoring the record alone after a refused replay relies on the rollback of the undo's own
	 * transaction. Inside another run there is none, so the undo refuses to start.
	 */
	public function testAnUndoInsideAnotherRunIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[, $first] = $this->twoCloses($uuid);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->expectException(\LogicException::class);
		\OCP\Server::get(AfterCommit::class)->run(fn (): array => $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']));
	}

	/** @return iterable<string, array{bool}> whether the reminder's delete is undone first */
	public static function undoOrders(): iterable {
		yield 'the reminder first' => [true];
		yield 'the record first' => [false];
	}

	/**
	 * The reminder is deleted, so deleting the record leaves it alone, and the record's close stays
	 * in effect. Undoing both, in either order, keeps the link and what the record kept.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('undoOrders')]
	public function testAnUndoOfACloseStillInEffectKeepsTheLink(bool $reminderFirst): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		if ($reminderFirst) {
			$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);
		}
		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);
		if (!$reminderFirst) {
			$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);
		}

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * A one-off stays done while its reminder is deleted, so the record's close stays in effect.
	 * Undoing both, in either order, keeps the link, and deleting the record then reopens it.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('undoOrders')]
	public function testAnUndoOfAOneOffStillDoneKeepsTheLink(bool $reminderFirst): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$once = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Towbar', 'mode' => Reminder::DATE, 'due_date' => '2031-12-31']);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['closes' => $once['uuid']]));
		$gone = $this->reminders->delete(self::OWNER, $uuid, $once['uuid'], $this->reminder($uuid, $once['uuid'])['updated_at']);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		if ($reminderFirst) {
			$this->reminders->restore(self::OWNER, $uuid, $once['uuid'], $gone['updated_at']);
		}
		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);
		if (!$reminderFirst) {
			$this->reminders->restore(self::OWNER, $uuid, $once['uuid'], $gone['updated_at']);
		}

		$this->assertSame($once['uuid'], $back['closes']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $once['uuid'])['state']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertSame(Reminder::PLANNED, $this->reminder($uuid, $once['uuid'])['state']);
	}

	/**
	 * The record is deleted before its reminder, so taking its close back reopened the reminder.
	 * Its undo closes the deleted reminder again without moving the reminder's token, so the
	 * reminder's undo still holds, and both snoozes are back where they were.
	 */
	public function testAnUndoClosesADeletedReminderAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $oil['updated_at'], '2031-03-01');
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2032-06-01');
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$gone = $this->reminders->delete(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);
		$this->reminders->restore(self::OWNER, $uuid, $oil['uuid'], $gone['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', 60000, '2031-03-01', 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
		$this->assertSame(Reminder::SNOOZED, $this->reminder($uuid, $oil['uuid'])['state']);
		$this->assertSame($back['updated_at'], $this->tokenOf($written['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $oil['uuid'])['snoozed_until']);
	}

	/** Unticking the reminder on the lower record hands its due up as deleting it does. */
	public function testUntickingTheLowerOfTwoClosesHandsItsDueUp(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$token = $this->tokenOf($second['uuid']);

		$this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500]));

		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $oil['uuid']);
		$this->assertSame([null, null, null, null, null, null, null, null, null, null], $this->keptBy($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame($token, $this->tokenOf($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $token);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * A km the reminder was given after the close is the person's, not the close's: taking the
	 * close back puts back the date and leaves the km as entered.
	 */
	public function testAnAxisAddedAfterTheCloseStaysAsEntered(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $written] = $this->checkUpGivenAKmAfterAClose($uuid);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertDue(['2027-03-01', 74000, 1], $uuid, $check['uuid']);
	}

	/**
	 * The km was added after a close by a record without a counter. Its close set no km, so its
	 * undo closes the reminder again without one, and the km stays as entered.
	 */
	public function testAnUndoWithoutACounterClosesAgainWhereTheCloseSetNoKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 74000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertDue(['2027-03-01', 74000, 1], $uuid, $check['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($written['uuid']));
		$this->assertNull($this->handedTo($written['uuid']));
		$this->assertDue(['2028-02-10', 74000, 2], $uuid, $check['uuid']);
	}

	/**
	 * The km was added between two closes, so the lower record kept none: the record above keeps
	 * the one it closed, and deleting both leaves the km as entered.
	 */
	public function testAnAxisAddedBetweenTwoClosesStaysAsEntered(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $first] = $this->checkUpGivenAKmAfterAClose($uuid);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'odo' => 73000, 'closes' => $check['uuid']]));

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame(['2027-03-01', 74000, null, 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2027-03-01', 74000, 1], $uuid, $check['uuid']);
	}

	/**
	 * The same for a date added between two closes: the lower close set none, so the record above
	 * still follows it on the km and keeps the date it closed.
	 */
	public function testADateAddedBetweenTwoClosesStaysAsEntered(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first] = $this->oilByKmGivenADateAfterAClose($uuid);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-03-01'), 'odo' => 73000, 'closes' => $oil['uuid']]));
		$this->assertSame(['2028-03-15', 73500, null, 2, null, null, null, '2029-03-01', 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame(['2028-03-15', 60000, null, 2, null, null, 1, '2029-03-01', 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2028-03-15', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The interval changed after the close, as the vehicle sheet's HU/AU field changes it, and the
	 * due stayed: the record stored the due its close set, so deleting it still puts back the
	 * planned due.
	 */
	public function testAnIntervalChangedAfterTheCloseStillPutsBackThePlannedDue(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->assertSame(['2028-02-10', 73500], $this->nextDueBy($written['uuid']));
		$this->changeInterval($uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * After the interval changed, only the record's day is corrected: the date is counted again with
	 * the new interval, and the km stays where the close put it, for no new counter counts it again.
	 */
	public function testADayCorrectedAfterAnIntervalChangeLeavesTheKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->changeInterval($uuid, $oil['uuid']);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58500, 'closes' => $oil['uuid']]));

		$this->assertDue(['2029-02-12', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame(['2029-02-12', 73500], $this->nextDueBy($written['uuid']));
	}

	/** The same under a record above: handing up still finds it, and deleting both puts back the planned due. */
	public function testAnIntervalChangedAfterTwoClosesStillHandsTheDueUp(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$this->changeInterval($uuid, $oil['uuid']);

		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($second['uuid'], $this->handedTo($first['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 2, null, null, 1, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $oil['uuid']);
	}

	/**
	 * The due km was edited by hand after the close, then the record's counter cleared, which is
	 * saved as the close is not in effect. The close set a km the reminder no longer holds, so
	 * deleting the record leaves the km that was entered.
	 */
	public function testACounterClearedOnACloseNoLongerInEffectLeavesTheEnteredKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 74000, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $oil['uuid']]));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->assertDue(['2028-02-10', 74000, 2], $uuid, $oil['uuid']);
	}

	/**
	 * A dismissal between two closes, then the lower record's day is slipped to a day that would
	 * give the due the dismissal planned, and set back. The lower close set another due, so the
	 * record above is not its record above: it keeps the due the dismissal planned, and deleting it
	 * puts that due back.
	 */
	public function testASlippedDayAcrossADismissalLeavesTheRecordAbove(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->reminders->dismiss(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at']);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2029-01-20'), 'closes' => $check['uuid']]));
		$this->assertDue(['2030-01-20', null, 4], $uuid, $check['uuid']);
		$slipped = $this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2028-02-10'), 'closes' => $check['uuid']]));
		// The slip moved nothing, so the record still stores the due its close set.
		$this->assertSame(['2028-02-10', null], $this->nextDueBy($first['uuid']));

		$this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $slipped['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));

		$this->assertSame(['2029-02-10', null, null, 3, null, null, null, '2030-01-20', null, null], $this->keptBy($second['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertDue(['2029-02-10', null, 3], $uuid, $check['uuid']);
	}

	/**
	 * The lower record handed its due up, then the reminder became one by date alone. Undoing the
	 * delete gives the record above back the km it kept before, though the reminder no longer has one.
	 */
	public function testAnUndoAfterHandingUpGivesBackAnAxisRemovedSince(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $first, $second] = $this->twoCloses($uuid);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::DATE, 'due_date' => '2029-02-01', 'recur_months' => 12,
		]);

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame(['2028-02-10', 73500, null, 2, null, null, null, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
	}

	/**
	 * The km was added after the close. Delete, then Undo: the record closes the reminder again at
	 * the date its close set, the km stays as entered, and the record still kept no km.
	 */
	public function testAnUndoLeavesAnAxisAddedAfterTheClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $written] = $this->checkUpGivenAKmAfterAClose($uuid);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertDue(['2028-02-10', 74000, 2], $uuid, $check['uuid']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($written['uuid']));
		$this->assertSame(['2028-02-10', null], $this->nextDueBy($written['uuid']));
	}

	/** The same for a date added after a close by km: it stays as entered through the delete and the undo. */
	public function testAnUndoLeavesADateAddedAfterTheClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$oil, $written] = $this->oilByKmGivenADateAfterAClose($uuid);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertDue(['2028-03-15', 60000, 1], $uuid, $oil['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertDue(['2028-03-15', 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame([null, 60000, null, 1, null, null, null, null, 73500, null], $this->keptBy($written['uuid']));
	}

	/**
	 * The same under a second close, both deleted and undone in turn: the stack comes back as it
	 * was, the km the second record kept with it.
	 */
	public function testUndoesInTurnLeaveAnAxisAddedAfterTheClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $first] = $this->checkUpGivenAKmAfterAClose($uuid);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'odo' => 73000, 'closes' => $check['uuid']]));
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $check['uuid']);
		$secondDeleted = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$firstDeleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $firstDeleted['updated_at']);
		$back = $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $secondDeleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertDue(['2029-02-01', 88000, 3], $uuid, $check['uuid']);
		$this->assertSame(['2028-02-10', 74000, null, 2, null, null, null, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
	}

	/**
	 * The reminder became one by date alone after the close. Delete, then Undo: the record keeps the
	 * km it kept, though the reminder no longer has one.
	 */
	public function testAnUndoKeepsAnAxisRemovedSince(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::DATE, 'due_date' => '2028-02-10', 'recur_months' => 12,
		]);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertDue(['2027-03-01', null, 1], $uuid, $oil['uuid']);

		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue(['2028-02-10', null, 2], $uuid, $oil['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));
		$this->assertSame(['2028-02-10', 73500], $this->nextDueBy($written['uuid']));
	}

	/**
	 * The reminder became one by km alone after the close. The delete puts back the planned km, and
	 * the Undo closes it again; the record keeps the date it kept, though the reminder no longer has one.
	 */
	public function testAnUndoKeepsADateRemovedSince(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::ODO, 'due_odo' => 73500, 'lead_odo' => 1000, 'recur_odo' => 15000,
		]);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertTrue($this->tookBack($written['uuid']));
		$this->assertDue([null, 60000, 1], $uuid, $oil['uuid']);

		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertDue([null, 73500, 2], $uuid, $oil['uuid']);
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($written['uuid']));
	}

	/**
	 * The interval changed after the close. Its close is still on top, so the delete takes it back.
	 * Undo: the reminder is due again where the close put it, not where the new interval would
	 * count from the record.
	 */
	public function testAnUndoAfterAnIntervalChangePutsBackTheDueTheCloseSet(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->changeInterval($uuid, $oil['uuid']);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertTrue($this->tookBack($written['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertDue(['2028-02-10', 73500, 2], $uuid, $oil['uuid']);
		$this->assertNull($this->tookBack($written['uuid']));
	}

	/**
	 * The next occurrence was dismissed, so deleting the record takes nothing back. Its Undo keeps
	 * the link and everything the record kept, and leaves the reminder as it is.
	 */
	public function testAnUndoAfterADismissalKeepsTheLinkAndWhatTheRecordKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->dismiss(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at']);
		$dismissed = $this->reminder($uuid, $oil['uuid']);
		$kept = ['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null];
		$this->assertSame($kept, $this->keptBy($written['uuid']));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame($kept, $this->keptBy($written['uuid']));
		$this->assertSame($dismissed, $this->reminder($uuid, $oil['uuid']));
	}

	/**
	 * The due was edited by hand after the close, so deleting the record takes nothing back, and
	 * marks that. Its Undo keeps the link and everything the record kept.
	 */
	public function testAnUndoAfterAHandEditOfTheDueKeepsTheLinkAndWhatTheRecordKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2028-06-01', 'due_odo' => 73500, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$edited = $this->reminder($uuid, $oil['uuid']);
		$kept = ['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500];

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertSame([...$kept, false], $this->keptBy($written['uuid']));
		$this->assertSame($edited, $this->reminder($uuid, $oil['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($oil['uuid'], $back['closes']);
		$this->assertSame([...$kept, null], $this->keptBy($written['uuid']));
		$this->assertSame($edited, $this->reminder($uuid, $oil['uuid']));
	}

	/**
	 * The reminder stopped recurring after the close, so deleting the record takes nothing back.
	 * Another record then closes it, and the Undo keeps the link: the one-off's close is the other
	 * record's, so deleting this one again leaves it done.
	 */
	public function testAnUndoAfterAnotherClosedTheOneOffKeepsTheLinkButNotTheClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->recurEvery($uuid, $check['uuid'], null);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->assertFalse($this->tookBack($first['uuid']));
		$this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'closes' => $check['uuid']]));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $back['updated_at']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
	}

	/**
	 * A check-up closed on 2027-02-10 stops recurring, and a second record closes it. Deleting the
	 * first record leaves the one-off done, for its close is the second record's; a one-off's close
	 * is no record above, so the second record keeps what it kept. Undo keeps the link.
	 */
	public function testDeletingARecordFromBeforeTheOneOffLeavesItsClose(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $first, $second] = $this->oneOffClosedBySecond($uuid);
		$done = $this->reminder($uuid, $check['uuid']);
		$kept = $this->keptBy($second['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($done, $this->reminder($uuid, $check['uuid']));
		$this->assertFalse($this->tookBack($first['uuid']));
		$this->assertSame($kept, $this->keptBy($second['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
		$this->assertSame($done, $this->reminder($uuid, $check['uuid']));
	}

	/**
	 * The same one-off. Deleting the second record opens it again, though the first record closed
	 * the occurrence before; Undo closes it again.
	 */
	public function testDeletingTheCloseOfTheOneOffOpensItAndUndoClosesIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, , $second] = $this->oneOffClosedBySecond($uuid);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);

		$this->assertNotSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
		$this->assertTrue($this->tookBack($second['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
		$this->assertSame(['2028-02-10', null, null, 2, null, null, null, null, null, null], $this->keptBy($second['uuid']));
	}

	/**
	 * The same one-off, deleted, then the second record, as a version did that did not mark what a
	 * delete took back. The reminder stayed done, and its close is the second record's, not the
	 * first's, so after both undos the second record still closes it.
	 */
	public function testAnUndoOfTheCloseOfTheOneOffStillDoneKeepsTheLink(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, , $second] = $this->oneOffClosedBySecond($uuid);
		$gone = $this->reminders->delete(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at']);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_maintenance')
			->set('took_back', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($second['uuid'])));
		$this->assertSame(1, $qb->executeStatement());
		$this->reminders->restore(self::OWNER, $uuid, $check['uuid'], $gone['updated_at']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
	}

	/**
	 * The same one-off, made to recur while done and a one-off again. The first record closed the
	 * occurrence before the done one, so deleting it leaves the one-off done, also once the second
	 * record is deleted; Undo keeps its link and what it kept.
	 */
	public function testARecordUnderTheDoneOneOffDoesNotOpenIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $first, $second] = $this->oneOffClosedBySecond($uuid);
		$this->recurEvery($uuid, $check['uuid'], 12);
		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->deletedEarlier($second['uuid']);
		$this->recurEvery($uuid, $check['uuid'], null);
		$done = $this->reminder($uuid, $check['uuid']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($done, $this->reminder($uuid, $check['uuid']));
		$this->assertFalse($this->tookBack($first['uuid']));

		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
		$this->assertSame($done, $this->reminder($uuid, $check['uuid']));
	}

	/**
	 * The same one-off, made to recur while done: the second record closed it as a one-off, so it is
	 * no record above, and deleting the first record takes nothing back. Made a one-off again,
	 * deleting the second record opens it, and Undo closes it again with what the second record
	 * kept; the first record's Undo keeps its link and its own values.
	 */
	public function testAnUndoOfTheOneOffsCloseAfterTheFirstDeleteClosesItAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		[$check, $first, $second] = $this->oneOffClosedBySecond($uuid);
		$kept = $this->keptBy($second['uuid']);
		$this->recurEvery($uuid, $check['uuid'], 12);
		$firstGone = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$this->assertFalse($this->tookBack($first['uuid']));
		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->recurEvery($uuid, $check['uuid'], null);
		$secondGone = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertNotSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $secondGone['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
		$this->assertSame($kept, $this->keptBy($second['uuid']));

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $firstGone['updated_at']);

		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
	}

	/**
	 * Both closes of a check-up taken back, the lower one handed up, then the check-up becomes a
	 * one-off. The second record's take-back put back the occurrence of the due handed up, so its
	 * Undo finds the one-off there and closes it again, at the occurrence the record closed:
	 * deleting the record again opens it.
	 */
	public function testAnUndoAfterTheReminderBecameAOneOffClosesItAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'closes' => $check['uuid']]));
		$this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));
		$this->assertDue(['2027-03-01', null, 1], $uuid, $check['uuid']);
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01',
		]);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
		$this->assertDue(['2027-03-01', null, 2], $uuid, $check['uuid']);
		$this->assertSame(['2027-03-01', null, null, 2, null, null, 1, '2029-02-01', null, null], $this->keptBy($second['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $back['updated_at']);

		$this->assertTrue($this->tookBack($second['uuid']));
		$this->assertNotSame(Reminder::DONE, $this->reminder($uuid, $check['uuid'])['state']);
	}

	/**
	 * The check-up from here on: due 2028-02-10, every that many months, or a one-off for null. The
	 * state stays, done, dismissed or not.
	 */
	private function recurEvery(string $vehicleUuid, string $reminderUuid, ?int $months): void {
		$this->reminders->update(self::OWNER, $vehicleUuid, $reminderUuid, $this->reminder($vehicleUuid, $reminderUuid)['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2028-02-10', 'recur_months' => $months,
		]);
	}

	/**
	 * The one-off was dismissed, then made to recur again. Its occurrence is over, so the record's
	 * close is not on top: deleting it leaves the dismissal, and Undo keeps the link.
	 */
	public function testDeletingARecordUnderADismissedOccurrenceLeavesIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->recurEvery($uuid, $check['uuid'], null);
		$this->reminders->dismiss(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at']);
		$this->recurEvery($uuid, $check['uuid'], 12);
		$dismissed = $this->reminder($uuid, $check['uuid']);
		$this->assertSame(Reminder::DISMISSED, $dismissed['state']);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($dismissed, $this->reminder($uuid, $check['uuid']));
		$back = $this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);
		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertSame($dismissed, $this->reminder($uuid, $check['uuid']));
	}

	/**
	 * The oil change became one by km alone between two closes, so the second record kept no date.
	 * Deleting the first hands only its km up; Undo gives the second record back what it kept.
	 */
	public function testAnUndoAfterHandingUpLeavesNoDateOnARecordThatKeptNone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::ODO, 'due_odo' => 73500, 'lead_odo' => 1000, 'recur_odo' => 15000,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'odo' => 73000, 'closes' => $oil['uuid']]));
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame([null, 73500, null, 2, null, null, null, null, 88000, null], $kept);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame([null, 60000, null, 2, null, null, 1, null, 88000, null], $this->keptBy($second['uuid']));

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertDue([null, 88000, 3], $uuid, $oil['uuid']);
	}

	/** The same for an oil change that became one by date alone: only the date is handed up. */
	public function testAnUndoAfterHandingUpLeavesNoKmOnARecordThatKeptNone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::DATE, 'due_date' => '2028-02-10', 'recur_months' => 12,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-02-01'), 'odo' => 73000, 'closes' => $oil['uuid']]));
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame(['2028-02-10', null, null, 2, null, null, null, '2029-02-01', null, null], $kept);

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame(['2027-03-01', null, null, 2, null, null, 1, '2029-02-01', null, null], $this->keptBy($second['uuid']));

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $deleted['updated_at']);

		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertDue(['2029-02-01', null, 3], $uuid, $oil['uuid']);
	}

	/**
	 * A record from before without a counter closed a check-up by date, which then recurs by km too.
	 * The record closes again from its own day and counter, and it has no counter, so Undo of its
	 * delete drops the link without a throw. The reminder holds what the delete put back.
	 */
	public function testAnUndoOfARecordFromBeforeWithoutTheCounterNowNeededDropsTheLink(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->keptNothing($this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']])));
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 74000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertDue(['2027-02-10', 74000, 1], $uuid, $check['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertNull($back['closes']);
		$this->assertDue(['2027-02-10', 74000, 1], $uuid, $check['uuid']);
	}

	/**
	 * The oil change lost its km between two closes and got it back after them, so the second
	 * record kept no km. Deleting the first still hands its due up on the date, and deleting the
	 * second then puts back the planned date; the km stays as entered. Both undos put the stack back.
	 */
	public function testAKmRemovedBetweenTwoClosesAndAddedBackStillHandsTheDueUp(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::DATE, 'due_date' => '2028-02-10', 'recur_months' => 12,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-01-20'), 'odo' => 70000, 'closes' => $oil['uuid']]));
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame(['2028-02-10', null, null, 2, null, null, null, '2029-01-20', null, null], $kept);
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2029-01-20', 'due_odo' => 85000, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);

		$firstGone = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($second['uuid'], $this->handedTo($first['uuid']));
		$this->assertSame(['2027-03-01', null, null, 2, null, null, 1, '2029-01-20', null, null], $this->keptBy($second['uuid']));

		$secondGone = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue(['2027-03-01', 85000, 1], $uuid, $oil['uuid']);

		$this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $secondGone['updated_at']);
		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $firstGone['updated_at']);

		$this->assertDue(['2029-01-20', 85000, 3], $uuid, $oil['uuid']);
		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', 60000, null, 1, null, null, null, '2028-02-10', 73500, null], $this->keptBy($first['uuid']));
	}

	/**
	 * The two records of testAKmRemovedBetweenTwoClosesAndAddedBackStillHandsTheDueUp(), with the
	 * first occurrence snoozed, deleted from the top. The second takes its close back on the date and
	 * leaves the km entered after it. That km is a hand edit of the axis the first close set, so the
	 * first record's delete takes nothing back, and the record keeps what it kept. Both undos, in
	 * the order Undo offers them, put the stack back.
	 */
	public function testAKmRemovedBetweenTwoClosesAndAddedBackLeavesTheFirstCloseWhenDeletedFromTheTop(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$this->reminders->snooze(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], '2031-03-20');
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::DATE, 'due_date' => '2028-02-10', 'recur_months' => 12,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-01-20'), 'odo' => 70000, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2029-01-20', 'due_odo' => 85000, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$stack = [$this->keptBy($first['uuid']), $this->keptBy($second['uuid'])];
		$this->assertSame(['2027-03-01', 60000, '2031-03-20', 1, null, null, null, '2028-02-10', 73500, null], $stack[0]);

		$secondGone = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $second['updated_at']);
		$this->deletedEarlier($second['uuid']);
		$this->assertDue(['2028-02-10', 85000, 2], $uuid, $oil['uuid']);
		$firstGone = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertFalse($this->tookBack($first['uuid']));
		$this->assertSame([...array_slice($stack[0], 0, 9), false], $this->keptBy($first['uuid']));
		$this->assertDue(['2028-02-10', 85000, 2], $uuid, $oil['uuid']);
		$this->assertNull($this->reminder($uuid, $oil['uuid'])['snoozed_until']);

		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $firstGone['updated_at']);
		$this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $secondGone['updated_at']);

		$this->assertDue(['2029-01-20', 85000, 3], $uuid, $oil['uuid']);
		$this->assertSame($stack, [$this->keptBy($first['uuid']), $this->keptBy($second['uuid'])]);
	}

	/**
	 * A check-up by date, snoozed, closed; given a km, closed again; then by km alone. The second
	 * record kept the date the first close set, so deleting the first hands its due and snooze up,
	 * though the reminder no longer recurs by date. Deleting the second then puts back the first
	 * occurrence with its snooze. Both undos put the stack back.
	 */
	public function testARecordAboveFollowsOnAnAxisTheReminderNoLongerRecursBy(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$this->reminders->snooze(self::OWNER, $uuid, $check['uuid'], $check['updated_at'], '2031-04-15');
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $check['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 75000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-01-20'), 'odo' => 70000, 'closes' => $check['uuid']]));
		$kept = $this->keptBy($second['uuid']);
		$this->assertSame(['2028-02-10', 75000, null, 2, null, null, null, '2029-01-20', 85000, null], $kept);
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::ODO, 'due_odo' => 85000, 'recur_odo' => 15000,
		]);

		$firstGone = $this->maintenance->delete(self::OWNER, $uuid, $first['uuid'], $first['updated_at']);

		$this->assertSame($second['uuid'], $this->handedTo($first['uuid']));
		$this->assertSame(['2027-03-01', 75000, '2031-04-15', 2, null, null, 1, '2029-01-20', 85000, null], $this->keptBy($second['uuid']));

		$secondGone = $this->maintenance->delete(self::OWNER, $uuid, $second['uuid'], $this->tokenOf($second['uuid']));

		$this->assertDue([null, 75000, 1], $uuid, $check['uuid']);
		$this->assertSame('2031-04-15', $this->reminder($uuid, $check['uuid'])['snoozed_until']);

		$this->maintenance->restore(self::OWNER, $uuid, $second['uuid'], $secondGone['updated_at']);
		$this->maintenance->restore(self::OWNER, $uuid, $first['uuid'], $firstGone['updated_at']);

		$this->assertDue([null, 85000, 3], $uuid, $check['uuid']);
		$this->assertSame($kept, $this->keptBy($second['uuid']));
		$this->assertSame(['2027-03-01', null, '2031-04-15', 1, null, null, null, '2028-02-10', null, null], $this->keptBy($first['uuid']));
	}

	/**
	 * A one-off closed on 2027-02-27, deleted, made to recur every 12 months, and the delete undone.
	 * The close set no due, so the record closes it as a new close would, from its own day, and
	 * stores that due: deleting it again finds its close on top.
	 */
	public function testAnUndoAfterAOneOffWasMadeToRecurClosesItFromTheRecord(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01']);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-27'), 'closes' => $check['uuid']]));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertDue(['2027-03-01', null, 1], $uuid, $check['uuid']);
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12,
		]);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertDue(['2028-02-27', null, 2], $uuid, $check['uuid']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-27', null, null], $this->keptBy($written['uuid']));

		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $back['updated_at']);

		$this->assertTrue($this->tookBack($written['uuid']));
		$this->assertDue(['2027-03-01', null, 1], $uuid, $check['uuid']);
	}

	/**
	 * A one-off by date or km, closed by a record without a counter, deleted, and made to recur by
	 * both. A new close would need the counter, so the undo cannot count the km due from the record:
	 * it drops the link, and the reminder keeps the due the record kept.
	 */
	public function testAnUndoAfterAOneOffWasMadeToRecurByKmDropsTheLinkWithoutACounter(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2027-03-01', 'due_odo' => 60000]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-26'), 'closes' => $check['uuid']]));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2027-03-01', 'due_odo' => 60000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertNull($back['closes']);
		$this->assertDue(['2027-03-01', 60000, 1], $uuid, $check['uuid']);
	}

	/**
	 * A check-up by date, closed, deleted, switched to km alone, and the delete undone. The close
	 * set no km, so nothing tells where it would stand: the record keeps its link and its values,
	 * the reminder stays at its due and occurrence, and the next occurrence's snooze comes back by
	 * the later day.
	 */
	public function testAnUndoAfterASwitchToAnAxisTheCloseSetNothingOnKeepsTheLink(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->reminders->snooze(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], '2032-06-01');
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->reminders->snooze(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], '2031-06-01');
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::ODO, 'due_odo' => 61000, 'recur_odo' => 15000,
		]);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertDue([null, 61000, 1], $uuid, $check['uuid']);
		$this->assertSame('2032-06-01', $this->reminder($uuid, $check['uuid'])['snoozed_until']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($written['uuid']));
	}

	/**
	 * The undo above kept the link without closing, so the record still names the occurrence the
	 * reminder stands at. A new record then closes that occurrence: its close is the one on top, as
	 * the kept record has nothing to tell, so deleting the new record takes its close back.
	 */
	public function testACloseAfterAnUndoThatKeptTheLinkCanBeTakenBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::ODO, 'due_odo' => 61000, 'recur_odo' => 15000,
		]);
		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);
		$next = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-04-01'), 'odo' => 60500, 'closes' => $check['uuid']]));
		$this->assertDue([null, 75500, 2], $uuid, $check['uuid']);

		$gone = $this->maintenance->delete(self::OWNER, $uuid, $next['uuid'], $next['updated_at']);

		$this->assertTrue($this->tookBack($next['uuid']));
		$this->assertDue([null, 61000, 1], $uuid, $check['uuid']);

		$this->maintenance->restore(self::OWNER, $uuid, $next['uuid'], $gone['updated_at']);

		$this->assertDue([null, 75500, 2], $uuid, $check['uuid']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-10', null, null], $this->keptBy($written['uuid']));
	}

	/**
	 * Tab 1 deletes X. Tab 2 records N for the same occurrence on another day and deletes it. Tab 1
	 * undoes X: replaying N's undo first would leave X closing nothing, so X is restored alone, at
	 * the due its close set, and N stays deleted as it was.
	 */
	public function testAnUndoWhoseReplayWouldDropTheRecordsOwnLinkRestoresItAlone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$x = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-26'), 'closes' => $check['uuid']]));
		$xGone = $this->maintenance->delete(self::OWNER, $uuid, $x['uuid'], $x['updated_at']);
		$n = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-03-05'), 'closes' => $check['uuid']]));
		$this->maintenance->delete(self::OWNER, $uuid, $n['uuid'], $n['updated_at']);
		$this->deletedEarlier($x['uuid']);
		$this->assertDue(['2027-03-01', null, 1], $uuid, $check['uuid']);
		$nKept = $this->keptBy($n['uuid']);
		$nDeletedAt = self::record($n['uuid'])->getDeletedAt();
		$nToken = $this->tokenOf($n['uuid']);

		$back = $this->maintenance->restore(self::OWNER, $uuid, $x['uuid'], $xGone['updated_at']);

		$this->assertSame($check['uuid'], $back['closes']);
		$this->assertDue(['2028-02-26', null, 2], $uuid, $check['uuid']);
		$this->assertSame(['2027-03-01', null, null, 1, null, null, null, '2028-02-26', null, null], $this->keptBy($x['uuid']));
		$this->assertSame($nKept, $this->keptBy($n['uuid']));
		$this->assertSame($nDeletedAt, self::record($n['uuid'])->getDeletedAt());
		$this->assertSame($nToken, $this->tokenOf($n['uuid']));
	}

	/**
	 * A check-up every 12 months, due 2027-03-01, closed on 2027-02-10. It then stops recurring,
	 * due 2028-02-10, and a second record closes it on 2028-02-01: done, its second occurrence.
	 *
	 * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} the reminder and the two records
	 */
	private function oneOffClosedBySecond(string $vehicleUuid): array {
		$check = $this->reminders->create(self::OWNER, $vehicleUuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$first = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->recurEvery($vehicleUuid, $check['uuid'], null);
		$second = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2028-02-01'), 'closes' => $check['uuid']]));
		$this->assertDue(['2028-02-10', null, 2], $vehicleUuid, $check['uuid']);
		$this->assertSame(Reminder::DONE, $this->reminder($vehicleUuid, $check['uuid'])['state']);

		return [$check, $first, $second];
	}

	/**
	 * The km was added after a close by a record without a counter. Its counter is given and taken
	 * away again, as an edit and its Undo: the close set no km, so it needs none.
	 */
	public function testAnEditBackToNoCounterIsSavedWhereTheCloseSetNoKm(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$check = $this->reminders->create(self::OWNER, $uuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $check['uuid'], $this->reminder($uuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 74000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work(['done_at' => self::on('2027-02-12'), 'odo' => 58600, 'closes' => $check['uuid']]));
		$this->assertDue(['2028-02-12', 74000, 2], $uuid, $check['uuid']);

		$this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $edited['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'closes' => $check['uuid']]));

		$this->assertDue(['2028-02-10', 74000, 2], $uuid, $check['uuid']);
		$this->assertNull(self::record($written['uuid'])->getOdo());
		$this->assertSame(['2028-02-10', null], $this->nextDueBy($written['uuid']));
	}

	/**
	 * The due date was edited by hand between two closes, then the reminder became one by km alone.
	 * The record above kept the date that was entered, not the one the lower close set, so an edit of
	 * the lower record's counter leaves it.
	 */
	public function testAnEditBelowAHandEditLeavesTheDateTheRecordAboveKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$oil = $this->oilChange($uuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => '2028-06-01', 'due_odo' => 73500, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);
		$second = $this->maintenance->record(self::OWNER, $uuid, $this->work(['done_at' => self::on('2028-05-01'), 'odo' => 73000, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $uuid, $oil['uuid'], $this->reminder($uuid, $oil['uuid'])['updated_at'], [
			'mode' => Reminder::ODO, 'due_odo' => 88000, 'lead_odo' => 1000, 'recur_odo' => 15000,
		]);

		$this->maintenance->update(self::OWNER, $uuid, $first['uuid'], $first['updated_at'], $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58700, 'closes' => $oil['uuid']]));

		$this->assertSame(['2028-06-01', 73500, null, 2, null, null, null, '2029-05-01', 88000, null], $this->keptBy($second['uuid']));
	}

	/** Every 24 months or 20 000 km from now on; the due stays where the close put it. */
	private function changeInterval(string $vehicleUuid, string $reminderUuid): void {
		$reminder = $this->reminder($vehicleUuid, $reminderUuid);
		$this->reminders->update(self::OWNER, $vehicleUuid, $reminderUuid, $reminder['updated_at'], [
			'mode' => Reminder::EITHER, 'due_date' => $reminder['due_date'], 'due_odo' => $reminder['due_odo'], 'lead_odo' => 1000, 'recur_months' => 24, 'recur_odo' => 20000,
		]);
	}

	/**
	 * A check-up every 12 months, due 2027-03-01, closed on 2027-02-10 at 58 500 km. Then it is
	 * given a km too: every 15 000 km, due at 74 000, not the 73 500 the close would have given.
	 *
	 * @return array{array<string, mixed>, array<string, mixed>} the reminder and the record
	 */
	private function checkUpGivenAKmAfterAClose(string $vehicleUuid): array {
		$check = $this->reminders->create(self::OWNER, $vehicleUuid, ['title' => 'Check-up', 'mode' => Reminder::DATE, 'due_date' => '2027-03-01', 'recur_months' => 12]);
		$written = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $check['uuid']]));
		$this->reminders->update(self::OWNER, $vehicleUuid, $check['uuid'], $this->reminder($vehicleUuid, $check['uuid'])['updated_at'], [
			'title' => 'Check-up', 'mode' => Reminder::EITHER, 'due_date' => '2028-02-10', 'due_odo' => 74000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);

		return [$check, $written];
	}

	/**
	 * An oil change every 15 000 km, due at 60 000, closed on 2027-02-10 at 58 500 km. Then it is
	 * given a date too: every 12 months, due 2028-03-15, not the 2028-02-10 the close would have given.
	 *
	 * @return array{array<string, mixed>, array<string, mixed>} the reminder and the record
	 */
	private function oilByKmGivenADateAfterAClose(string $vehicleUuid): array {
		$oil = $this->reminders->create(self::OWNER, $vehicleUuid, ['title' => 'Oil change', 'mode' => Reminder::ODO, 'due_odo' => 60000, 'lead_odo' => 1000, 'recur_odo' => 15000]);
		$written = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$this->reminders->update(self::OWNER, $vehicleUuid, $oil['uuid'], $this->reminder($vehicleUuid, $oil['uuid'])['updated_at'], [
			'title' => 'Oil change', 'mode' => Reminder::EITHER, 'due_date' => '2028-03-15', 'due_odo' => 73500, 'lead_odo' => 1000, 'recur_months' => 12, 'recur_odo' => 15000,
		]);

		return [$oil, $written];
	}

	/**
	 * An oil change due 2027-03-01 or at 60 000 km, closed on 2027-02-10 at 58 500 km and again on
	 * 2028-02-01 at 73 000 km: due 2029-02-01 or at 88 000 km, its third occurrence.
	 *
	 * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} the reminder and the two records
	 */
	private function twoCloses(string $vehicleUuid): array {
		$oil = $this->oilChange($vehicleUuid, '2027-03-01', 60000);
		$first = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2027-02-10'), 'odo' => 58500, 'closes' => $oil['uuid']]));
		$second = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on('2028-02-01'), 'odo' => 73000, 'closes' => $oil['uuid']]));
		$this->assertDue(['2029-02-01', 88000, 3], $vehicleUuid, $oil['uuid']);
		$this->assertSame(['2028-02-10', 73500, null, 2, null, null, null, '2029-02-01', 88000, null], $this->keptBy($second['uuid']));

		return [$oil, $first, $second];
	}

	/**
	 * An oil change due 2027-03-01 or at 60 000 km, snoozed before each close: closed on 2027-02-10
	 * at 58 500 km, on 2028-02-01 at 73 000 km, and on 2029-01-15 at 87 000 km. Each close keeps the
	 * snooze before it; the fourth occurrence, due 2030-01-15 or at 102 000 km, is snoozed too.
	 *
	 * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>, array<string, mixed>} the reminder and the three records
	 */
	private function threeCloses(string $vehicleUuid): array {
		$oil = $this->oilChange($vehicleUuid, '2027-03-01', 60000);
		$snoozeThenClose = function (string $snooze, string $day, int $odo) use ($vehicleUuid, $oil): array {
			$this->reminders->snooze(self::OWNER, $vehicleUuid, $oil['uuid'], $this->reminder($vehicleUuid, $oil['uuid'])['updated_at'], $snooze);

			return $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on($day), 'odo' => $odo, 'closes' => $oil['uuid']]));
		};
		$records = [$snoozeThenClose('2031-03-01', '2027-02-10', 58500), $snoozeThenClose('2032-06-01', '2028-02-01', 73000), $snoozeThenClose('2033-03-01', '2029-01-15', 87000)];
		$this->reminders->snooze(self::OWNER, $vehicleUuid, $oil['uuid'], $this->reminder($vehicleUuid, $oil['uuid'])['updated_at'], '2034-01-01');
		$this->assertDue(['2030-01-15', 102000, 4], $vehicleUuid, $oil['uuid']);

		return [$oil, ...$records];
	}

	/**
	 * The oil change of twoCloses(), closed `$count` times, a year apart, with no snooze: on
	 * 2027-02-10 at 58 500 km, 2028-02-01 at 73 000 km, 2029-01-15 at 87 000 km and 2030-01-10 at
	 * 101 000 km.
	 *
	 * @param int<1, 4> $count
	 * @return non-empty-list<array<string, mixed>> the reminder, then the records in the order they closed it
	 */
	private function closesWithoutSnooze(string $vehicleUuid, int $count): array {
		$oil = $this->oilChange($vehicleUuid, '2027-03-01', 60000);
		$records = [];
		foreach (array_slice([['2027-02-10', 58500], ['2028-02-01', 73000], ['2029-01-15', 87000], ['2030-01-10', 101000]], 0, $count) as [$day, $odo]) {
			$records[] = $this->maintenance->record(self::OWNER, $vehicleUuid, $this->work(['done_at' => self::on($day), 'odo' => $odo, 'closes' => $oil['uuid']]));
		}

		return [$oil, ...$records];
	}

	/** Sets the occurrence the record's close replaced, its token untouched. */
	private function closedOccurrenceSetTo(string $recordUuid, int $occurrence): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_maintenance')
			->set('closed_occurrence', $qb->createNamedParameter($occurrence))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($recordUuid)));
		$this->assertSame(1, $qb->executeStatement());
	}

	/**
	 * The record as one written before it kept anything for its close: its due, km, snooze,
	 * occurrence and the due its close set null.
	 *
	 * @param array<string, mixed> $record the record's answer
	 * @return array<string, mixed> the same answer, still its token
	 */
	private function keptNothing(array $record): array {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_maintenance')
			->set('closed_due_date', $qb->createNamedParameter(null))
			->set('closed_due_odo', $qb->createNamedParameter(null))
			->set('closed_snoozed_until', $qb->createNamedParameter(null))
			->set('closed_occurrence', $qb->createNamedParameter(null))
			->set('next_due_date', $qb->createNamedParameter(null))
			->set('next_due_odo', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($record['uuid'])));
		$this->assertSame(1, $qb->executeStatement());

		return $record;
	}

	/**
	 * Moves the record's delete that many seconds back. The deletes of a test come in the same
	 * second, where their order is a guess (MaintenanceMapper::findDeletedAfter()). Quoted, as
	 * Oracle upper-cases a bare name in raw SQL (docs/development.md#oracle).
	 */
	private function deletedEarlier(string $recordUuid, int $seconds = 1): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_maintenance')
			->set('deleted_at', $qb->createFunction($qb->getColumnName('deleted_at') . ' - ' . $seconds))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($recordUuid)));
		$this->assertSame(1, $qb->executeStatement());
	}

	/**
	 * Everything the record kept, read back from the database: off the wire, so no answer carries
	 * it. All ten at once, so a write that skips one cannot pass unseen: the due date, km, snooze
	 * and occurrence its close replaced; the next occurrence's snooze, the record it handed them
	 * to, the occurrence of a due handed up; the due date and km its close set; whether its delete
	 * took the close back.
	 *
	 * @return array{?string, ?int, ?string, ?int, ?string, ?string, ?int, ?string, ?int, ?bool}
	 */
	private function keptBy(string $recordUuid): array {
		$record = self::record($recordUuid);

		return [
			$record->getClosedDueDate()?->format('Y-m-d'),
			$record->getClosedDueOdo(),
			$record->getClosedSnoozedUntil()?->format('Y-m-d'),
			$record->getClosedOccurrence(),
			$record->getNextSnoozedUntil()?->format('Y-m-d'),
			$this->handedTo($recordUuid),
			$record->getKeptOccurrence(),
			$record->getNextDueDate()?->format('Y-m-d'),
			$record->getNextDueOdo(),
			$record->getTookBack(),
		];
	}

	/**
	 * The due the record's close set, read back from the database.
	 *
	 * @return array{?string, ?int} the due date and the due km
	 */
	private function nextDueBy(string $recordUuid): array {
		$record = self::record($recordUuid);

		return [$record->getNextDueDate()?->format('Y-m-d'), $record->getNextDueOdo()];
	}

	/** Whether the record's delete took its close back, read back from the database. */
	private function tookBack(string $recordUuid): ?bool {
		return self::record($recordUuid)->getTookBack();
	}

	/** The uuid of the record this one handed what it kept to, read back from the database. */
	private function handedTo(string $recordUuid): ?string {
		$id = self::record($recordUuid)->getHandedTo();

		return $id === null ? null : \OCP\Server::get(MaintenanceMapper::class)->findAnyById($id)->getUuid();
	}

	/** That point of that occurrence reached the owner on that channel. */
	private function sent(string $reminderUuid, int $occurrence, string $point, string $channel): void {
		$reminder = clone \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($reminderUuid);
		$reminder->setOccurrence($occurrence);
		$this->assertNotNull(\OCP\Server::get(ReminderReceiptMapper::class)->claim($reminder, $point, $channel, self::OWNER, self::on('2027-01-10')));
	}

	/**
	 * The receipts of that occurrence, read back from the database.
	 *
	 * @return list<string> channel and point of each, sorted
	 */
	private function receipts(string $reminderUuid, int $occurrence): array {
		$reminder = clone \OCP\Server::get(ReminderMapper::class)->findAnyByUuid($reminderUuid);
		$reminder->setOccurrence($occurrence);
		$receipts = array_map(static fn (ReminderReceipt $receipt): string => $receipt->getChannel() . ' ' . $receipt->getPoint(), \OCP\Server::get(ReminderReceiptMapper::class)->findByOccurrence($reminder));
		sort($receipts);

		return $receipts;
	}

	private function tokenOf(string $recordUuid): int {
		return self::record($recordUuid)->getUpdatedAt();
	}

	private static function record(string $recordUuid): Maintenance {
		return \OCP\Server::get(MaintenanceMapper::class)->findAnyByUuid($recordUuid);
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
	 * An oil change every 12 months or 15 000 km, due on that day or at that km.
	 *
	 * @return array<string, mixed>
	 */
	private function oilChange(string $vehicleUuid, string $dueDate = '2025-09-30', int $dueOdo = 125000): array {
		return $this->reminders->create(self::OWNER, $vehicleUuid, [
			'template_key' => 'oil_change',
			'due_date' => $dueDate,
			'due_odo' => $dueOdo,
		]);
	}

	/** Mid-morning on that day, where work() says the work was done. */
	private static function on(string $day): int {
		return (new \DateTimeImmutable($day . ' 10:00', new \DateTimeZone('UTC')))->getTimestamp();
	}

	/** @param array{?string, ?int, int} $due the due date, the due km and the occurrence */
	private function assertDue(array $due, string $vehicleUuid, string $reminderUuid): void {
		$reminder = $this->reminder($vehicleUuid, $reminderUuid);
		$this->assertSame($due, [$reminder['due_date'], $reminder['due_odo'], $reminder['occurrence']]);
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
