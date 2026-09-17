<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;

/**
 * One vehicle's logbook for one calendar year, as the core read it and before any country lays it
 * out (`IReportRenderer`). Every judgement in it is the core's: which trips belong to the year,
 * what each one leaves unstated, when Logbook Mode was on. A renderer decides only how that reads.
 *
 * @psalm-type LateChange = array{change: string, at: int, fields: array<string, array{mixed, mixed}>, offsets: array{started_at_off: int, ended_at_off: int}}
 */
final class LogbookReport {
	/**
	 * `$trips` is oldest first, voided ones included - a voided trip has a `deleted_at`. On each,
	 * `missing` is empty for a trip set off outside every period, and `late` is every change made
	 * after the lock delay, oldest first: the trail's word for it (`edited`, `voided`, `restored`),
	 * the server instant it was made at, each column it changed as `[before, after]`, and the trip's
	 * offsets as they stood before it.
	 *
	 * @param int $year the local calendar year the trips set off in (docs/architecture.md#time)
	 * @param list<array{trip: Trip, missing: list<string>, late: list<LateChange>}> $trips
	 * @param list<array{from: int, to: ?int}> $periods the instants Logbook Mode was switched on and
	 *                                                  off, for every period that reaches into the year; `to` is null while it is still on
	 * @param ?string $sourceUrl where the requirement the logbook claims to meet is written, or null
	 *                           where the jurisdiction states none
	 */
	public function __construct(
		public readonly Vehicle $vehicle,
		public readonly int $year,
		public readonly array $trips,
		public readonly array $periods,
		public readonly ?string $sourceUrl,
	) {
	}
}
