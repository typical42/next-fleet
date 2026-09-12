<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * What one country answers about a vehicle registered in it: how it is named, and what a vehicle
 * created under it takes for the fields the create sheet does not ask for (docs/ui.md).
 *
 * Internal seam, not a public API - see docs/contributing.md. Plate format and document kinds
 * arrive with the feature that reads them; a profile that cannot answer says so rather than
 * guessing, and every caller has to tolerate that.
 */
interface IJurisdiction {
	/**
	 * What a vehicle's `jurisdiction` column holds, and what the registration list resolves.
	 * At most eight characters, because that is what the column is.
	 */
	public function key(): string;

	/**
	 * The country's name in English. Translated where it is shown, not here: the catalogues are
	 * the frontend's (docs/ui.md#languages), and a profile that took an `IL10N` would carry a
	 * screen's concern into the seam.
	 */
	public function displayName(): string;

	/**
	 * The `odo_unit` a new vehicle takes - `km` or `h`, never a display unit. Miles are a
	 * rendering of kilometres, not a second way to store them
	 * (docs/contributing.md#rules-that-keep-the-seam-honest).
	 */
	public function odoUnit(): string;

	/**
	 * ISO 4217, or null where the jurisdiction has none and the vehicle states its own. Null is
	 * an answer: "I don't know" has to break nothing.
	 */
	public function currency(): ?string;

	/**
	 * The ruleset a vehicle under this jurisdiction keeps its logbook by, or null where the
	 * country requires none - the generic profile's answer, and the one every caller has to
	 * tolerate. Null is not an empty ruleset: Logbook Mode still keeps trips append-only and
	 * audited under it, because that part is the core's (docs/features.md#logbook-mode).
	 */
	public function logbookRules(): ?ILogbookRules;
}
