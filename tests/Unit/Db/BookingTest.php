<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Booking;
use PHPUnit\Framework\TestCase;

/** Where a booking's answer stands (docs/architecture.md#booking-approval). */
class BookingTest extends TestCase {
	public function testABookedRequestOrQuestionWaits(): void {
		$this->assertTrue(self::booking(Booking::BOOKED, Booking::REQUESTED)->waits());
		$this->assertTrue(self::booking(Booking::BOOKED, Booking::ASKED)->waits());
		$this->assertFalse(self::booking(Booking::BOOKED, Booking::APPROVED)->waits());
		$this->assertFalse(self::booking(Booking::BOOKED, null)->waits());
	}

	/** A cancel keeps the approval it stood at, as history. */
	public function testACancelledBookingWaitsForNothing(): void {
		$this->assertFalse(self::booking(Booking::CANCELLED, Booking::REQUESTED)->waits());
		$this->assertFalse(self::booking(Booking::CANCELLED, Booking::ASKED)->waits());
	}

	/** Made by somebody other than its booker: an approver booked it for them (docs/architecture.md#booking-for-a-driver). */
	public function testABookingByAnotherHandIsMadeForItsBooker(): void {
		$this->assertTrue(Booking::fromRow(['user_id' => 'ben', 'created_by' => 'anna'])->madeByAnother());
		$this->assertFalse(Booking::fromRow(['user_id' => 'ben', 'created_by' => 'ben'])->madeByAnother());
	}

	private static function booking(string $state, ?string $approval): Booking {
		return Booking::fromRow(['state' => $state, 'approval' => $approval]);
	}
}
