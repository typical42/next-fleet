<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\UserZone;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class UserZoneTest extends TestCase {
	/** @return array<string, array{string, string, string}> */
	public static function settings(): array {
		return [
			'the user\'s own' => ['Europe/Berlin', 'America/New_York', 'Europe/Berlin'],
			'the server\'s when the user set none' => ['', 'America/New_York', 'America/New_York'],
			// A client stores what the browser reports; the mail still goes out.
			'UTC for a name PHP does not know' => ['Mars/Olympus', 'America/New_York', 'UTC'],
		];
	}

	/** @dataProvider settings */
	public function testTheZoneIsTheUsersElseTheServers(string $user, string $server, string $expected): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('anna', 'core', 'timezone', '')->willReturn($user);
		$config->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn($server);

		$this->assertSame($expected, (new UserZone($config))->of('anna')->getName());
	}
}
