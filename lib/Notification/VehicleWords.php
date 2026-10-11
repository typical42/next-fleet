<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCA\NextFleet\Db\Vehicle;
use OCP\IL10N;

class VehicleWords {
	/** src/utils/format.js `nameOf`. */
	public static function name(IL10N $l, Vehicle $vehicle): string {
		return self::nameOr($vehicle, $l->t('Unnamed vehicle'));
	}

	/** name() for a page or file whose words are not the reader's: `$unnamed` when it has none. */
	public static function nameOr(Vehicle $vehicle, string $unnamed): string {
		foreach ([$vehicle->getPlate(), $vehicle->getDisplayName(), self::made($vehicle)] as $name) {
			if (($name ?? '') !== '') {
				return (string)$name;
			}
		}

		return $unnamed;
	}

	/** src/utils/format.js `madeOf`: make and model, or an empty string. */
	public static function made(Vehicle $vehicle): string {
		return trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));
	}
}
