<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\De;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Jurisdiction\De;
use PHPUnit\Framework\TestCase;

/**
 * What Germany answers, as opposed to that it answers at all - which is
 * `JurisdictionTestCase`'s question, asked of every country
 * (docs/contributing.md#what-a-country-owes-us).
 *
 * Each figure here is a legal one and carries the source it came from, never the reasoning that
 * would justify it: this app is a compliance aid and no lawyer has read it (docs/legal.md).
 */
class LogbookRulesTest extends TestCase {
	/**
	 * Germany is where the ruleset is reached from: a vehicle is read under its jurisdiction, and
	 * nothing in the core names this class (docs/contributing.md#rules-that-keep-the-seam-honest).
	 */
	public function testGermanyHandsOutTheRuleset(): void {
		$this->assertInstanceOf(De\LogbookRules::class, (new De\Profile())->logbookRules());
	}

	/**
	 * The list docs/features.md#logbook-mode states: plate, date, both counters, the full
	 * destination, the purpose and the business partner visited.
	 */
	public function testABusinessTripStatesWhereItWentWhyAndToWhom(): void {
		$this->assertSame(
			['plate', 'started_at', 'start_odo', 'end_odo', 'to_label', 'purpose', 'partner'],
			(new De\LogbookRules())->mandatoryFields(Trip::BUSINESS),
		);
	}

	/**
	 * A commute is a journey between home and work, and the law asks what it is rather than why:
	 * the category is the note, so no destination, purpose or partner is required of it.
	 */
	public function testACommuteTripStatesTheJourneyAndNotItsReason(): void {
		$this->assertSame(
			['plate', 'started_at', 'start_odo', 'end_odo'],
			(new De\LogbookRules())->mandatoryFields(Trip::COMMUTE),
		);
	}

	/**
	 * "Private trips need only the kilometres" (docs/features.md#logbook-mode) - and a trip
	 * stating neither a counter nor a distance is never written in the first place
	 * (`TripService::apply()`), so the kilometres are already there. The empty list is the
	 * answer, not a gap: a private trip is complete the moment it is saved.
	 */
	public function testAPrivateTripAsksForNothingTheCoreDoesNotAlreadyDemand(): void {
		$this->assertSame([], (new De\LogbookRules())->mandatoryFields(Trip::PRIVATE));
	}

	/**
	 * Timeliness is the point German case law turns on, and a week is the span the practice
	 * literature reads "zeitnah" as - days, not weeks (docs/features.md#logbook-mode). A later
	 * edit is still written; it is written as a late one.
	 */
	public function testAnEntryStaysTimelyForAWeek(): void {
		$this->assertSame(7, (new De\LogbookRules())->lockDelayDays());
	}

	/**
	 * A Fahrtenbuch is an Aufzeichnung under section 147 AO, which keeps the ten years the
	 * shortened period for Buchungsbelege left behind. It is a floor under the vehicle's own
	 * `retention_months` (docs/legal.md), never an instruction to delete anything.
	 */
	public function testItKeepsARecordForTenYears(): void {
		$this->assertSame(120, (new De\LogbookRules())->retentionMonths());
	}

	/**
	 * The export carries this in its footer, so an auditor can read the requirement rather than
	 * take the app's word for it. The statute itself, not an article about it: a secondary source
	 * moves, and one that moves in a printed logbook cannot be corrected.
	 */
	public function testItNamesTheRequirementItClaimsToMeet(): void {
		$this->assertSame(
			'https://www.gesetze-im-internet.de/estg/__6.html',
			(new De\LogbookRules())->sourceUrl(),
		);
	}
}
