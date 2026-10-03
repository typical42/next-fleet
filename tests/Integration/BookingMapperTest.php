<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * M7's bookings through their mapper and back. No route writes the table yet, so this is the
 * only proof its columns and reads hold.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class BookingMapperTest extends TestCase {
	private const BOOKER = 'nextfleet-test-bookings';
	private const PSEUDONYM = 'nextfleet-test-bookings-gone';
	/** Far above any id the dev fleet reaches, so the reads meet only this test's rows. */
	private const VEHICLE = 990011;
	private const OTHER_VEHICLE = 990012;
	private const NOON = 1790000000;
	private const HOUR = 3600;

	private BookingMapper $bookings;

	protected function setUp(): void {
		$this->forgetTestRows();
		$this->bookings = (new Application())->getContainer()->get(BookingMapper::class);
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	private function forgetTestRows(): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('fleet_bookings')->where($qb->expr()->in(
			'vehicle_id',
			$qb->createNamedParameter([self::VEHICLE, self::OTHER_VEHICLE], IQueryBuilder::PARAM_INT_ARRAY),
		));
		$qb->executeStatement();
	}

	private function book(int $vehicleId, int $startsAt, int $endsAt, string $state = Booking::BOOKED): Booking {
		$booking = new Booking();
		$booking->setVehicleId($vehicleId);
		$booking->setUserId(self::BOOKER);
		$booking->setStartsAt($startsAt);
		$booking->setStartsAtOff(120);
		$booking->setEndsAt($endsAt);
		$booking->setEndsAtOff(120);
		$booking->setState($state);
		$booking->setCreatedBy(self::BOOKER);

		return $this->bookings->insert($booking);
	}

	/** @return list<string> */
	private function overlapping(int $from, int $to, ?int $except = null): array {
		return array_map(
			static fn (Booking $b): string => $b->getUuid(),
			$this->bookings->findLiveOverlapping(self::VEHICLE, $from, $to, $except),
		);
	}

	public function testABookingAndItsHandoverRoundTrip(): void {
		$written = $this->book(self::VEHICLE, self::NOON, self::NOON + 3 * self::HOUR, Booking::RETURNED);
		$written->setPurpose('Customer visit');
		$written->setOutAt(self::NOON + 300);
		$written->setOutAtOff(120);
		$written->setOutOdo(48210);
		$written->setOutLevel(75);
		$written->setOutNotes('Scratch on the left door');
		$written->setInAt(self::NOON + 2 * self::HOUR);
		$written->setInAtOff(60);
		$written->setInOdo(48340);
		$written->setInLevel(40);
		$written->setInNotes('Washed');
		$written->setTripId(17);
		$this->bookings->updateChecked($written, $written->getUpdatedAt());

		$read = $this->bookings->findOnVehicle(self::VEHICLE, $written->getUuid());

		$this->assertSame(self::BOOKER, $read->getUserId());
		$this->assertSame([self::NOON, 120, self::NOON + 3 * self::HOUR, 120], [
			$read->getStartsAt(), $read->getStartsAtOff(), $read->getEndsAt(), $read->getEndsAtOff(),
		]);
		$this->assertSame('Customer visit', $read->getPurpose());
		$this->assertSame('returned', $read->getState());
		$this->assertSame([self::NOON + 300, 120, 48210, 75, 'Scratch on the left door'], [
			$read->getOutAt(), $read->getOutAtOff(), $read->getOutOdo(), $read->getOutLevel(), $read->getOutNotes(),
		]);
		$this->assertSame([self::NOON + 2 * self::HOUR, 60, 48340, 40, 'Washed'], [
			$read->getInAt(), $read->getInAtOff(), $read->getInOdo(), $read->getInLevel(), $read->getInNotes(),
		]);
		$this->assertSame(17, $read->getTripId());
	}

	public function testABookingIsFoundOnlyOnItsOwnVehicle(): void {
		$booking = $this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR);

		$this->expectException(DoesNotExistException::class);
		$this->bookings->findOnVehicle(self::OTHER_VEHICLE, $booking->getUuid());
	}

	public function testALiveBookingOverASpanCollidesWithIt(): void {
		$booked = $this->book(self::VEHICLE, self::NOON, self::NOON + 3 * self::HOUR);
		$out = $this->book(self::VEHICLE, self::NOON + 4 * self::HOUR, self::NOON + 6 * self::HOUR, Booking::OUT);

		// Ordered by start, and touching either end from inside counts.
		$this->assertSame(
			[$booked->getUuid(), $out->getUuid()],
			$this->overlapping(self::NOON + 2 * self::HOUR, self::NOON + 5 * self::HOUR),
		);
		// A span wholly inside a booking collides with it, and so does one around it.
		$this->assertSame([$booked->getUuid()], $this->overlapping(self::NOON + 1, self::NOON + 2));
		$this->assertSame([$booked->getUuid()], $this->overlapping(self::NOON - self::HOUR, self::NOON + 3 * self::HOUR + 1));
	}

	public function testBookingsThatOnlyTouchDoNotCollide(): void {
		$this->book(self::VEHICLE, self::NOON, self::NOON + 3 * self::HOUR);

		$this->assertSame([], $this->overlapping(self::NOON - self::HOUR, self::NOON));
		$this->assertSame([], $this->overlapping(self::NOON + 3 * self::HOUR, self::NOON + 4 * self::HOUR));
	}

	public function testOnlyALiveBookingOfThisVehicleCollides(): void {
		$this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR, Booking::RETURNED);
		$this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR, Booking::CANCELLED);
		$this->book(self::OTHER_VEHICLE, self::NOON, self::NOON + self::HOUR);
		$deleted = $this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR);
		$this->bookings->softDelete($deleted, $deleted->getUpdatedAt());

		$this->assertSame([], $this->overlapping(self::NOON, self::NOON + self::HOUR));
	}

	public function testABookingBeingChangedDoesNotCollideWithItself(): void {
		$booking = $this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR);

		$this->assertSame([], $this->overlapping(self::NOON, self::NOON + 2 * self::HOUR, $booking->getId()));
	}

	public function testErasingTheBookerPseudonymisesTheirBookings(): void {
		$booking = $this->book(self::VEHICLE, self::NOON, self::NOON + self::HOUR);

		$this->bookings->pseudonymise(self::BOOKER, self::PSEUDONYM);

		$read = $this->bookings->findByUuid($booking->getUuid());
		$this->assertSame(self::PSEUDONYM, $read->getUserId());
		$this->assertSame(self::PSEUDONYM, $read->getCreatedBy());
		// Nobody edited the row, so an open client's token still holds.
		$this->assertSame($booking->getUpdatedAt(), $read->getUpdatedAt());
	}
}
