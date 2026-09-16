<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * How one country prints a logbook the core has already read: range in, printable HTML out, and the
 * browser makes the PDF (docs/adr/0005-no-pdf-library.md). What is required is `ILogbookRules`'s;
 * this only lays it out.
 *
 * Internal seam, not a public API - see docs/contributing.md. The page inlines its own styles and
 * references nothing it would have to load (docs/security.md#hostile-content), and every value on
 * it is printed as text: each one came from a person.
 */
interface IReportRenderer {
	/** One whole HTML document, doctype first. */
	public function render(LogbookReport $report): string;
}
