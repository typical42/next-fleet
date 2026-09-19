<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * The rates a country sets, each by the date it applied: a 2020 receipt is read at the 2020 rate,
 * never today's (docs/contributing.md#rules-that-keep-the-seam-honest).
 *
 * Internal seam, not a public API - see docs/contributing.md. A jurisdiction that sets none has
 * no provider at all and says so with a null (`IJurisdiction::rates()`). Mileage allowance and
 * emission factors join this interface with the milestone that reads them (plan.md).
 */
interface IRateProvider {
	/**
	 * The standard VAT rate on the day `$when` falls on in its own timezone, in basis points
	 * (`1900` = 19 %), or null for a day the table does not state. Null is "not stated", never a
	 * zero rate (docs/architecture.md#data-model).
	 */
	public function vatRateAt(\DateTimeInterface $when): ?int;

	/** Where the VAT rate is written down. A URL, because a rate is linked and never quoted. */
	public function vatSourceUrl(): string;
}
