<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * Another live booking holds the vehicle, or a trip is logged from a booking not back yet or
 * logged already. Answered with 409 and that booking, so the sheet can say whose it is and when
 * without a second read.
 */
class BookingConflictException extends \RuntimeException {
	/**
	 * @param array{uuid: string, user_id: string, user_name: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int, state: string} $booking
	 */
	public function __construct(
		string $message,
		public readonly array $booking,
	) {
		parent::__construct($message);
	}
}
