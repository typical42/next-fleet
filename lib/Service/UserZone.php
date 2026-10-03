<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCP\IConfig;

/**
 * Where a user lives, for work done without their browser to ask: the reminder mail's hour, a day
 * an import reads without a time.
 */
class UserZone {
	public function __construct(
		private IConfig $config,
	) {
	}

	/** The user's own zone, else the server's. */
	public function of(string $userId): \DateTimeZone {
		$zone = $this->config->getUserValue($userId, 'core', 'timezone', '');
		if ($zone === '') {
			$zone = $this->config->getSystemValueString('default_timezone', 'UTC');
		}
		try {
			return new \DateTimeZone($zone);
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}
}
