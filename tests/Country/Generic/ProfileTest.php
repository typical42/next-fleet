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
}
