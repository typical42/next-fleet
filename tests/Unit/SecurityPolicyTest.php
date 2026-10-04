<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A third-party app is outside Nextcloud's bug bounty, so SECURITY.md is the only way a
 * reporter reaches us. A placeholder there would ship as a dead end.
 */
class SecurityPolicyTest extends TestCase {
	private const FILE = __DIR__ . '/../../SECURITY.md';

	public function testItCarriesNoPlaceholder(): void {
		$policy = (string)file_get_contents(self::FILE);

		$this->assertStringNotContainsString('TODO', $policy);
		$this->assertStringNotContainsString('example.org', $policy);
	}

	public function testItPointsAtPrivateVulnerabilityReporting(): void {
		$this->assertStringContainsString(
			'https://github.com/typical42/next-fleet/security/advisories/new',
			(string)file_get_contents(self::FILE),
		);
	}
}
