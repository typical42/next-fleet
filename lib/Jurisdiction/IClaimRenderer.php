<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * How one country prints a mileage claim the core has already valued, on the terms
 * `IReportRenderer` sets for a logbook: printable HTML out, nothing loaded, every value text.
 *
 * Internal seam, not a public API - see docs/contributing.md.
 */
interface IClaimRenderer {
	/** One whole HTML document, doctype first. */
	public function render(MileageClaim $claim): string;
}
