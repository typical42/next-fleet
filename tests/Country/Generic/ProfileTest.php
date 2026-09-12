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

/**
 * The profile that answers "I don't know" about currency. It takes the same kit as every other
 * country, which is the point: a caller meets an incomplete answer here first.
 */
class ProfileTest extends JurisdictionTestCase {
	public static function profile(): IJurisdiction {
		return new Generic\Profile();
	}

	/**
	 * No logbook ruleset (CONTEXT.md, Generic Jurisdiction), which is not the same as an empty
	 * one: under it Logbook Mode still keeps trips append-only and audited, it simply requires
	 * no fields. A ruleset here would be this app inventing a country's law.
	 */
	public function testItRequiresNoLogbookOfItsOwn(): void {
		$this->assertNull(static::profile()->logbookRules());
	}
}
