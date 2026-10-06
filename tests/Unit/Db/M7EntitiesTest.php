<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\TestCase;

/**
 * What the booking columns mean when they come back from the database, before any service
 * reads them.
 */
class M7EntitiesTest extends TestCase {
	public function testABookingReadsItsInstantsAndCountersAsIntegers(): void {
		$booking = Booking::fromRow([
			'vehicle_id' => '4', 'user_id' => 'anna',
			'starts_at' => '1790000000', 'starts_at_off' => '120',
			'ends_at' => '1790010800', 'ends_at_off' => '120',
			'purpose' => 'Customer visit', 'state' => Booking::OUT,
			'out_at' => '1790000300', 'out_at_off' => '120', 'out_odo' => '48210', 'out_level' => '75',
			'out_notes' => 'Scratch on the left door', 'trip_id' => '17',
		]);

		$this->assertSame(4, $booking->getVehicleId());
		$this->assertSame('anna', $booking->getUserId());
		$this->assertSame(1790000000, $booking->getStartsAt());
		$this->assertSame(120, $booking->getStartsAtOff());
		$this->assertSame(1790010800, $booking->getEndsAt());
		$this->assertSame(120, $booking->getEndsAtOff());
		$this->assertSame('Customer visit', $booking->getPurpose());
		$this->assertSame('out', $booking->getState());
		$this->assertSame(1790000300, $booking->getOutAt());
		$this->assertSame(120, $booking->getOutAtOff());
		$this->assertSame(48210, $booking->getOutOdo());
		$this->assertSame(75, $booking->getOutLevel());
		$this->assertSame('Scratch on the left door', $booking->getOutNotes());
		$this->assertSame(17, $booking->getTripId());
	}

	public function testABookingNotYetHandedOverHasNoHandover(): void {
		$booking = Booking::fromRow([
			'vehicle_id' => '4', 'user_id' => 'anna', 'starts_at' => '1', 'starts_at_off' => '0',
			'ends_at' => '2', 'ends_at_off' => '0', 'state' => Booking::BOOKED,
		]);

		$this->assertNull($booking->getPurpose());
		foreach ([
			$booking->getOutAt(), $booking->getOutAtOff(), $booking->getOutOdo(), $booking->getOutLevel(), $booking->getOutNotes(),
			$booking->getInAt(), $booking->getInAtOff(), $booking->getInOdo(), $booking->getInLevel(), $booking->getInNotes(),
			$booking->getTripId(),
		] as $field) {
			$this->assertNull($field);
		}
	}

	public function testOnlyABookedOrOutBookingIsLive(): void {
		$this->assertSame(['booked', 'out', 'returned', 'cancelled'], Booking::STATES);
		$this->assertSame(['booked', 'out'], Booking::LIVE);
	}

	/**
	 * `book` is `log` on a vehicle in service, so the screen offers a booking only where
	 * BookingService takes one. Read off the lifecycle as it is answered, after an edit too.
	 */
	public function testAVehicleSaysBookWhereItTakesABooking(): void {
		$vehicle = new Vehicle();
		$vehicle->setLifecycle(Vehicle::ACTIVE);
		$vehicle->setMay(['view', 'log']);
		$this->assertSame(['view', 'log', 'book'], $vehicle->jsonSerialize()['may']);
		$this->assertSame(['view', 'log'], $vehicle->getMay());

		$vehicle->setLifecycle(Vehicle::LAID_UP);
		$this->assertSame(['view', 'log'], $vehicle->jsonSerialize()['may']);

		$vehicle->setLifecycle(Vehicle::ACTIVE);
		$vehicle->setMay(['view']);
		$this->assertSame(['view'], $vehicle->jsonSerialize()['may']);
	}
}
