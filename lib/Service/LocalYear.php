<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * A calendar year as the rows in it count it: each by its own offset
 * (docs/architecture.md#time), so a year needs no zone. The Fahrtenbuch and the CSV export both
 * sort rows into years this way.
 */
final class LocalYear {
	/**
	 * How far a local year reaches past its UTC bounds: an offset runs from twelve hours west to
	 * fourteen east (`TripService`). Exact rather than roomy, since the Fahrtenbuch states a period
	 * by whether it overlaps this.
	 */
	private const EAST = 14 * 3600;
	private const WEST = 12 * 3600;

	/**
	 * Every UTC instant some row of the local year can fall at, half-open.
	 *
	 * @return array{int, int}
	 */
	public static function window(int $year): array {
		return [gmmktime(0, 0, 0, 1, 1, $year) - self::EAST, gmmktime(0, 0, 0, 1, 1, $year + 1) + self::WEST];
	}

	/** Whether an instant entered at `$off` minutes falls in the year where it happened. */
	public static function holds(int $year, int $at, int $off): bool {
		return (int)gmdate('Y', $at + $off * 60) === $year;
	}
}
