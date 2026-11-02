<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\BookingRecipient;
use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\TestCase;

/** Which booking events a vehicle's list hears, and who is on it (BookingNotices). */
class BookingNoticeEntitiesTest extends TestCase {
	/** A row older than the column hears what a new vehicle hears. */
	public function testAVehicleHearsOfBookingsAndTakingsUnlessTicked(): void {
		$this->assertSame(['booked', 'taken'], Vehicle::fromRow(['user_id' => 'alice'])->bookingNotices());
		$this->assertSame(['booked', 'taken'], Vehicle::fromRow(['user_id' => 'alice', 'booking_notices' => null])->bookingNotices());
		$this->assertSame(['returned'], Vehicle::fromRow(['user_id' => 'alice', 'booking_notices' => '["returned"]'])->bookingNotices());
		$this->assertSame([], Vehicle::fromRow(['user_id' => 'alice', 'booking_notices' => '[]'])->bookingNotices());
	}

	public function testTheEventsAreOnTheWire(): void {
		$this->assertSame(['booked', 'taken'], Vehicle::fromRow(['user_id' => 'alice'])->jsonSerialize()['booking_notices']);
		$this->assertSame([], Vehicle::fromRow(['user_id' => 'alice', 'booking_notices' => '[]'])->jsonSerialize()['booking_notices']);
	}

	/** One of the two names a row, the other is null. */
	public function testARecipientIsAUserOrAGroup(): void {
		$user = BookingRecipient::fromRow(['vehicle_id' => '4', 'user_id' => 'ben', 'group_id' => null]);
		$group = BookingRecipient::fromRow(['vehicle_id' => '4', 'user_id' => null, 'group_id' => 'drivers']);

		$this->assertSame(['user', 'ben'], [$user->type(), $user->name()]);
		$this->assertSame(['group', 'drivers'], [$group->type(), $group->name()]);
	}
}
