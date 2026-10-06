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
 * The business trips one reader entered on one vehicle in one calendar year, each valued at the
 * rate on its day, as the core read them and before any country lays them out (`IClaimRenderer`).
 * Every figure in it is the core's; a renderer decides only how it reads.
 */
final class MileageClaim {
	/**
	 * `$lines` is oldest first. On each, `kilometres` is null where the driver stated none, `rate`
	 * is null where the table does not cover the trip's day, and `amount` in cents is null when
	 * either is. `missing` names the fields the ruleset requires and the trip leaves unstated, and
	 * `reconciled` marks a Reconciliation Trip; either keeps the line out of the sums, amount or
	 * not. Either may be absent, which means neither.
	 * `$total` and `$kilometres` count only the lines that remain with an amount, and are null when
	 * none does: "not stated", never a claim of nothing.
	 *
	 * @param int $year the local calendar year the trips set off in (docs/architecture.md#time)
	 * @param list<array{trip: Trip, kilometres: ?int, rate: ?int, amount: ?int, missing?: list<string>, reconciled?: bool}> $lines
	 * @param ?int $total cents of the jurisdiction's currency
	 * @param string $sourceUrl where the rate is written down
	 */
	public function __construct(
		public readonly Vehicle $vehicle,
		public readonly int $year,
		public readonly array $lines,
		public readonly ?int $total,
		public readonly ?int $kilometres,
		public readonly string $sourceUrl,
	) {
	}
}
