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
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\BookingConflictException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A booking ahead on a vehicle whose owner wants to answer it first (CONTEXT.md, Pool;
 * docs/adr/0011-a-small-firm-may-opt-into-approval-and-hidden-costs.md).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class BookingApprovalTest extends TestCase {
	/** Not Nextcloud accounts, for the reason BookingTest gives. */
	private const OWNER = 'nextfleet-test-appr-owner';
	private const MANAGER = 'nextfleet-test-appr-manager';
	private const DRIVER = 'nextfleet-test-appr-driver';
	/** A second driver on the car, who books beside the first. */
	private const OTHER = 'nextfleet-test-appr-other';
	private const HOUR = 3600;

	private BookingService $bookings;
	private VehicleService $vehicles;
	private Vehicle $vehicle;
	/** The next full hour a day from now: always to come while a case runs. */
	private int $tomorrow;

	protected function setUp(): void {
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forgetTestRows();
		$this->vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-AP 100', 'booking_approval' => true]);
		$this->grant(self::MANAGER, 'manager');
		$this->grant(self::DRIVER, 'driver');
		$this->grant(self::OTHER, 'driver');
		$now = time();
		$this->tomorrow = $now + 86400 + self::HOUR - $now % self::HOUR;
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows and the owner's default this suite invents, gone for real. */
	private function forgetTestRows(): void {
		\OCP\Server::get(IConfig::class)->deleteUserValue(self::OWNER, Application::APP_ID, Vehicle::OWNER_APPROVAL);
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::DRIVER, self::OTHER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_bookings' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function testADriversBookingAheadWaitsForAnAnswer(): void {
		$written = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$this->assertSame(Booking::BOOKED, $written['state']);
		$this->assertSame(Booking::REQUESTED, $written['approval']);
		$this->assertNull($written['approval_by']);
		$this->assertNull($written['approval_comment']);
	}

	/** The vehicle names the reader's next booking with its answer, so a request does not read as booked. */
	public function testTheReadersNextBookingCarriesItsAnswer(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->assertSame(Booking::REQUESTED, $this->vehicles->find(self::DRIVER, $this->vehicle->getUuid())->jsonSerialize()['my_next_booking']['approval']);

		$this->bookings->answer(self::MANAGER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);
		$this->assertSame(Booking::APPROVED, $this->vehicles->find(self::DRIVER, $this->vehicle->getUuid())->jsonSerialize()['my_next_booking']['approval']);
	}

	/** Asking themselves would be a formality. */
	public function testTheOwnerAndAManagerAreApprovedByBooking(): void {
		$owner = $this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));
		$manager = $this->bookings->book(self::MANAGER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 2 * self::HOUR, 2));

		$this->assertSame([Booking::APPROVED, self::OWNER], [$owner['approval'], $owner['approval_by']]);
		$this->assertSame([Booking::APPROVED, self::MANAGER], [$manager['approval'], $manager['approval_by']]);
	}

	public function testAVehicleThatAsksNothingAnswersNoApproval(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-AP 101']);

		$this->assertFalse($vehicle->jsonSerialize()['booking_approval']);
		$this->assertNull($this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span($this->tomorrow, 2))['approval']);
	}

	/** A manager's sheet sends the switch back as it read it. */
	public function testOnlyTheOwnerTurnsApprovalOnOrOff(): void {
		$read = $this->vehicles->find(self::MANAGER, $this->vehicle->getUuid());

		$kept = $this->vehicles->update(self::MANAGER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval' => true, 'color' => 'red']);
		$this->assertTrue($kept->needsApproval());
		try {
			$this->vehicles->update(self::MANAGER, $this->vehicle->getUuid(), $kept->getUpdatedAt(), ['booking_approval' => false]);
			$this->fail('a manager turned approval off');
		} catch (AccessDeniedException) {
		}
		$off = $this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $kept->getUpdatedAt(), ['booking_approval' => false]);
		$this->assertFalse($off->needsApproval());
	}

	/** A manager's sheet read before the owner's switch sends it back as it was: a stale save, no refusal. */
	public function testAManagersStaleSaveIsStaleNotRefused(): void {
		$read = $this->vehicles->find(self::MANAGER, $this->vehicle->getUuid());
		$this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval' => false]);

		$this->expectException(StaleUpdateException::class);
		$this->vehicles->update(self::MANAGER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval' => true, 'color' => 'red']);
	}

	public function testAnApproverApprovesWithAComment(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->assertSame(['edit', 'cancel', 'approve', 'ask', 'decline'], $this->listed(self::MANAGER, $asked['uuid'])['may']);
		$this->assertSame(['edit', 'cancel'], $asked['may']);

		$answered = $this->bookings->answer(self::MANAGER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, ['comment' => 'Fill it up after']);

		$this->assertSame(Booking::BOOKED, $answered['state']);
		$this->assertSame([Booking::APPROVED, 'Fill it up after', self::MANAGER], [$answered['approval'], $answered['approval_comment'], $answered['approval_by']]);
		$this->assertSame(['edit', 'cancel', 'check_out'], $answered['may']);
	}

	/** Declined is cancelled: the slot is free for the next. */
	public function testADeclinedRequestFreesItsSlot(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$answered = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::DECLINED, []);

		$this->assertSame([Booking::CANCELLED, Booking::DECLINED, null, self::OWNER], [$answered['state'], $answered['approval'], $answered['approval_comment'], $answered['approval_by']]);
		$this->assertSame(Booking::APPROVED, $this->bookings->book(self::MANAGER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3))['approval']);
	}

	/** Asked back, the booking still holds its slot and can be answered again. */
	public function testAskedBackTheBookingHoldsItsSlotAndWaitsForTheNextAnswer(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$back = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, ['comment' => 'Could you take the van?']);

		$this->assertSame([Booking::BOOKED, Booking::ASKED, 'Could you take the van?'], [$back['state'], $back['approval'], $back['approval_comment']]);
		try {
			$this->bookings->book(self::MANAGER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
			$this->fail('a booking over an asked one was taken');
		} catch (BookingConflictException $e) {
			$this->assertSame(Booking::ASKED, $e->booking['approval']);
		}
		$approved = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $back['updated_at'], Booking::APPROVED, []);
		$this->assertSame([Booking::APPROVED, null], [$approved['approval'], $approved['approval_comment']]);
	}

	/** A second driver's sheet says "Requested by …" where it says "Booked by …". */
	public function testARequestHoldsItsSlotAndTheRefusalSaysSo(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		try {
			$this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow + self::HOUR, 1));
			$this->fail('a booking over a request was taken');
		} catch (BookingConflictException $e) {
			$this->assertSame([$asked['uuid'], Booking::REQUESTED], [$e->booking['uuid'], $e->booking['approval']]);
		}
	}

	public function testADriverDoesNotAnswerTheirOwnRequest(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$this->expectException(AccessDeniedException::class);
		$this->bookings->answer(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);
	}

	/** What is approved stays so; cancelling it is what is left. */
	public function testAnAnsweredBookingIsNotAnsweredAgain(): void {
		$approved = $this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$this->expectException(\InvalidArgumentException::class);
		$this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $approved['uuid'], $approved['updated_at'], Booking::DECLINED, []);
	}

	public function testAnAnswerToABookingReadBeforeAChangeIsRefused(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], $this->span($this->tomorrow, 3, ['purpose' => 'Fair']));

		$this->expectException(StaleUpdateException::class);
		$this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);
	}

	/** `requested` is where an answer starts, not one. */
	public function testAnAnswerIsApprovedAskedOrDeclined(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$this->expectException(\InvalidArgumentException::class);
		/** @psalm-suppress InvalidArgument a request body can name any word */
		$this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::REQUESTED, []);
	}

	/**
	 * A request nobody answered by its end is declined, with no comment and by nobody; an
	 * approved one, and a request still running, are left as they are.
	 */
	public function testARequestNobodyAnsweredIsDeclinedAtItsEnd(): void {
		$unanswered = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));
		$approved = $this->bookings->book(self::OWNER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 2 * self::HOUR, 2));
		$running = $this->bookings->book(self::OTHER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 4 * self::HOUR, 2));
		$this->pastEnd($unanswered['uuid']);
		$this->pastEnd($approved['uuid']);

		$this->bookings->declineUnanswered();

		$listed = $this->listed(self::OWNER, $unanswered['uuid']);
		$this->assertSame([Booking::CANCELLED, Booking::DECLINED, null, null], [$listed['state'], $listed['approval'], $listed['approval_comment'], $listed['approval_by']]);
		$this->assertSame([Booking::BOOKED, Booking::APPROVED], [$this->listed(self::OWNER, $approved['uuid'])['state'], $this->listed(self::OWNER, $approved['uuid'])['approval']]);
		$this->assertSame(Booking::REQUESTED, $this->listed(self::OWNER, $running['uuid'])['approval']);
	}

	/** Its end come, a request is past answering; the job declines it within the hour. */
	public function testARequestPastItsEndIsNotAnswered(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));
		$this->pastEnd($asked['uuid']);
		$ended = $this->listed(self::OWNER, $asked['uuid']);
		$this->assertSame([], array_intersect(['approve', 'ask', 'decline'], $ended['may']));

		$this->expectException(\InvalidArgumentException::class);
		$this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $ended['updated_at'], Booking::APPROVED, []);
	}

	/** With nobody left to ask, what waited is the owner's yes; what was answered stays so. */
	public function testTurningApprovalOffApprovesWhatWaits(): void {
		$requested = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));
		$asked = $this->bookings->book(self::OTHER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 2 * self::HOUR, 2));
		$this->bookings->answer(self::MANAGER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, ['comment' => 'Why?']);
		$declined = $this->bookings->book(self::OTHER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 4 * self::HOUR, 2));
		$this->bookings->answer(self::MANAGER, $this->vehicle->getUuid(), $declined['uuid'], $declined['updated_at'], Booking::DECLINED, []);
		// Over already: past answering, so declineUnanswered()'s, not the switch's.
		$ended = $this->bookings->book(self::OTHER, $this->vehicle->getUuid(), $this->span($this->tomorrow + 6 * self::HOUR, 2));
		$this->pastEnd($ended['uuid']);
		$read = $this->vehicles->find(self::OWNER, $this->vehicle->getUuid());

		$this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval' => false]);

		foreach ([$requested, $asked] as $one) {
			$listed = $this->listed(self::OWNER, $one['uuid']);
			$this->assertSame([Booking::BOOKED, Booking::APPROVED, self::OWNER, null], [$listed['state'], $listed['approval'], $listed['approval_by'], $listed['approval_comment']]);
		}
		$this->assertSame([Booking::CANCELLED, Booking::DECLINED], [$this->listed(self::OWNER, $declined['uuid'])['state'], $this->listed(self::OWNER, $declined['uuid'])['approval']]);
		$this->assertSame(Booking::REQUESTED, $this->listed(self::OWNER, $ended['uuid'])['approval']);
	}

	/**
	 * Take it now waits for nobody, but only for the rest of the day: a car kept longer is a
	 * booking ahead. The day is the booker's, by the offset the sheet sends.
	 */
	public function testTakingTheCarNowAsksNothingUntilTheDaysEnd(): void {
		$now = time();
		// The offset that puts the booker's clock at about noon, whenever the suite runs.
		$off = intdiv((43200 - $now % 86400 + 86400 + 43200) % 86400 - 43200, 60);
		$taken = ['starts_at' => $now, 'starts_at_off' => $off, 'ends_at' => $now + 3 * self::HOUR, 'ends_at_off' => $off];

		$today = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $taken);
		$this->assertNull($today['approval']);
		$this->assertContains('check_out', $today['may']);
		$this->bookings->cancel(self::DRIVER, $this->vehicle->getUuid(), $today['uuid'], $today['updated_at']);

		$overnight = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), ['ends_at' => $now + 13 * self::HOUR] + $taken);
		$this->assertSame(Booking::REQUESTED, $overnight['approval']);
	}

	/** The approver said yes to a span: a new one asks again, a new purpose does not. */
	public function testAMovedBookingIsAskedForAgain(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$approved = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, ['comment' => 'Fine']);

		$renamed = $this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $approved['updated_at'], $this->span($this->tomorrow, 3, ['purpose' => 'Fair']));
		$this->assertSame([Booking::APPROVED, 'Fine', self::OWNER], [$renamed['approval'], $renamed['approval_comment'], $renamed['approval_by']]);

		$moved = $this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $renamed['updated_at'], $this->span($this->tomorrow, 4));
		$this->assertSame([Booking::REQUESTED, null, null], [$moved['approval'], $moved['approval_comment'], $moved['approval_by']]);

		$back = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $moved['updated_at'], Booking::ASKED, ['comment' => 'Shorter?']);
		$shorter = $this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $back['updated_at'], $this->span($this->tomorrow, 2));
		$this->assertSame([Booking::REQUESTED, null], [$shorter['approval'], $shorter['approval_comment']]);
	}

	/**
	 * Asked back, the booker answers by changing the booking, a new purpose as much as a new span:
	 * it is a request again. An approver's new purpose answers nothing, nor does a save that
	 * changes nothing.
	 */
	public function testTheBookersChangeAnswersTheQuestion(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$back = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, ['comment' => 'What for?']);

		$noted = $this->bookings->change(self::MANAGER, $this->vehicle->getUuid(), $asked['uuid'], $back['updated_at'], $this->span($this->tomorrow, 3, ['purpose' => 'Ask Ben']));
		$this->assertSame([Booking::ASKED, 'What for?', self::OWNER], [$noted['approval'], $noted['approval_comment'], $noted['approval_by']]);

		$saved = $this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $noted['updated_at'], $this->span($this->tomorrow, 3, ['purpose' => 'Ask Ben']));
		$this->assertSame(Booking::ASKED, $saved['approval'], 'a save that changes nothing answers nothing');

		$answered = $this->bookings->change(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $saved['updated_at'], $this->span($this->tomorrow, 3, ['purpose' => 'The fair in Hall 3']));
		$this->assertSame([Booking::REQUESTED, null, null], [$answered['approval'], $answered['approval_comment'], $answered['approval_by']]);
	}

	/** An approver moving a booking answers it by doing so, as one booking does. */
	public function testABookingAnApproverMovesIsApproved(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));

		$moved = $this->bookings->change(self::MANAGER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], $this->span($this->tomorrow + self::HOUR, 3));

		$this->assertSame([Booking::APPROVED, self::MANAGER], [$moved['approval'], $moved['approval_by']]);
	}

	/**
	 * Its start come, a request still holds the car for an answer, but nobody takes it under one.
	 * Longer than a day, so it is a request even though it starts now.
	 */
	public function testARequestIsNotTaken(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span(time() + 60, 25));

		try {
			$this->bookings->checkOut(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], ['odo' => 1000, 'at_off' => 120]);
			$this->fail('a request was taken');
		} catch (\InvalidArgumentException) {
		}
		$back = $this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, []);
		$this->assertNotContains('check_out', $this->listed(self::OWNER, $asked['uuid'])['may']);
		$this->assertSame(['approve', 'ask', 'decline'], array_slice($this->listed(self::OWNER, $asked['uuid'])['may'], -3));
		$this->expectException(\InvalidArgumentException::class);
		$this->bookings->checkOut(self::DRIVER, $this->vehicle->getUuid(), $back['uuid'], ['odo' => 1000, 'at_off' => 120]);
	}

	/** Another driver sees where the answer stands, never what the approver wrote. */
	public function testTheCommentIsForTheBookerAndTheApproversOnly(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 3));
		$this->bookings->answer(self::OWNER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, ['comment' => 'Could you take the van?']);

		$this->assertSame([Booking::ASKED, null], [$this->listed(self::OTHER, $asked['uuid'])['approval'], $this->listed(self::OTHER, $asked['uuid'])['approval_comment']]);
		$this->assertSame('Could you take the van?', $this->listed(self::DRIVER, $asked['uuid'])['approval_comment']);
		$this->assertSame('Could you take the van?', $this->listed(self::MANAGER, $asked['uuid'])['approval_comment']);
	}

	/** A new vehicle asks as its owner's default says, which starts off. */
	public function testAVehicleFollowsItsOwnersDefault(): void {
		$follows = $this->shared('NF-AP 102');
		$this->assertSame([false, Vehicle::APPROVAL_DEFAULT], [$follows->needsApproval(), $follows->approvalRule()]);
		$this->assertFalse(\OCP\Server::get(PreferencesService::class)->forUser(self::OWNER)['preferences']['booking_approval']);

		\OCP\Server::get(PreferencesService::class)->write(self::OWNER, ['booking_approval' => true]);

		$this->assertTrue($this->vehicles->find(self::DRIVER, $follows->getUuid())->jsonSerialize()['booking_approval']);
		$this->assertSame(Booking::REQUESTED, $this->bookings->book(self::DRIVER, $follows->getUuid(), $this->span($this->tomorrow, 2))['approval']);
	}

	/** Turned off for all, what waits on a vehicle that follows is approved; one that always asks keeps asking. */
	public function testTurningTheOwnersDefaultOffApprovesWhatWaitsWhereItApplied(): void {
		$preferences = \OCP\Server::get(PreferencesService::class);
		$preferences->write(self::OWNER, ['booking_approval' => true]);
		$follows = $this->shared('NF-AP 102');
		$waiting = $this->bookings->book(self::DRIVER, $follows->getUuid(), $this->span($this->tomorrow, 2));
		$always = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));

		$preferences->write(self::OWNER, ['booking_approval' => false]);

		$answered = $this->listedOn($follows, self::OWNER, $waiting['uuid']);
		$this->assertSame([Booking::APPROVED, self::OWNER], [$answered['approval'], $answered['approval_by']]);
		$this->assertSame(Booking::REQUESTED, $this->listed(self::OWNER, $always['uuid'])['approval']);
	}

	/** A vehicle in the trash misses the default going off; brought back, it approves what waits. */
	public function testARestoredVehicleThatNoLongerAsksApprovesWhatWaits(): void {
		$preferences = \OCP\Server::get(PreferencesService::class);
		$preferences->write(self::OWNER, ['booking_approval' => true]);
		$follows = $this->shared('NF-AP 102');
		$waiting = $this->bookings->book(self::DRIVER, $follows->getUuid(), $this->span($this->tomorrow, 2));
		$trashed = $this->vehicles->delete(self::OWNER, $follows->getUuid(), $this->vehicles->find(self::OWNER, $follows->getUuid())->getUpdatedAt());

		$preferences->write(self::OWNER, ['booking_approval' => false]);
		$this->vehicles->restore(self::OWNER, $follows->getUuid(), $trashed->getUpdatedAt());

		$answered = $this->listedOn($follows, self::OWNER, $waiting['uuid']);
		$this->assertSame([Booking::APPROVED, self::OWNER], [$answered['approval'], $answered['approval_by']]);
	}

	/**
	 * IConfig keeps the default it read for the rest of the request. A save that read it off while
	 * it was on still approves what waits once the vehicle stops asking.
	 */
	public function testASaveThatReadAnOldDefaultStillApprovesWhatWaits(): void {
		$follows = $this->shared('NF-AP 102');
		$this->turnedOnBehindTheCache();
		// The driver's request, as the request that read the default on writes it.
		$waiting = $this->bookings->book(self::DRIVER, $follows->getUuid(), $this->span($this->tomorrow, 2));
		$mapper = \OCP\Server::get(BookingMapper::class);
		$row = $mapper->findOnVehicle((int)$follows->getId(), $waiting['uuid']);
		$row->setApproval(Booking::REQUESTED);
		$mapper->updateChecked($row, $row->getUpdatedAt());
		$read = $this->vehicles->find(self::OWNER, $follows->getUuid());
		$this->assertFalse($read->needsApproval(), 'the request still reads the old default');

		$this->vehicles->update(self::OWNER, $follows->getUuid(), $read->getUpdatedAt(), ['booking_approval_rule' => Vehicle::APPROVAL_NEVER]);

		$this->assertSame(Booking::APPROVED, $this->listedOn($follows, self::OWNER, $waiting['uuid'])['approval']);
	}

	/**
	 * Two writes of the default at once: this request read it off before the other wrote it on.
	 * Turned off here, it is stored off, and the answer says so.
	 */
	public function testTheDefaultIsWrittenPastTheValueTheRequestRead(): void {
		$this->shared('NF-AP 102');
		$this->turnedOnBehindTheCache();

		$answer = \OCP\Server::get(PreferencesService::class)->write(self::OWNER, ['booking_approval' => false]);

		$this->assertFalse(\OCP\Server::get(VehicleMapper::class)->storedOwnerApproval(self::OWNER), 'the stored default');
		$this->assertFalse($answer['preferences']['booking_approval'], 'the answer');
	}

	/** The vehicle's own way off: from Always to as for all, while the default is off. */
	public function testAVehicleThatStopsAskingApprovesWhatWaits(): void {
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->span($this->tomorrow, 2));
		$read = $this->vehicles->find(self::OWNER, $this->vehicle->getUuid());

		$written = $this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval_rule' => Vehicle::APPROVAL_DEFAULT]);

		$this->assertSame([false, Vehicle::APPROVAL_DEFAULT], [$written->needsApproval(), $written->approvalRule()]);
		$this->assertSame(Booking::APPROVED, $this->listed(self::OWNER, $asked['uuid'])['approval']);
	}

	/**
	 * A client of the bool sets Always or Never by changing it. Sent back as it reads, it changes
	 * nothing, so a sheet that sends the whole vehicle keeps the vehicle following its owner.
	 */
	public function testTheBoolPinsTheVehicleOnlyWhenItChangesTheAnswer(): void {
		$follows = $this->shared('NF-AP 102');

		$kept = $this->vehicles->update(self::OWNER, $follows->getUuid(), $follows->getUpdatedAt(), ['booking_approval' => false, 'color' => 'red']);
		$this->assertSame(Vehicle::APPROVAL_DEFAULT, $kept->approvalRule());
		$on = $this->vehicles->update(self::OWNER, $follows->getUuid(), $kept->getUpdatedAt(), ['booking_approval' => true]);
		$this->assertSame(Vehicle::APPROVAL_ALWAYS, $on->approvalRule());
		$off = $this->vehicles->update(self::OWNER, $follows->getUuid(), $on->getUpdatedAt(), ['booking_approval' => false]);
		$this->assertSame(Vehicle::APPROVAL_NEVER, $off->approvalRule());
		$ruled = $this->vehicles->update(self::OWNER, $follows->getUuid(), $off->getUpdatedAt(), ['booking_approval' => true, 'booking_approval_rule' => Vehicle::APPROVAL_DEFAULT]);
		$this->assertSame(Vehicle::APPROVAL_DEFAULT, $ruled->approvalRule(), 'the choice outranks the bool');
	}

	/**
	 * A vehicle that follows answers differently once the default moves, so its token moves too: a
	 * save that sends the old answer back is stale, not refused, and pins nothing.
	 */
	public function testANewDefaultMakesASaveReadBeforeItStale(): void {
		$follows = $this->shared('NF-AP 102');
		$read = $this->vehicles->find(self::MANAGER, $follows->getUuid());

		\OCP\Server::get(PreferencesService::class)->write(self::OWNER, ['booking_approval' => true]);

		try {
			$this->vehicles->update(self::MANAGER, $follows->getUuid(), $read->getUpdatedAt(), ['booking_approval' => false, 'color' => 'red']);
			$this->fail('a save read before the default moved went through');
		} catch (StaleUpdateException) {
		}
		$this->assertSame(Vehicle::APPROVAL_DEFAULT, $this->vehicles->find(self::OWNER, $follows->getUuid())->approvalRule());
	}

	/** A manager's sheet sends the choice back as it read it. */
	public function testOnlyTheOwnerChangesTheChoice(): void {
		$read = $this->vehicles->find(self::MANAGER, $this->vehicle->getUuid());
		$kept = $this->vehicles->update(self::MANAGER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval_rule' => Vehicle::APPROVAL_ALWAYS, 'color' => 'red']);

		$this->expectException(AccessDeniedException::class);
		$this->vehicles->update(self::MANAGER, $this->vehicle->getUuid(), $kept->getUpdatedAt(), ['booking_approval_rule' => Vehicle::APPROVAL_NEVER]);
	}

	public function testAChoiceNotOfferedIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $this->vehicle->getUpdatedAt(), ['booking_approval_rule' => 'sometimes']);
	}

	/** The switch for all vehicles is offered to an owner whose vehicle somebody else uses or used. */
	public function testThePreferencesSayWhetherTheUserOwnsASharedVehicle(): void {
		$preferences = \OCP\Server::get(PreferencesService::class);

		$this->assertTrue($preferences->forUser(self::OWNER)['owns_shared']);
		$this->assertFalse($preferences->forUser(self::MANAGER)['owns_shared']);
	}

	public function testTheOwnersDefaultIsTrueOrFalse(): void {
		$this->expectException(\InvalidArgumentException::class);
		\OCP\Server::get(PreferencesService::class)->write(self::OWNER, ['booking_approval' => 'yes']);
	}

	/**
	 * The owner's default as two requests see it: this one read it off, and another turned it on
	 * past this one's IConfig since.
	 */
	private function turnedOnBehindTheCache(): void {
		$config = \OCP\Server::get(IConfig::class);
		$config->setUserValue(self::OWNER, Application::APP_ID, Vehicle::OWNER_APPROVAL, '0');
		$this->assertSame('0', $config->getUserValue(self::OWNER, Application::APP_ID, Vehicle::OWNER_APPROVAL, '0'));
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('preferences')
			->set('configvalue', $qb->createNamedParameter('1'))
			->where($qb->expr()->eq('userid', $qb->createNamedParameter(self::OWNER)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(Vehicle::OWNER_APPROVAL)))
			->executeStatement();
	}

	/** A second vehicle of the owner's that follows their default, with the two drivers on it. */
	private function shared(string $plate): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => $plate]);
		$this->grant(self::MANAGER, 'manager', $vehicle);
		$this->grant(self::DRIVER, 'driver', $vehicle);
		$this->grant(self::OTHER, 'driver', $vehicle);

		return $vehicle;
	}

	/** @return array<string, mixed> the booking as the user's list of that vehicle answers it */
	private function listedOn(Vehicle $vehicle, string $userId, string $uuid): array {
		foreach ($this->bookings->list($userId, $vehicle->getUuid(), []) as $booking) {
			if ($booking['uuid'] === $uuid) {
				return $booking;
			}
		}
		$this->fail('no booking ' . $uuid);
	}

	/** Moves the booking to the day that ended a minute ago, which no sheet may book. */
	private function pastEnd(string $uuid): void {
		$mapper = \OCP\Server::get(BookingMapper::class);
		$row = $mapper->findOnVehicle((int)$this->vehicle->getId(), $uuid);
		$row->setStartsAt(time() - 86400);
		$row->setEndsAt(time() - 60);
		$mapper->updateChecked($row, $row->getUpdatedAt());
	}

	/** @return array<string, mixed> the booking as the user's list answers it */
	private function listed(string $userId, string $uuid): array {
		return $this->listedOn($this->vehicle, $userId, $uuid);
	}

	/**
	 * @param array<string, mixed> $more
	 * @return array<string, mixed>
	 */
	private function span(int $from, int $hours, array $more = []): array {
		return ['starts_at' => $from, 'starts_at_off' => 120, 'ends_at' => $from + $hours * self::HOUR, 'ends_at_off' => 120] + $more;
	}

	private function grant(string $grantee, string $role, ?Vehicle $vehicle = null): void {
		$grant = new Access();
		$grant->setVehicleId((int)($vehicle ?? $this->vehicle)->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
