<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Jurisdiction\ILogbookRules;

/**
 * What the German tax authorities require of a Fahrtenbuch, as far as this app can state it. Every
 * figure is cited by URL rather than quoted (docs/contributing.md), and none of it is legal advice:
 * the app is a compliance aid that no lawyer has reviewed (docs/legal.md).
 */
class LogbookRules implements ILogbookRules {
	/**
	 * A business trip states plate, date, both counters, the full destination, the purpose and
	 * the partner visited (docs/features.md#logbook-mode). `started_at` is the date; the app
	 * writes both ends of a journey, and the requirement is met by the one it began on.
	 */
	private const BUSINESS = ['plate', 'started_at', 'start_odo', 'end_odo', 'to_label', 'purpose', 'partner'];

	/** A commute is asked what it was, not why it was: its category is the whole reason. */
	private const COMMUTE = ['plate', 'started_at', 'start_odo', 'end_odo'];

	/**
	 * A private trip needs only its kilometres, which the core refuses to write a trip without
	 * (`TripService::apply()`) - so does an unknown category, which nobody here has ruled on.
	 */
	public function mandatoryFields(string $category): array {
		return match ($category) {
			Trip::BUSINESS => self::BUSINESS,
			Trip::COMMUTE => self::COMMUTE,
			default => [],
		};
	}

	/**
	 * A week, which is what the practice literature reads "zeitnah" as. What a correction after
	 * it costs is the point of the mode:
	 * https://www.haufe.de/personal/entgelt/nachbesserungen-im-fahrtenbuch-sind-unzulaessig_78_170740.html
	 */
	public function lockDelayDays(): int {
		return 7;
	}

	/**
	 * Ten years. A Fahrtenbuch is an Aufzeichnung under section 147 Abs. 1 Nr. 1 AO, which the
	 * shortening of the period for Buchungsbelege did not touch:
	 * https://www.gesetze-im-internet.de/ao_1977/__147.html
	 */
	public function retentionMonths(): int {
		return 120;
	}

	/**
	 * Section 6 Abs. 1 Nr. 4 EStG, where a logbook is what a business vehicle's private share may
	 * be proved with. An employee's company car is section 8 Abs. 2 EStG and reads the same way;
	 * one link is what fits in a footer, and both sit in the same code.
	 */
	public function sourceUrl(): string {
		return 'https://www.gesetze-im-internet.de/estg/__6.html';
	}
}
