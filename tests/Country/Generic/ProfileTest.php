<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\Generic;

use OCA\NextFleet\Jurisdiction\Generic;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Tests\Country\JurisdictionTestCase;
use OCA\NextFleet\Tests\Stub\Untranslated;

/**
 * The profile that answers "I don't know" about currency. It takes the same kit as every other
 * country, which is the point: a caller meets an incomplete answer here first.
 */
class ProfileTest extends JurisdictionTestCase {
	public static function profile(): IJurisdiction {
		return new Generic\Profile(new Untranslated());
	}

	/** A plain trip listing (docs/features.md#logbook-mode): no logbook is required, but one prints. */
	public function testItPrintsAPlainLogbook(): void {
		$this->assertInstanceOf(Generic\LogbookRenderer::class, static::profile()->logbookRenderer());
	}

	/**
	 * No logbook ruleset (CONTEXT.md, Generic Jurisdiction), which is not the same as an empty
	 * one: under it Logbook Mode still keeps trips append-only and audited, it simply requires
	 * no fields. A ruleset here would be this app inventing a country's law.
	 */
	public function testItRequiresNoLogbookOfItsOwn(): void {
		$this->assertNull(static::profile()->logbookRules());
	}

	/**
	 * No rates either (docs/contributing.md): a VAT field under it starts empty, "not stated",
	 * rather than at a rate some other country charges.
	 */
	public function testItStatesNoRates(): void {
		$this->assertNull(static::profile()->rates());
	}

	/** No inspection scheme either (CONTEXT.md): no vehicle under it is asked when one is due. */
	public function testItStatesNoInspectionScheme(): void {
		$this->assertNull(static::profile()->inspectionScheme());
	}
}
