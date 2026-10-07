<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\BookingConflictException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Booking against the real database: what a booking may span, and that a second one over it is
 * refused (CONTEXT.md, Pool).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class BookingTest extends TestCase {
	use CountsQueries;

	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const MANAGER = 'nextfleet-test-dave';
	private const DRIVER = 'nextfleet-test-erin';
	private const VIEWER = 'nextfleet-test-frank';
	private const HOUR = 3600;
	/** The least a handover states. */
	private const HANDOVER = ['odo' => 52000, 'at_off' => 120];

	private BookingService $bookings;
	private TripService $trips;
	private VehicleService $vehicles;
	private Vehicle $vehicle;
	/** The next full hour a day from now: always to come while a case runs. */
	private int $tomorrow;

	protected function setUp(): void {
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forgetTestRows();
		$this->vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant(self::MANAGER, 'manager');
		$this->grant(self::DRIVER, 'driver');
		$now = time();
		$this->tomorrow = $now + 86400 + self::HOUR - $now % self::HOUR;
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::DRIVER, self::VIEWER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_bookings' => 'created_by', 'fleet_odo_readings' => 'created_by', 'fleet_trips' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * @param array<string, mixed> $more
	 * @return array<string, mixed>
	 */
	private function span(int $from, int $hours, array $more = []): array {
		return ['starts_at' => $from, 'starts_at_off' => 120, 'ends_at' => $from + $hours * self::HOUR, 'ends_at_off' => 120] + $more;
	}

	public function testADriverBooksAndTheVehicleListsIt(): void {
		$written = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3, ['purpose' => 'Customer visit']));

		$this->assertSame(Booking::BOOKED, $written['state']);
		$this->assertSame(self::DRIVER, $written['user_id']);
		$this->assertSame(self::DRIVER, $written['user_name']);
		$this->assertSame($this->tomorrow, $written['starts_at']);
		$this->assertSame(120, $written['starts_at_off']);
		$this->assertSame($this->tomorrow + 3 * self::HOUR, $written['ends_at']);
		$this->assertSame('Customer visit', $written['purpose']);
		$this->assertNull($written['out_odo']);
		$this->assertNull($written['trip_uuid']);
		$this->assertSame(['edit', 'cancel', 'check_out'], $written['may']);
		$this->assertSame([$written['uuid']], array_column($this->bookings->list(self::OWNER, $this->vehicle->getUuid(), []), 'uuid'));
	}

	/** The refusal names who has it and when, so the sheet can say "Booked by Erin, …". */
	public function testABookingOverAnotherIsRefusedWithWhoAndWhen(): void {
		$held = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		try {
			$this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 2 * self::HOUR, 2));
			$this->fail('a booking over a live one was taken');
		} catch (BookingConflictException $e) {
			$this->assertSame([
				'uuid' => $held['uuid'],
				'user_id' => self::DRIVER,
				'user_name' => self::DRIVER,
				'starts_at' => $this->tomorrow,
				'starts_at_off' => 120,
				'ends_at' => $this->tomorrow + 3 * self::HOUR,
				'ends_at_off' => 120,
				'state' => 'booked',
			], $e->booking);
		}
		$this->assertCount(1, $this->bookings->list(self::OWNER, $this->vehicle->getUuid(), []));
	}

	/** Half-open: one ending at noon and the next starting at noon do not collide. */
	public function testBookingsEndToEndDoNotCollide(): void {
		$this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 3 * self::HOUR, 2));
		$this->bookings->book(self::MANAGER, $this->vehicle->getUuid(), $this->span($this->tomorrow - 2 * self::HOUR, 2));

		$this->assertCount(3, $this->bookings->list(self::OWNER, $this->vehicle->getUuid(), []));
	}

	/** @return array<string, array{int, int, string}> start and end, in hours from tomorrow */
	public static function badSpans(): array {
		return [
			'ending before it starts' => [3, 1, 'ends_at is after starts_at'],
			'ending as it starts' => [3, 3, 'ends_at is after starts_at'],
			'over before now' => [-50, -48, 'ends_at is still to come'],
			'longer than 90 days' => [0, 90 * 24 + 1, 'a booking spans 90 days at most'],
			'ending over a year ahead' => [365 * 24 - 2, 365 * 24, 'ends_at is a year ahead at most'],
			'starting an hour ago' => [-26, -23, 'starts_at is in the past'],
		];
	}

	/** The bounds take what they name: 90 days, a year ahead, a start a few minutes gone. */
	public function testASpanAtItsBoundsIsTaken(): void {
		$uuid = $this->vehicle->getUuid();
		$this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 90 * 24));
		$this->bookings->book(self::DRIVER, $uuid, $this->span(time() + 364 * 86400, 23));
		$written = $this->bookings->book(self::OWNER, $uuid, $this->span(time() - 240, 1));

		$this->assertSame(Booking::BOOKED, $written['state']);
	}

	/** Just past each bound is refused: the slack is five minutes, the horizon a year. */
	public function testJustPastTheBoundsIsRefused(): void {
		$uuid = $this->vehicle->getUuid();
		foreach ([
			'starts_at is in the past' => $this->span(time() - 360, 1),
			'ends_at is a year ahead at most' => $this->span(time() + 365 * 86400 - self::HOUR + 60, 1),
		] as $message => $span) {
			try {
				$this->bookings->book(self::DRIVER, $uuid, $span);
				$this->fail('booked: ' . $message);
			} catch (\InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}

	/** A booking made before the bounds keeps its span, and its purpose still changes. */
	public function testABookingPastTheBoundsChangesWithoutMovingItsSpan(): void {
		$uuid = $this->vehicle->getUuid();
		$long = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$mapper = \OCP\Server::get(BookingMapper::class);
		$row = $mapper->findOnVehicle((int)$this->vehicle->getId(), $long['uuid']);
		$row->setEndsAt(time() + 400 * 86400);
		$updatedAt = $mapper->updateChecked($row, $row->getUpdatedAt())->getUpdatedAt();
		$span = ['starts_at' => $row->getStartsAt(), 'starts_at_off' => 120, 'ends_at' => $row->getEndsAt(), 'ends_at_off' => 120];

		$changed = $this->bookings->change(self::DRIVER, $uuid, $long['uuid'], $updatedAt, $span + ['purpose' => 'Season']);
		$this->assertSame('Season', $changed['purpose']);

		$this->expectExceptionObject(new \InvalidArgumentException('a booking spans 90 days at most'));
		$this->bookings->change(self::DRIVER, $uuid, $long['uuid'], $changed['updated_at'], ['ends_at' => $row->getEndsAt() - 60] + $span);
	}

	/** Someone running late keeps the start they booked: only a new start may not lie behind. */
	public function testABookingWhoseStartWentByChangesWithoutMovingItBack(): void {
		$uuid = $this->vehicle->getUuid();
		$late = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->pushBack($late['uuid'], self::HOUR);
		$late = $this->listed(self::DRIVER)[$late['uuid']];

		$changed = $this->bookings->change(self::DRIVER, $uuid, $late['uuid'], $late['updated_at'], $this->span($late['starts_at'], 4, ['purpose' => 'Airport']));
		$this->assertSame('Airport', $changed['purpose']);

		$this->expectExceptionObject(new \InvalidArgumentException('starts_at is in the past'));
		$this->bookings->change(self::DRIVER, $uuid, $late['uuid'], $changed['updated_at'], $this->span($late['starts_at'] - self::HOUR, 5));
	}

	#[DataProvider('badSpans')]
	public function testASpanThatIsNoPlanIsRefused(int $from, int $to, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), [
			'starts_at' => $this->tomorrow + $from * self::HOUR, 'starts_at_off' => 120,
			'ends_at' => $this->tomorrow + $to * self::HOUR, 'ends_at_off' => 120,
		]);
	}

	/** A laid-up or sold car is not in the pool. */
	public function testOnlyAnActiveVehicleTakesABooking(): void {
		$this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $this->vehicle->getUpdatedAt(), ['lifecycle' => Vehicle::LAID_UP]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('in service');
		$this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
	}

	/** Booking takes `log`: a viewer reads the list and books nothing. */
	public function testAViewerSeesTheBookingsAndBooksNone(): void {
		$this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->grant(self::VIEWER, 'viewer');

		$this->assertCount(1, $this->bookings->list(self::VIEWER, $this->vehicle->getUuid(), []));
		$this->expectException(AccessDeniedException::class);
		$this->bookings->book(self::VIEWER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 5 * self::HOUR, 1));
	}

	/** Seven days back by default; `from` and `to` narrow it. */
	public function testTheListReachesAWeekBackUnlessToldOtherwise(): void {
		$soon = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 1));
		$later = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 48 * self::HOUR, 1));
		$uuid = $this->vehicle->getUuid();

		$this->assertSame([$soon['uuid'], $later['uuid']], array_column($this->bookings->list(self::OWNER, $uuid, []), 'uuid'));
		$this->assertSame([$later['uuid']], array_column($this->bookings->list(self::OWNER, $uuid, ['from' => $this->tomorrow + self::HOUR]), 'uuid'));
		$this->assertSame([$soon['uuid']], array_column($this->bookings->list(self::OWNER, $uuid, ['to' => $this->tomorrow + self::HOUR]), 'uuid'));
	}

	/**
	 * A car still out and a return still waiting for its trip are listed whatever the window: the
	 * one has to come back, the other to be logged. A return with its trip is history.
	 */
	public function testACarOutLongPastItsEndStaysListedAndComesBack(): void {
		$uuid = $this->vehicle->getUuid();
		$logged = $this->returned(self::OWNER);
		$this->trips->record(self::OWNER, $uuid, $logged['trip_draft'] + ['category' => 'private', 'booking_uuid' => $logged['uuid']]);
		$this->pushBack($logged['uuid'], 10 * 86400);
		$untripped = $this->returned(self::OWNER);
		$this->pushBack($untripped['uuid'], 9 * 86400);
		$out = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->bookings->checkOut(self::DRIVER, $uuid, $out['uuid'], ['odo' => 52140, 'at_off' => 120]);
		$this->pushBack($out['uuid'], 8 * 86400);

		$this->assertSame([$untripped['uuid'], $out['uuid']], array_column($this->bookings->list(self::OWNER, $uuid, []), 'uuid'));
		$this->assertSame([$untripped['uuid'], $out['uuid']], array_column($this->bookings->list(self::OWNER, $uuid, ['from' => $this->tomorrow, 'to' => $this->tomorrow + self::HOUR]), 'uuid'));
		$this->assertSame(['check_in', 'attach'], $this->listed(self::DRIVER)[$out['uuid']]['may']);
		$in = $this->bookings->checkIn(self::DRIVER, $uuid, $out['uuid'], ['odo' => 52300, 'at_off' => 120]);
		$this->assertSame(Booking::RETURNED, $in['state']);
		$this->assertSame(['late'], $in['flags']);
	}

	/** A change may overlap the booking's own old span, never another's. */
	public function testTheBookerChangesTheSpanAndThePurpose(): void {
		$uuid = $this->vehicle->getUuid();
		$mine = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$theirs = $this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow + 5 * self::HOUR, 2));

		$changed = $this->bookings->change(self::DRIVER, $uuid, $mine['uuid'], $mine['updated_at'], $this->span($this->tomorrow + self::HOUR, 3, ['purpose' => 'Airport']));

		$this->assertSame($this->tomorrow + self::HOUR, $changed['starts_at']);
		$this->assertSame('Airport', $changed['purpose']);
		$this->assertGreaterThan($mine['updated_at'], $changed['updated_at']);
		try {
			$this->bookings->change(self::DRIVER, $uuid, $mine['uuid'], $changed['updated_at'], $this->span($this->tomorrow + 3 * self::HOUR, 3));
			$this->fail('a change over another booking was taken');
		} catch (BookingConflictException $e) {
			$this->assertSame($theirs['uuid'], $e->booking['uuid']);
		}
		$this->expectException(StaleUpdateException::class);
		$this->bookings->change(self::DRIVER, $uuid, $mine['uuid'], $mine['updated_at'], $this->span($this->tomorrow, 2));
	}

	/** A stale client hears that it is stale, not about a span it never saw taken. */
	public function testAStaleChangeIsStaleBeforeItIsAConflict(): void {
		$uuid = $this->vehicle->getUuid();
		$mine = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow + 5 * self::HOUR, 2));
		$this->bookings->change(self::DRIVER, $uuid, $mine['uuid'], $mine['updated_at'], $this->span($this->tomorrow, 2));

		$this->expectException(StaleUpdateException::class);
		$this->bookings->change(self::DRIVER, $uuid, $mine['uuid'], $mine['updated_at'], $this->span($this->tomorrow + 4 * self::HOUR, 2));
	}

	/** A laid-up car keeps its bookings, offers no change, and may still be cancelled. */
	public function testALaidUpVehicleOffersNoChangeButACancel(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$this->vehicles->update(self::OWNER, $uuid, $this->vehicle->getUpdatedAt(), ['lifecycle' => Vehicle::LAID_UP]);

		$may = $this->listed(self::DRIVER)[$booking['uuid']]['may'];
		$this->assertNotContains('edit', $may);
		$this->assertNotContains('check_out', $may);
		try {
			$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);
			$this->fail('a laid-up car was taken');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('in service', $e->getMessage());
		}
		$this->assertContains('cancel', $may);
		$this->assertSame(Booking::CANCELLED, $this->bookings->cancel(self::DRIVER, $uuid, $booking['uuid'], $booking['updated_at'])['state']);
	}

	/** `log` changes your own; somebody else's takes `edit`. */
	public function testADriverChangesOnlyTheirOwnBooking(): void {
		$uuid = $this->vehicle->getUuid();
		$owners = $this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow, 3));
		$drivers = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 5 * self::HOUR, 3));

		$this->assertSame([], $this->listed(self::DRIVER)[$owners['uuid']]['may']);
		$this->assertSame(['edit', 'cancel', 'check_out'], $this->listed(self::MANAGER)[$drivers['uuid']]['may']);
		$this->bookings->change(self::MANAGER, $uuid, $drivers['uuid'], $drivers['updated_at'], $this->span($this->tomorrow + 6 * self::HOUR, 3));
		$this->expectException(AccessDeniedException::class);
		$this->bookings->change(self::DRIVER, $uuid, $owners['uuid'], $owners['updated_at'], $this->span($this->tomorrow + self::HOUR, 1));
	}

	/** Once the car is out, the span is history in the making; only check-in moves it on. */
	public function testABookingThatIsOutNoLongerChanges(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$token = $this->moveTo($booking['uuid'], Booking::OUT);

		$this->assertSame(['check_in', 'attach'], $this->listed(self::DRIVER)[$booking['uuid']]['may']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('only a booking not yet taken');
		$this->bookings->change(self::DRIVER, $uuid, $booking['uuid'], $token, $this->span($this->tomorrow, 4));
	}

	/** The row stays, says so, and frees the span. */
	public function testACancelledBookingStaysListedAndHoldsNothing(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));

		$cancelled = $this->bookings->cancel(self::DRIVER, $uuid, $booking['uuid'], $booking['updated_at']);

		$this->assertSame(Booking::CANCELLED, $cancelled['state']);
		$this->assertSame([], $cancelled['may']);
		$this->assertSame(Booking::CANCELLED, $this->listed(self::OWNER)[$booking['uuid']]['state']);
		$this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow, 3));
		$this->expectException(\InvalidArgumentException::class);
		$this->bookings->cancel(self::DRIVER, $uuid, $booking['uuid'], $cancelled['updated_at']);
	}

	/** A driver cancels their own; a manager anybody's. */
	public function testADriverCancelsOnlyTheirOwnBooking(): void {
		$uuid = $this->vehicle->getUuid();
		$owners = $this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow, 3));
		$drivers = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 5 * self::HOUR, 3));

		$this->assertSame(Booking::CANCELLED, $this->bookings->cancel(self::MANAGER, $uuid, $drivers['uuid'], $drivers['updated_at'])['state']);
		$this->expectException(AccessDeniedException::class);
		$this->bookings->cancel(self::DRIVER, $uuid, $owners['uuid'], $owners['updated_at']);
	}

	/** Taking the car: the moment is the server's, the offset the client's. */
	public function testTheBookerChecksOutWithTheCounterTheLevelAndANote(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$before = time();

		$out = $this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], ['odo' => 52000, 'level' => 80, 'notes' => 'Scratch on the left door', 'at_off' => 60]);

		$this->assertSame(Booking::OUT, $out['state']);
		$this->assertGreaterThanOrEqual($before, $out['out_at']);
		$this->assertLessThanOrEqual(time(), $out['out_at']);
		$this->assertSame(60, $out['out_at_off']);
		$this->assertSame(52000, $out['out_odo']);
		$this->assertSame(80, $out['out_level']);
		$this->assertSame('Scratch on the left door', $out['out_notes']);
		$this->assertSame(['check_in', 'attach'], $out['may']);
		$this->assertSame([], $out['flags']);
		$this->assertSame(Booking::OUT, $this->listed(self::OWNER)[$booking['uuid']]['state']);
	}

	/** Early is allowed; while the car is still with somebody else it is not - "still with Erin". */
	public function testNobodyChecksOutWhileTheCarIsOutWithSomebodyElse(): void {
		$uuid = $this->vehicle->getUuid();
		$drivers = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$owners = $this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow() + 3 * self::HOUR, 2));
		$this->bookings->checkOut(self::DRIVER, $uuid, $drivers['uuid'], self::HANDOVER);

		try {
			$this->bookings->checkOut(self::OWNER, $uuid, $owners['uuid'], self::HANDOVER);
			$this->fail('the car was taken while it was out');
		} catch (BookingConflictException $e) {
			$this->assertSame($drivers['uuid'], $e->booking['uuid']);
			$this->assertSame(self::DRIVER, $e->booking['user_name']);
		}
		$this->assertSame(Booking::BOOKED, $this->listed(self::OWNER)[$owners['uuid']]['state']);
	}

	/** Giving it back answers what the entry sheet needs to log the trip, and logs none itself. */
	public function testCheckingInAnswersTheTripToLog(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3, ['purpose' => 'Customer visit']));
		$out = $this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], ['odo' => 52000, 'level' => 80, 'at_off' => 120]);

		$in = $this->bookings->checkIn(self::DRIVER, $uuid, $booking['uuid'], ['odo' => 52140, 'level' => 45, 'notes' => 'Washed', 'at_off' => 60]);

		$this->assertSame(Booking::RETURNED, $in['state']);
		$this->assertGreaterThanOrEqual($out['out_at'], $in['in_at']);
		$this->assertSame(60, $in['in_at_off']);
		$this->assertSame(52140, $in['in_odo']);
		$this->assertSame(45, $in['in_level']);
		$this->assertSame('Washed', $in['in_notes']);
		$this->assertSame(['log_trip', 'attach'], $in['may']);
		$this->assertSame([
			'started_at' => $out['out_at'],
			'started_at_off' => 120,
			'ended_at' => $in['in_at'],
			'ended_at_off' => 60,
			'start_odo' => 52000,
			'end_odo' => 52140,
			'purpose' => 'Customer visit',
		], $in['trip_draft']);
		$this->assertNull($in['trip_uuid']);
		$this->assertSame([], \OCP\Server::get(OdometerService::class)->list(self::DRIVER, $uuid));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('only a booking whose car is out');
		$this->bookings->checkIn(self::DRIVER, $uuid, $booking['uuid'], ['odo' => 52140, 'at_off' => 60]);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function badHandovers(): array {
		return [
			'no counter' => [['at_off' => 120], 'odo is required'],
			'no offset' => [['odo' => 52000], 'at_off is required'],
			'a negative counter' => [['odo' => -1, 'at_off' => 120], 'odo is a whole number'],
			'a level over 100' => [['odo' => 52000, 'level' => 101, 'at_off' => 120], 'level is 100 at most'],
			'a counter over a billion' => [['odo' => 1_000_000_001, 'at_off' => 120], 'odo is 1000000000 at most'],
			'a note over 10 000 characters' => [['odo' => 52000, 'at_off' => 120, 'notes' => str_repeat('x', 10_001)], 'notes is longer than 10000 characters'],
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	#[DataProvider('badHandovers')]
	public function testAHandoverStatesTheCounterAndALevelThatIsAPercentage(array $fields, string $message): void {
		$booking = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->justNow(), 3));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->bookings->checkOut(self::DRIVER, $this->vehicle->getUuid(), $booking['uuid'], $fields);
	}

	/** Past its end a booking is not taken any more; the screen stops offering it as well. */
	public function testABookingThatIsOverIsNotCheckedOut(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->endBefore($booking['uuid'], time() - 60);

		$this->assertNotContains('check_out', $this->listed(self::DRIVER)[$booking['uuid']]['may']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('the booking is over');
		$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);
	}

	/** `odo_below`, as BookingService::flags() reads it; a Reading after check-out flags nothing. */
	public function testACounterThatRunsBackwardsIsFlaggedNotRefused(): void {
		$uuid = $this->vehicle->getUuid();
		$odometer = \OCP\Server::get(OdometerService::class);
		$odometer->record(self::OWNER, $uuid, ['read_at' => $this->justNow() - 2 * self::HOUR, 'read_at_off' => 120, 'value' => 52000]);
		$below = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->assertSame(['odo_below'], $this->bookings->checkOut(self::DRIVER, $uuid, $below['uuid'], ['odo' => 51900, 'at_off' => 120])['flags']);
		$this->assertSame(['odo_below'], $this->listed(self::OWNER)[$below['uuid']]['flags']);
		$this->bookings->checkIn(self::DRIVER, $uuid, $below['uuid'], ['odo' => 52100, 'at_off' => 120]);

		$backwards = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow() + 3 * self::HOUR, 1));
		$this->bookings->checkOut(self::DRIVER, $uuid, $backwards['uuid'], ['odo' => 52100, 'at_off' => 120]);
		$odometer->record(self::OWNER, $uuid, ['read_at' => time() + 60, 'read_at_off' => 120, 'value' => 52500]);
		$this->assertSame([], $this->listed(self::OWNER)[$backwards['uuid']]['flags']);
		$this->assertSame(['odo_below'], $this->bookings->checkIn(self::DRIVER, $uuid, $backwards['uuid'], ['odo' => 52050, 'at_off' => 120])['flags']);
	}

	/** The counter it came back at counts too, before anyone logs that trip: taken below it is flagged. */
	public function testACheckOutBelowTheLastReturnIsFlagged(): void {
		$uuid = $this->vehicle->getUuid();
		$this->returned(self::DRIVER);
		$below = $this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow(), 3));

		$this->assertSame(['odo_below'], $this->bookings->checkOut(self::OWNER, $uuid, $below['uuid'], ['odo' => 52100, 'at_off' => 120])['flags']);
		$this->assertSame(['odo_below'], $this->bookings->checkIn(self::OWNER, $uuid, $below['uuid'], ['odo' => 52200, 'at_off' => 120])['flags']);
		$next = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->assertSame([], $this->bookings->checkOut(self::DRIVER, $uuid, $next['uuid'], ['odo' => 52200, 'at_off' => 120])['flags']);
	}

	/** Once its trip is logged the trip is the record, so a slip in the check-in counter flags nobody after. */
	public function testALoggedTripOverridesTheCounterTheCarCameBackAt(): void {
		$uuid = $this->vehicle->getUuid();
		$slipped = $this->returned(self::DRIVER);
		$this->trips->record(self::DRIVER, $uuid, ['end_odo' => 52100, 'category' => 'private', 'booking_uuid' => $slipped['uuid']] + $slipped['trip_draft']);
		$next = $this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow(), 3));

		$this->assertSame([], $this->bookings->checkOut(self::OWNER, $uuid, $next['uuid'], ['odo' => 52110, 'at_off' => 120])['flags']);
	}

	/** A car laid up while it is out still comes back. */
	public function testACarLaidUpWhileOutIsStillCheckedIn(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);
		$this->vehicles->update(self::OWNER, $uuid, $this->vehicle->getUpdatedAt(), ['lifecycle' => Vehicle::LAID_UP]);

		$this->assertSame(['check_in', 'attach'], $this->listed(self::DRIVER)[$booking['uuid']]['may']);
		$this->assertSame(Booking::RETURNED, $this->bookings->checkIn(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER)['state']);
	}

	/** Early takes the hours before the start too, so not while another booking holds them. */
	public function testAnEarlyCheckOutDoesNotTakeAnotherBookingsHours(): void {
		$uuid = $this->vehicle->getUuid();
		$erins = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 2));
		$alices = $this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow() + 2 * self::HOUR, 2));

		try {
			$this->bookings->checkOut(self::OWNER, $uuid, $alices['uuid'], self::HANDOVER);
			$this->fail('the car was taken in somebody else\'s hours');
		} catch (BookingConflictException $e) {
			$this->assertSame($erins['uuid'], $e->booking['uuid']);
		}
		$this->bookings->cancel(self::DRIVER, $uuid, $erins['uuid'], $erins['updated_at']);
		$this->assertSame(Booking::OUT, $this->bookings->checkOut(self::OWNER, $uuid, $alices['uuid'], self::HANDOVER)['state']);
	}

	/**
	 * The flags of a page are read in one go: a list of many handovers costs what a list of two
	 * does. Two, so one has a return before it to look up, as each of the many does.
	 */
	public function testTheFlagsCostTheSameQueriesForFewRowsAsForMany(): void {
		$this->returned(self::DRIVER);
		$this->returned(self::DRIVER);
		$two = self::queriesOf(fn () => $this->bookings->list(self::OWNER, $this->vehicle->getUuid(), []));
		for ($i = 0; $i < 3; $i++) {
			$this->returned(self::DRIVER);
		}

		$five = self::queriesOf(fn () => $this->bookings->list(self::OWNER, $this->vehicle->getUuid(), []));

		$this->assertSame($two, $five);
	}

	/** Taken early, the car is gone from the moment it was taken: nobody books the hours before the start. */
	public function testNobodyBooksTheHoursAnEarlyCheckOutTook(): void {
		$uuid = $this->vehicle->getUuid();
		$alices = $this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow() + 3 * self::HOUR, 2));
		$this->bookings->checkOut(self::OWNER, $uuid, $alices['uuid'], self::HANDOVER);

		try {
			$this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow() + self::HOUR, 1));
			$this->fail('a booking was taken in the hours an early check-out holds');
		} catch (BookingConflictException $e) {
			$this->assertSame($alices['uuid'], $e->booking['uuid']);
			$this->assertSame(Booking::OUT, $e->booking['state']);
		}
		$this->assertSame(Booking::BOOKED, $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow() + 5 * self::HOUR, 1))['state']);
	}

	/** Overdue, the car is still gone: nobody books from now until it is back, and later stays bookable. */
	public function testAnOverdueCarRefusesABookingFromNow(): void {
		$uuid = $this->vehicle->getUuid();
		$erins = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->bookings->checkOut(self::DRIVER, $uuid, $erins['uuid'], self::HANDOVER);
		$this->pushBack($erins['uuid'], 86400);

		try {
			// A minute back: at a minute's first second justNow() is the clock itself, where the
			// half-open hold already ends (BookingMapperTest).
			$this->bookings->book(self::OWNER, $uuid, $this->span($this->justNow() - 60, 2));
			$this->fail('a booking was taken while the car is overdue');
		} catch (BookingConflictException $e) {
			$this->assertSame($erins['uuid'], $e->booking['uuid']);
			$this->assertSame(Booking::OUT, $e->booking['state']);
		}
		$this->assertSame(Booking::BOOKED, $this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow, 1))['state']);
	}

	/** Given back after its end: flagged, never refused. An `out` past its end is overdue, not late. */
	public function testACheckInAfterTheEndIsLate(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);
		$this->endBefore($booking['uuid'], time() - 60);
		$this->assertSame([], $this->listed(self::OWNER)[$booking['uuid']]['flags']);

		$in = $this->bookings->checkIn(self::DRIVER, $uuid, $booking['uuid'], ['odo' => 52100, 'at_off' => 120]);

		$this->assertSame(Booking::RETURNED, $in['state']);
		$this->assertSame(['late'], $in['flags']);
		$this->assertSame(['late'], $this->listed(self::OWNER)[$booking['uuid']]['flags']);
	}

	/** The trip the check-in prompted, logged from the booking and tied to it. */
	public function testAReturnedBookingBecomesTheTripLoggedFromIt(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->returned(self::DRIVER, 'Customer visit');
		$listed = $this->listed(self::DRIVER)[$booking['uuid']];
		$this->assertSame(['log_trip', 'attach'], $listed['may']);
		$this->assertSame($booking['trip_draft'], $listed['trip_draft']);

		$trip = $this->trips->record(self::DRIVER, $uuid, $booking['trip_draft'] + ['category' => 'business', 'booking_uuid' => $booking['uuid']]);

		$listed = $this->listed(self::DRIVER)[$booking['uuid']];
		$this->assertSame($trip->getUuid(), $listed['trip_uuid']);
		$this->assertFalse($listed['trip_voided']);
		$this->assertSame(['attach', 'open_trip'], $listed['may']);
		$this->assertNull($listed['trip_draft']);
	}

	/** Refused 409, and the trip goes with the refusal: a car not back yet, or a second trip. */
	public function testOnlyAReturnedBookingWithoutATripBecomesOne(): void {
		$uuid = $this->vehicle->getUuid();
		$booked = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 3));
		$returned = $this->returned(self::DRIVER);
		$this->trips->record(self::DRIVER, $uuid, $returned['trip_draft'] + ['category' => 'private', 'booking_uuid' => $returned['uuid']]);

		foreach ([$booked['uuid'] => Booking::BOOKED, $returned['uuid'] => Booking::RETURNED] as $bookingUuid => $state) {
			try {
				$this->trips->record(self::DRIVER, $uuid, $returned['trip_draft'] + ['category' => 'private', 'booking_uuid' => $bookingUuid]);
				$this->fail('a trip was tied to a ' . $state . ' booking');
			} catch (BookingConflictException $e) {
				$this->assertSame($bookingUuid, $e->booking['uuid']);
				$this->assertSame($state, $e->booking['state']);
			}
		}
		$this->assertCount(1, \OCP\Server::get(TripMapper::class)->findAllForVehicle((int)$this->vehicle->getId()));
	}

	/** The booking rule: the booker logs their own; a manager anybody's; a booking on another car is none. */
	public function testADriverLogsOnlyTheirOwnBookingsTrip(): void {
		$uuid = $this->vehicle->getUuid();
		$owners = $this->returned(self::OWNER);
		$this->assertSame([], $this->listed(self::DRIVER)[$owners['uuid']]['may']);

		try {
			$this->trips->record(self::DRIVER, $uuid, $owners['trip_draft'] + ['category' => 'private', 'booking_uuid' => $owners['uuid']]);
			$this->fail('a driver logged somebody else\'s booking');
		} catch (AccessDeniedException) {
		}
		$trip = $this->trips->record(self::MANAGER, $uuid, $owners['trip_draft'] + ['category' => 'private', 'booking_uuid' => $owners['uuid']]);
		$this->assertSame($trip->getUuid(), $this->listed(self::OWNER)[$owners['uuid']]['trip_uuid']);
		// The trip opens by its own rule, as its timeline row does: the manager's, not the driver's.
		$this->assertSame(['attach', 'open_trip'], $this->listed(self::OWNER)[$owners['uuid']]['may']);
		$this->assertSame([], $this->listed(self::DRIVER)[$owners['uuid']]['may']);

		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		$this->expectException(DoesNotExistException::class);
		$this->trips->record(self::OWNER, $other->getUuid(), $owners['trip_draft'] + ['category' => 'private', 'booking_uuid' => $owners['uuid']]);
	}

	/** A voided trip stays tied, and the row says so; voiding is no way to log the booking twice. */
	public function testAVoidedTripStaysTiedToItsBooking(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->returned(self::DRIVER);
		$trip = $this->trips->record(self::DRIVER, $uuid, $booking['trip_draft'] + ['category' => 'private', 'booking_uuid' => $booking['uuid']]);

		$this->trips->delete(self::DRIVER, $uuid, $trip->getUuid(), $trip->getUpdatedAt());

		$listed = $this->listed(self::DRIVER)[$booking['uuid']];
		$this->assertSame($trip->getUuid(), $listed['trip_uuid']);
		$this->assertTrue($listed['trip_voided']);
		$this->assertSame(['attach'], $listed['may']);
	}

	/** Who has the car, as the overview row says it: the out booking's booker and its end, for anyone who sees it. */
	public function testTheFleetListSaysWhoHasTheCar(): void {
		$uuid = $this->vehicle->getUuid();
		$this->assertNull($this->fleetRow(self::OWNER)['out_with']);
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->assertNull($this->fleetRow(self::OWNER)['out_with']);

		$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);

		$this->grant(self::VIEWER, 'viewer');
		$with = ['user_id' => self::DRIVER, 'user_name' => self::DRIVER, 'ends_at' => $this->justNow() + 3 * self::HOUR, 'ends_at_off' => 120];
		$this->assertSame($with, $this->fleetRow(self::OWNER)['out_with']);
		$this->assertSame($with, $this->fleetRow(self::VIEWER)['out_with']);
		$this->bookings->checkIn(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);
		$this->assertNull($this->fleetRow(self::OWNER)['out_with']);
	}

	/** The reader's own next booking within a week: not somebody else's, not a cancelled one, not one taken. */
	public function testTheFleetListNamesTheReadersOwnNextBookingWithinAWeek(): void {
		$uuid = $this->vehicle->getUuid();
		$this->bookings->book(self::OWNER, $uuid, $this->span($this->tomorrow, 1));
		$this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 8 * 86400, 1));
		$this->assertNull($this->fleetRow(self::DRIVER)['my_next_booking']);

		$cancelled = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 2 * self::HOUR, 1));
		$this->bookings->cancel(self::DRIVER, $uuid, $cancelled['uuid'], $cancelled['updated_at']);
		$next = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 4 * self::HOUR, 2));
		$this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow + 48 * self::HOUR, 1));

		$this->assertSame([
			'uuid' => $next['uuid'],
			'starts_at' => $this->tomorrow + 4 * self::HOUR,
			'starts_at_off' => 120,
			'ends_at' => $this->tomorrow + 6 * self::HOUR,
			'ends_at_off' => 120,
		], $this->fleetRow(self::DRIVER)['my_next_booking']);
		$this->assertSame($this->tomorrow, $this->fleetRow(self::OWNER)['my_next_booking']['starts_at']);

		// Running now and not taken yet is still the next; taken, it is "with you" instead.
		$now = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 1));
		$this->assertSame($now['uuid'], $this->fleetRow(self::DRIVER)['my_next_booking']['uuid']);
		$this->bookings->checkOut(self::DRIVER, $uuid, $now['uuid'], self::HANDOVER);
		$this->assertSame($next['uuid'], $this->fleetRow(self::DRIVER)['my_next_booking']['uuid']);
	}

	/** The single vehicle's read says the same, so the vehicle header can. */
	public function testTheVehiclesOwnReadSaysWhoHasItToo(): void {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book(self::DRIVER, $uuid, $this->span($this->justNow(), 3));
		$this->bookings->book(self::DRIVER, $uuid, $this->span($this->tomorrow, 1));
		$this->bookings->checkOut(self::DRIVER, $uuid, $booking['uuid'], self::HANDOVER);

		$read = $this->vehicles->find(self::DRIVER, $uuid)->jsonSerialize();

		$this->assertSame(self::DRIVER, $read['out_with']['user_id']);
		$this->assertSame($this->tomorrow, $read['my_next_booking']['starts_at']);
		$written = $this->vehicles->update(self::OWNER, $uuid, $this->vehicle->getUpdatedAt(), ['color' => 'red'])->jsonSerialize();
		$this->assertSame(self::DRIVER, $written['out_with']['user_id']);
	}

	/**
	 * The vehicle as one user's fleet list carries it, on the wire.
	 *
	 * @return array<string, mixed>
	 */
	private function fleetRow(string $userId): array {
		foreach ($this->vehicles->list($userId) as $vehicle) {
			if ($vehicle->getUuid() === $this->vehicle->getUuid()) {
				return $vehicle->jsonSerialize();
			}
		}
		$this->fail('the vehicle is not in the list');
	}

	/**
	 * A booking checked in and handed back, as the check-in answered it.
	 *
	 * @return array<string, mixed>
	 */
	private function returned(string $booker, ?string $purpose = null): array {
		$uuid = $this->vehicle->getUuid();
		$booking = $this->bookings->book($booker, $uuid, $this->span($this->justNow(), 3, ['purpose' => $purpose]));
		$this->bookings->checkOut($booker, $uuid, $booking['uuid'], self::HANDOVER);

		return $this->bookings->checkIn($booker, $uuid, $booking['uuid'], ['odo' => 52140, 'at_off' => 120]);
	}

	/**
	 * The vehicle's bookings as one user reads them, by uuid.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listed(string $userId): array {
		return array_column($this->bookings->list($userId, $this->vehicle->getUuid(), []), null, 'uuid');
	}

	/**
	 * Sets a booking's state directly, without a handover.
	 *
	 * @return int its new token
	 */
	private function moveTo(string $bookingUuid, string $state): int {
		$mapper = \OCP\Server::get(BookingMapper::class);
		$booking = $mapper->findOnVehicle((int)$this->vehicle->getId(), $bookingUuid);
		$booking->setState($state);

		return $mapper->updateChecked($booking, $booking->getUpdatedAt())->getUpdatedAt();
	}

	/** Moves a booking's end into the past, which no route does: the car kept past its end. */
	private function endBefore(string $bookingUuid, int $endsAt): void {
		$mapper = \OCP\Server::get(BookingMapper::class);
		$booking = $mapper->findOnVehicle((int)$this->vehicle->getId(), $bookingUuid);
		$booking->setEndsAt($endsAt);
		$mapper->updateChecked($booking, $booking->getUpdatedAt());
	}

	/** Moves a booking and its handover back in time, which no route does: a booking long past. */
	private function pushBack(string $bookingUuid, int $seconds): void {
		$mapper = \OCP\Server::get(BookingMapper::class);
		$booking = $mapper->findOnVehicle((int)$this->vehicle->getId(), $bookingUuid);
		$booking->setStartsAt($booking->getStartsAt() - $seconds);
		$booking->setEndsAt($booking->getEndsAt() - $seconds);
		$outAt = $booking->getOutAt();
		$inAt = $booking->getInAt();
		if ($outAt !== null) {
			$booking->setOutAt($outAt - $seconds);
		}
		if ($inAt !== null) {
			$booking->setInAt($inAt - $seconds);
		}
		$mapper->updateChecked($booking, $booking->getUpdatedAt());
	}

	/** This minute: a booking starting then is running now, and lies within the start's slack. */
	private function justNow(): int {
		$now = time();

		return $now - $now % 60;
	}

	private function grant(string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$this->vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
