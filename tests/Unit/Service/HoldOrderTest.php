<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\AfterCommit;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\OwnFiles;
use OCA\NextFleet\Service\Pending;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\Sharable;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every write holds the vehicle first, inside its transaction, and only then reads what decides
 * it: the row it changes, the span it claims, the grantee that must still exist. A read before
 * the hold is a race two requests can both win (docs/architecture.md#concurrency).
 */
class HoldOrderTest extends TestCase {
	private const OWNER = 'alice';
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const ROW = '0195e2f1-0000-4000-8000-000000000002';
	private const CLIENT_UUID = '0195e2f1-0000-4000-8000-000000000003';
	private const NOW = 1790000000;
	private const TOKEN = 1789990000;

	/** @var list<string> */
	private array $calls = [];
	private Vehicle $vehicle;
	private VehicleService&MockObject $fleet;
	private VehicleMapper&MockObject $vehicles;
	private IDBConnection&MockObject $db;
	private OdometerService&MockObject $odometer;

	protected function setUp(): void {
		$this->calls = [];
		$this->vehicle = new Vehicle();
		$this->vehicle->setId(7);
		$this->vehicle->setUuid(self::VEHICLE);
		$this->vehicle->setUserId(self::OWNER);
		$this->vehicle->setLifecycle(Vehicle::ACTIVE);
		$this->vehicle->setEnergyTypes(['diesel']);
		$this->vehicle->setJurisdiction('generic');

		$this->fleet = $this->createMock(VehicleService::class);
		$this->fleet->method('reach')->willReturn($this->vehicle);
		$this->fleet->method('change')->willReturnCallback($this->noted('change'));
		$this->vehicles = $this->createMock(VehicleMapper::class);
		$this->vehicles->method('hold')->willReturnCallback($this->noted('hold'));
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction')->willReturnCallback($this->noted('begin'));
		$this->db->method('commit')->willReturnCallback($this->noted('commit'));
		$this->db->method('rollBack')->willReturnCallback($this->noted('rollback'));
		$this->odometer = $this->createMock(OdometerService::class);
		$this->odometer->method('followEntry')->willReturnCallback($this->noted('reading'));
	}

	/**
	 * A mock's answer that records it was asked first.
	 *
	 * @param mixed $answer what the call returns, or a closure over its arguments that does
	 */
	private function noted(string $call, mixed $answer = null): \Closure {
		return function (mixed ...$args) use ($call, $answer): mixed {
			$this->calls[] = $call;

			return $answer instanceof \Closure ? $answer(...$args) : $answer;
		};
	}

	/**
	 * The calls of the `n`th transaction, from its begin to its commit.
	 *
	 * @return list<string>
	 */
	private function transaction(int $n = 1): array {
		$begins = array_keys($this->calls, 'begin', true);
		$this->assertArrayHasKey($n - 1, $begins, 'no transaction ' . $n);
		$from = $begins[$n - 1];
		$to = array_search('commit', array_slice($this->calls, $from, null, true), true);
		$this->assertIsInt($to, 'transaction ' . $n . ' never committed');

		return array_slice($this->calls, $from, $to - $from + 1);
	}

	/**
	 * A row as the mapper hands it back: the vehicle's, entered by the owner, read at TOKEN.
	 *
	 * @template E of Energy|Expense|Maintenance|Booking|Document
	 * @param E $row
	 * @return E
	 */
	private function row(Energy|Expense|Maintenance|Booking|Document $row): Energy|Expense|Maintenance|Booking|Document {
		$row->setId(11);
		$row->setUuid(self::ROW);
		$row->setVehicleId(7);
		$row->setCreatedBy(self::OWNER);
		$row->setUpdatedAt(self::TOKEN);

		return $row;
	}

	/** The row an insert writes, numbered as the database would. */
	private static function inserted(): \Closure {
		return static function (\OCA\NextFleet\Db\BaseEntity $row): \OCA\NextFleet\Db\BaseEntity {
			$row->setId(11);
			$row->setUpdatedAt(self::NOW);

			return $row;
		};
	}

	/**
	 * A mapper that records every lookup, write and client-uuid check this suite cares about.
	 *
	 * @template M of object
	 * @param class-string<M> $class
	 * @return M&MockObject
	 */
	private function rows(string $class, \OCA\NextFleet\Db\BaseEntity $found): MockObject {
		$mapper = $this->createMock($class);
		$mapper->method('findAnyByUuid')->willReturnCallback($this->noted('client uuid', static fn () => throw new DoesNotExistException('none')));
		$mapper->method('findOnVehicle')->willReturnCallback($this->noted('find', $found));
		$mapper->method('findAnyOnVehicle')->willReturnCallback($this->noted('find', $found));
		$mapper->method('insert')->willReturnCallback($this->noted('insert', self::inserted()));
		$mapper->method('updateChecked')->willReturnCallback($this->noted('update', static fn ($row) => $row));
		$mapper->method('softDelete')->willReturnCallback($this->noted('delete', static fn ($row) => $row));
		$mapper->method('restoreChecked')->willReturnCallback($this->noted('restore', static fn ($row) => $row));

		return $mapper;
	}

	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return $time;
	}

	/** @param array<string, mixed> $fields */
	private function entry(string $kind, string $action, array $fields): void {
		$this->calls = [];
		$service = match ($kind) {
			'energy' => new EnergyService($this->rows(EnergyMapper::class, $this->row(new Energy())), $this->odometer, $this->fleet, $this->vehicles, $this->createMock(Jurisdictions::class), $this->db),
			'expense' => new ExpenseService($this->rows(ExpenseMapper::class, $this->row(new Expense())), $this->fleet, $this->vehicles, $this->createMock(Jurisdictions::class), $this->db),
			'maintenance' => $this->workshop(),
		};
		match ($action) {
			'record' => $service->record(self::OWNER, self::VEHICLE, $fields + ['client_uuid' => self::CLIENT_UUID]),
			'update' => $service->update(self::OWNER, self::VEHICLE, self::ROW, self::TOKEN, $fields),
			'delete' => $service->delete(self::OWNER, self::VEHICLE, self::ROW, self::TOKEN),
			'restore' => $service->restore(self::OWNER, self::VEHICLE, self::ROW, self::TOKEN),
		};
	}

	private function workshop(): MaintenanceService {
		$record = $this->row(new Maintenance());
		$record->setTitle('Oil change');
		$reminders = $this->createMock(ReminderService::class);
		$reminders->method('closable')->willReturnCallback($this->noted('reminder'));
		$reminderRows = $this->createMock(ReminderMapper::class);
		$reminderRows->method('findAnyByIds')->willReturnCallback($this->noted('reminder', []));

		return new MaintenanceService(
			$this->rows(MaintenanceMapper::class, $record),
			$this->odometer,
			$this->fleet,
			$this->vehicles,
			$this->createMock(Jurisdictions::class),
			$reminders,
			$reminderRows,
			new AfterCommit($this->db, $this->createMock(LoggerInterface::class)),
		);
	}

	/**
	 * The three Entry kinds besides the trip, each write. What decides it - the row, the right to
	 * change it, a client uuid already taken, the reminder it closes - is read under the hold.
	 *
	 * @param list<string> $expected
	 * @param array<string, mixed> $fields
	 * @dataProvider entryWrites
	 */
	public function testAnEntryWriteHoldsTheVehicleBeforeItReads(string $kind, string $action, array $fields, array $expected): void {
		$this->entry($kind, $action, $fields);

		$this->assertSame($expected, $this->transaction());
	}

	/** @return iterable<string, array{string, string, array<string, mixed>, list<string>}> */
	public static function entryWrites(): iterable {
		$bodies = [
			'energy' => ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000],
			'expense' => ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 6400],
			'maintenance' => ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change'],
		];
		$expected = [
			'energy' => [
				'record' => ['begin', 'hold', 'client uuid', 'insert', 'reading', 'commit'],
				'update' => ['begin', 'hold', 'find', 'change', 'update', 'reading', 'commit'],
				'delete' => ['begin', 'hold', 'find', 'change', 'delete', 'reading', 'commit'],
				'restore' => ['begin', 'hold', 'find', 'change', 'restore', 'reading', 'commit'],
			],
			'expense' => [
				'record' => ['begin', 'hold', 'client uuid', 'insert', 'commit'],
				'update' => ['begin', 'hold', 'find', 'change', 'update', 'commit'],
				'delete' => ['begin', 'hold', 'find', 'change', 'delete', 'commit'],
				'restore' => ['begin', 'hold', 'find', 'change', 'restore', 'commit'],
			],
			'maintenance' => [
				'record' => ['begin', 'hold', 'client uuid', 'reminder', 'insert', 'reading', 'commit'],
				'update' => ['begin', 'hold', 'find', 'change', 'reminder', 'update', 'reading', 'commit'],
				'delete' => ['begin', 'hold', 'find', 'change', 'delete', 'reading', 'commit'],
				'restore' => ['begin', 'hold', 'find', 'change', 'restore', 'reading', 'commit'],
			],
		];
		foreach ($expected as $kind => $actions) {
			foreach ($actions as $action => $calls) {
				yield "$kind $action" => [$kind, $action, $bodies[$kind], $calls];
			}
		}
	}

	private function pool(Booking $found): BookingService {
		$bookings = $this->rows(BookingMapper::class, $found);
		$bookings->method('findLiveOverlapping')->willReturnCallback($this->noted('overlapping', []));
		$bookings->method('findOut')->willReturnCallback($this->noted('out'));
		$access = $this->createMock(VehicleAccess::class);
		$access->method('mayBooking')->willReturn(true);

		return new BookingService(
			$bookings,
			$this->createMock(TripMapper::class),
			$this->fleet,
			$this->vehicles,
			$access,
			$this->createMock(IUserManager::class),
			$this->createMock(BookingNotices::class),
			$this->clock(),
			$this->db,
		);
	}

	private function booking(string $state): Booking {
		$booking = $this->row(new Booking());
		$booking->setUserId(self::OWNER);
		$booking->setState($state);
		$booking->setStartsAt(self::NOW - 600);
		$booking->setStartsAtOff(120);
		$booking->setEndsAt(self::NOW + 3600);
		$booking->setEndsAtOff(120);

		return $booking;
	}

	/** A span is claimed under the hold, or two bookings checked at once could both find it free. */
	public function testABookingClaimsItsSpanUnderTheHold(): void {
		$span = ['starts_at' => self::NOW + 3600, 'starts_at_off' => 120, 'ends_at' => self::NOW + 7200, 'ends_at_off' => 120];

		$this->pool($this->booking(Booking::BOOKED))->book(self::OWNER, self::VEHICLE, $span + ['client_uuid' => self::CLIENT_UUID]);

		$this->assertSame(['begin', 'hold', 'client uuid', 'overlapping', 'insert', 'commit'], $this->transaction());
	}

	/**
	 * Every change of a booking finds it in its state under the hold (BookingService::inState()), so
	 * a cancel racing a check-out sees what the other left.
	 *
	 * @param list<string> $expected
	 * @dataProvider bookingChanges
	 */
	public function testABookingIsFoundInItsStateUnderTheHold(string $action, string $state, array $expected): void {
		$service = $this->pool($this->booking($state));
		$span = ['starts_at' => self::NOW + 3600, 'starts_at_off' => 120, 'ends_at' => self::NOW + 7200, 'ends_at_off' => 120];
		$handover = ['odo' => 52000, 'at_off' => 120];

		match ($action) {
			'change' => $service->change(self::OWNER, self::VEHICLE, self::ROW, self::TOKEN, $span),
			'cancel' => $service->cancel(self::OWNER, self::VEHICLE, self::ROW, self::TOKEN),
			'check out' => $service->checkOut(self::OWNER, self::VEHICLE, self::ROW, $handover),
			'check in' => $service->checkIn(self::OWNER, self::VEHICLE, self::ROW, $handover),
		};

		$this->assertSame($expected, $this->transaction());
	}

	/** @return iterable<string, array{string, string, list<string>}> */
	public static function bookingChanges(): iterable {
		yield 'change' => ['change', Booking::BOOKED, ['begin', 'hold', 'find', 'overlapping', 'update', 'commit']];
		yield 'cancel' => ['cancel', Booking::BOOKED, ['begin', 'hold', 'find', 'update', 'commit']];
		yield 'check out' => ['check out', Booking::BOOKED, ['begin', 'hold', 'find', 'out', 'update', 'commit']];
		yield 'check in' => ['check in', Booking::OUT, ['begin', 'hold', 'find', 'update', 'commit']];
	}

	private function papers(Document $found): DocumentService {
		$documents = $this->rows(DocumentMapper::class, $found);
		$documents->method('findByVehicle')->willReturnCallback($this->noted('papers', []));
		$access = $this->createMock(VehicleAccess::class);
		$access->method('mayChange')->willReturn(true);

		return new DocumentService(
			$documents,
			$this->fleet,
			$this->vehicles,
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$this->createMock(ExpenseMapper::class),
			$this->createMock(BookingMapper::class),
			$access,
			$this->createMock(OwnFiles::class),
			$this->db,
		);
	}

	private function paper(?int $deletedAt): Document {
		$paper = $this->row(new Document());
		$paper->setFileId(1);
		$paper->setKind('receipt');
		$paper->setDeletedAt($deletedAt);

		return $paper;
	}

	/**
	 * A paper is put on, taken off or brought back under the hold, after the list it joins is read
	 * there: one file attached twice at once is one paper.
	 *
	 * @param list<string> $expected
	 * @dataProvider paperWrites
	 */
	public function testAPaperIsWrittenUnderTheHold(string $action, array $expected): void {
		match ($action) {
			'attach' => $this->papers($this->paper(null))->attach(self::OWNER, self::VEHICLE, ['file_id' => 1, 'kind' => 'receipt', 'client_uuid' => self::CLIENT_UUID]),
			'detach' => $this->papers($this->paper(null))->detach(self::OWNER, self::VEHICLE, self::ROW),
			'restore' => $this->papers($this->paper(self::TOKEN))->restore(self::OWNER, self::VEHICLE, self::ROW),
		};

		$this->assertSame($expected, $this->transaction());
	}

	/** @return iterable<string, array{string, list<string>}> */
	public static function paperWrites(): iterable {
		yield 'attach' => ['attach', ['begin', 'hold', 'client uuid', 'papers', 'insert', 'commit']];
		yield 'detach' => ['detach', ['begin', 'hold', 'find', 'delete', 'commit']];
		yield 'restore' => ['restore', ['begin', 'hold', 'find', 'papers', 'restore', 'commit']];
	}

	/**
	 * A new grant asks whether its grantee still exists under the hold, after the grant committed
	 * (GrantService::took()): asked before, an erasure could finish in between and the grant would
	 * wait for whoever takes the name next.
	 *
	 * @dataProvider grantees
	 */
	public function testANewGrantAsksAfterItsGranteeUnderTheHold(bool $exists, array $expected): void {
		$grants = $this->createMock(AccessMapper::class);
		$grants->method('findAnyByUuid')->willThrowException(new DoesNotExistException('none'));
		$grants->method('findByVehicle')->willReturnCallback($this->noted('grants', []));
		$grants->method('insert')->willReturnCallback($this->noted('insert', self::inserted()));
		$grants->method('discard')->willReturnCallback($this->noted('discard'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$users->method('userExists')->willReturnCallback($this->noted('exists', $exists));
		$sharable = $this->createMock(Sharable::class);
		$sharable->method('reaches')->willReturn(true);
		$this->vehicles->method('findAnyById')->willReturnCallback($this->noted('vehicle', $this->vehicle));
		$recipients = $this->createMock(ReminderRecipientMapper::class);
		$recipients->method('findByVehicle')->willReturnCallback($this->noted('recipients', []));
		$service = new GrantService(
			$grants,
			$this->fleet,
			$this->createMock(VehicleAccess::class),
			$this->vehicles,
			$recipients,
			$users,
			$this->createMock(IGroupManager::class),
			$sharable,
			$this->createMock(GrantNotices::class),
			$this->createMock(NotificationService::class),
			$this->db,
			$this->createMock(Pending::class),
			$this->createMock(LoggerInterface::class),
		);

		try {
			$service->grant(self::OWNER, self::VEHICLE, ['grantee' => 'bob', 'grantee_type' => Access::USER, 'role' => 'driver']);
		} catch (\InvalidArgumentException) {
			// The vanished grantee's refusal, once the row is gone again.
		}

		$this->assertSame(['begin', 'hold', 'grants', 'insert', 'commit'], $this->transaction(1));
		$this->assertSame($expected, $this->transaction(2));
	}

	/** @return iterable<string, array{bool, list<string>}> */
	public static function grantees(): iterable {
		yield 'still there' => [true, ['begin', 'hold', 'exists', 'commit']];
		yield 'deleted meanwhile' => [false, ['begin', 'hold', 'exists', 'vehicle', 'recipients', 'discard', 'commit']];
	}
}
