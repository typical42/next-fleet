<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * What one country requires of a logbook, and nothing about how it is kept: append-only storage,
 * revisions, voiding and the audit trail are the core's (docs/features.md#logbook-mode). A ruleset
 * answers questions; it never writes a row and never refuses one.
 *
 * Internal seam, not a public API - see docs/contributing.md. A jurisdiction that requires no
 * logbook has none of this at all and says so with a null (`IJurisdiction::logbookRules()`).
 */
interface ILogbookRules {
	/**
	 * The fields a trip of this category must state, named as the trip states them - a key of
	 * `Trip::jsonSerialize()`, or `plate` for the one required fact that lives on the vehicle.
	 * A name outside that vocabulary is a requirement nothing can measure, so the country kit
	 * refuses one.
	 *
	 * A missing field flags the trip and never refuses the save
	 * (docs/contributing.md#rules-that-keep-the-seam-honest), and a category the ruleset has
	 * never heard of requires nothing - a trip must be saveable whatever its category says.
	 *
	 * @return list<string>
	 */
	public function mandatoryFields(string $category): array;

	/**
	 * How many days after a journey an entry about it still counts as timely. An edit arriving
	 * later is allowed - the mode refuses no write - and its audit row says it was late
	 * (docs/features.md#logbook-mode).
	 */
	public function lockDelayDays(): int;

	/**
	 * How long a voided row must survive before anything may purge it. A floor under the
	 * vehicle's own `retention_months` and never an instruction to delete: retention is opt-in
	 * and off by default (docs/legal.md).
	 */
	public function retentionMonths(): int;

	/**
	 * Where the requirement is written down, for the export to cite in its footer. A URL, because
	 * a rate or a deadline is linked and never quoted (docs/contributing.md) - and because a
	 * printed logbook is read long after this release.
	 */
	public function sourceUrl(): string;
}
