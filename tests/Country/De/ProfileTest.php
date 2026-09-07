<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\De;

use OCA\NextFleet\Jurisdiction\De;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Tests\Country\JurisdictionTestCase;

/** Germany takes the shared kit and adds nothing: what it answers is asserted where it is registered. */
class ProfileTest extends JurisdictionTestCase {
	public static function profile(): IJurisdiction {
		return new De\Profile();
	}
}
