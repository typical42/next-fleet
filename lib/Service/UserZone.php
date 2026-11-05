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
 * an import reads without a time. Or where the browser's word is not enough: the midnight a take-now
 * is back by, which a client could stretch with any offset.
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

		return self::named($zone) ?? new \DateTimeZone('UTC');
	}

	/** The zone the user set, if PHP knows it: where a caller has a better guess than the server's. */
	public function own(string $userId): ?\DateTimeZone {
		return self::named($this->config->getUserValue($userId, 'core', 'timezone', ''));
	}

	private static function named(string $zone): ?\DateTimeZone {
		if ($zone === '') {
			return null;
		}
		try {
			return new \DateTimeZone($zone);
		} catch (\Exception) {
			return null;
		}
	}
}
