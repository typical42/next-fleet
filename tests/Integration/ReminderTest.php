<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A Reminder against the real database: what the sheet may write, and who may write it
 * (docs/architecture.md#reminder-engine).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ReminderTest extends TestCase {
	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const MANAGER = 'nextfleet-test-dave';
	private const DRIVER = 'nextfleet-test-erin';

	private ReminderService $reminders;
	private VehicleService $vehicles;
	private OdometerService $odometer;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->reminders = $container->get(ReminderService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::DRIVER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_odo_readings' => 'created_by', 'fleet_reminders' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** A template brings its mode, recurrence and lead; the title stays null, so it translates. */
	public function testATemplateFillsWhatTheSheetLeftOut(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), [
			'template_key' => 'oil_change',
			'due_date' => '2027-03-31',
			'due_odo' => 135000,
		]);

		$this->assertSame('oil_change', $written['template_key']);
		$this->assertNull($written['title']);
		$this->assertSame(Reminder::EITHER, $written['mode']);
		$this->assertSame('2027-03-31', $written['due_date']);
		$this->assertSame(135000, $written['due_odo']);
		$this->assertSame(1000, $written['lead_odo']);
		$this->assertSame(12, $written['recur_months']);
		$this->assertSame(15000, $written['recur_odo']);
		$this->assertSame(Reminder::PLANNED, $written['state']);
		$this->assertSame(1, $written['occurrence']);
		$this->assertSame([$written['uuid']], array_column($this->reminders->list(self::OWNER, $vehicle->getUuid()), 'uuid'));
	}

	/** A mode the request names wins, and the template fills only the parts that mode reads. */
	public function testATemplateFillsOnlyWhatTheChosenModeReads(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), [
			'template_key' => 'oil_change',
			'mode' => Reminder::DATE,
			'due_date' => '2027-03-31',
		]);

		$this->assertSame(Reminder::DATE, $written['mode']);
		$this->assertSame(12, $written['recur_months']);
		$this->assertNull($written['lead_odo']);
		$this->assertNull($written['recur_odo']);
	}

	/** HU/AU is a template only where the vehicle's jurisdiction requires it, at its type's cadence. */
	public function testTheInspectionIsATemplateWhereTheJurisdictionHasOne(): void {
		$truck = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'vehicle_type' => 'truck', 'jurisdiction' => 'de']);
		$elsewhere = $this->vehicles->create(self::OWNER, ['plate' => 'XY 123', 'jurisdiction' => 'generic']);

		$written = $this->reminders->create(self::OWNER, $truck->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2027-05-31']);

		$this->assertSame(Reminder::DATE, $written['mode']);
		$this->assertSame(12, $written['recur_months']);
		$this->expectException(\InvalidArgumentException::class);
		$this->reminders->create(self::OWNER, $elsewhere->getUuid(), ['template_key' => 'hu_au', 'due_date' => '2027-05-31']);
	}

	/** An own title is stored as given, and the warning points default as the columns do. */
	public function testAnOwnTitleIsStoredAndTheWarningPointsDefault(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), [
			'title' => 'Insurance renewal',
			'mode' => Reminder::DATE,
			'due_date' => '2027-01-01',
			'warn_month_start' => true,
		]);

		$this->assertSame('Insurance renewal', $written['title']);
		$this->assertNull($written['template_key']);
		$this->assertNull($written['recur_months']);
		$this->assertTrue($written['warn_month_before']);
		$this->assertTrue($written['warn_month_start']);
		$this->assertTrue($written['warn_due_date']);
	}

	/**
	 * What the sheet cannot send: no title and no template, a template the vehicle does not
	 * offer, a mode missing its due field or given another mode's, a recurrence of nothing.
	 *
	 * @dataProvider refusals
	 * @param array<string, mixed> $fields
	 */
	public function testASheetThatDoesNotAddUpIsRefused(array $fields): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->reminders->create(self::OWNER, $vehicle->getUuid(), $fields);
	}

	/**
	 * An edit replaces what the sheet sets and keeps what it does not: the template, the state and
	 * the occurrence. A template fills only at creation, so an edit can clear a recurrence.
	 */
	public function testAnEditRewritesTheSheetAndNothingElse(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'brake_fluid', 'due_date' => '2027-03-31']);

		$edited = $this->reminders->update(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], [
			'mode' => Reminder::DATE,
			'due_date' => '2027-04-30',
			'warn_month_before' => false,
		]);

		$this->assertSame('brake_fluid', $edited['template_key']);
		$this->assertSame('2027-04-30', $edited['due_date']);
		$this->assertNull($edited['recur_months']);
		$this->assertFalse($edited['warn_month_before']);
		$this->assertSame(Reminder::PLANNED, $edited['state']);
		$this->assertSame(1, $edited['occurrence']);
		$this->assertSame('2027-04-30', $this->reminders->list(self::OWNER, $vehicle->getUuid())[0]['due_date']);

		$this->expectException(StaleUpdateException::class);
		$this->reminders->update(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], ['mode' => Reminder::DATE, 'due_date' => '2027-05-31']);
	}

	/** A delete takes it off the list; undo, on the token the delete answered with, brings it back. */
	public function testADeleteIsUndone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2027-04-15']);

		$deleted = $this->reminders->delete(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']);

		$this->assertNotNull($deleted['deleted_at']);
		$this->assertSame([], $this->reminders->list(self::OWNER, $vehicle->getUuid()));

		$this->reminders->restore(self::OWNER, $vehicle->getUuid(), $written['uuid'], $deleted['updated_at']);

		$this->assertSame([$written['uuid']], array_column($this->reminders->list(self::OWNER, $vehicle->getUuid()), 'uuid'));
	}

	/** A manager writes reminders as the owner does; a driver reads them and writes nothing. */
	public function testAManagerWritesAndADriverOnlyReads(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant((int)$vehicle->getId(), self::MANAGER, 'manager');
		$this->grant((int)$vehicle->getId(), self::DRIVER, 'driver');

		$written = $this->reminders->create(self::MANAGER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2027-04-15']);

		$this->assertSame([$written['uuid']], array_column($this->reminders->list(self::DRIVER, $vehicle->getUuid()), 'uuid'));
		$refused = 0;
		foreach ([
			fn (): array => $this->reminders->create(self::DRIVER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2027-04-15']),
			fn (): array => $this->reminders->update(self::DRIVER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], ['mode' => 'date', 'due_date' => '2027-05-15']),
			fn (): array => $this->reminders->delete(self::DRIVER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']),
			fn (): array => $this->reminders->restore(self::DRIVER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']),
		] as $write) {
			try {
				$write();
			} catch (AccessDeniedException) {
				$refused++;
			}
		}
		$this->assertSame(4, $refused);
		$this->assertSame('2027-04-15', $this->reminders->list(self::OWNER, $vehicle->getUuid())[0]['due_date']);
	}

	/** A reminder is reached through the vehicle the route names, never by its uuid alone. */
	public function testAReminderOnAnotherVehicleIsNotThere(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 456']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2027-04-15']);

		$this->expectException(DoesNotExistException::class);
		$this->reminders->delete(self::OWNER, $other->getUuid(), $written['uuid'], $written['updated_at']);
	}

	/** A snooze holds the reminder until its day and leaves the due date where it was. */
	public function testASnoozeLeavesTheDueDateAlone(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2036-04-15']);

		$snoozed = $this->reminders->snooze(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], '2036-05-01');

		$this->assertSame(Reminder::SNOOZED, $snoozed['state']);
		$this->assertSame('2036-05-01', $snoozed['snoozed_until']);
		$this->assertSame('2036-04-15', $snoozed['due_date']);
		$this->assertSame(1, $snoozed['occurrence']);
	}

	/** A snooze is until a day still to come; one that is over would silence nothing. */
	public function testASnoozeIntoThePastIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2036-04-15']);

		$this->expectException(\InvalidArgumentException::class);
		$this->reminders->snooze(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], '2020-01-01');
	}

	/**
	 * Dismissing skips this occurrence: the next one is due a recurrence after the planned one,
	 * and its state is evaluated at once.
	 */
	public function testADismissalSkipsToTheNextOccurrence(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2036-04-15']);
		$snoozed = $this->reminders->snooze(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], '2036-05-01');

		$dismissed = $this->reminders->dismiss(self::OWNER, $vehicle->getUuid(), $written['uuid'], $snoozed['updated_at']);

		$this->assertSame('2036-10-15', $dismissed['due_date']);
		$this->assertSame(2, $dismissed['occurrence']);
		$this->assertSame(Reminder::PLANNED, $dismissed['state']);
		$this->assertNull($dismissed['snoozed_until']);
	}

	/** The next occurrence by km is measured against the main chain as it stands. */
	public function testADismissedOccurrenceByKmIsEvaluatedAgainstTheCounter(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750000000, 'read_at_off' => 120, 'value' => 149500]);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'oil_change', 'mode' => Reminder::ODO, 'due_odo' => 135000]);

		$dismissed = $this->reminders->dismiss(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']);

		$this->assertSame(150000, $dismissed['due_odo']);
		$this->assertSame(Reminder::WARNED, $dismissed['state']);
	}

	/** Without a recurrence there is no next occurrence, and a dismissed one takes no snooze. */
	public function testADismissalWithoutARecurrenceEndsTheOccurrence(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['title' => 'Insurance renewal', 'mode' => Reminder::DATE, 'due_date' => '2036-01-01']);

		$dismissed = $this->reminders->dismiss(self::OWNER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']);

		$this->assertSame(Reminder::DISMISSED, $dismissed['state']);
		$this->assertSame('2036-01-01', $dismissed['due_date']);
		$this->expectException(\InvalidArgumentException::class);
		$this->reminders->snooze(self::OWNER, $vehicle->getUuid(), $written['uuid'], $dismissed['updated_at'], '2036-02-01');
	}

	/** Snoozing and dismissing are writes: a manager may, a driver may not. */
	public function testADriverNeitherSnoozesNorDismisses(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant((int)$vehicle->getId(), self::MANAGER, 'manager');
		$this->grant((int)$vehicle->getId(), self::DRIVER, 'driver');
		$written = $this->reminders->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'tyre_swap', 'due_date' => '2036-04-15']);

		$refused = 0;
		foreach ([
			fn () => $this->reminders->snooze(self::DRIVER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], '2036-05-01'),
			fn () => $this->reminders->dismiss(self::DRIVER, $vehicle->getUuid(), $written['uuid'], $written['updated_at']),
		] as $write) {
			try {
				$write();
			} catch (AccessDeniedException) {
				$refused++;
			}
		}
		$snoozed = $this->reminders->snooze(self::MANAGER, $vehicle->getUuid(), $written['uuid'], $written['updated_at'], '2036-05-01');

		$this->assertSame(2, $refused);
		$this->assertSame(Reminder::SNOOZED, $snoozed['state']);
	}

	private function grant(int $vehicleId, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId($vehicleId);
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);

		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function refusals(): iterable {
		yield 'no title, no template' => [['mode' => 'date', 'due_date' => '2027-01-01']];
		yield 'a title beside a template' => [['template_key' => 'tyre_swap', 'title' => 'Tyres', 'due_date' => '2027-01-01']];
		yield 'an unknown template' => [['template_key' => 'wax', 'due_date' => '2027-01-01']];
		yield 'no mode' => [['title' => 'Wax', 'due_date' => '2027-01-01']];
		yield 'by date without a date' => [['title' => 'Wax', 'mode' => 'date']];
		yield 'by odometer without a km' => [['title' => 'Wax', 'mode' => 'odo']];
		yield 'either without a km' => [['title' => 'Wax', 'mode' => 'either', 'due_date' => '2027-01-01']];
		yield 'a km on a date reminder' => [['title' => 'Wax', 'mode' => 'date', 'due_date' => '2027-01-01', 'due_odo' => 1000]];
		yield 'a date on a km reminder' => [['title' => 'Wax', 'mode' => 'odo', 'due_odo' => 1000, 'due_date' => '2027-01-01']];
		yield 'not a day' => [['title' => 'Wax', 'mode' => 'date', 'due_date' => '2027-02-30']];
		yield 'either, recurring by one axis only' => [['title' => 'Wax', 'mode' => 'either', 'due_date' => '2027-01-01', 'due_odo' => 1000, 'recur_months' => 12]];
		yield 'a recurrence of nothing' => [['title' => 'Wax', 'mode' => 'date', 'due_date' => '2027-01-01', 'recur_months' => 0]];
	}
}
