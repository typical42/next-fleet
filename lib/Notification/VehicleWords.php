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
		$made = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return ($vehicle->getPlate() ?? '') !== '' ? (string)$vehicle->getPlate() : ($made !== '' ? $made : $l->t('Unnamed vehicle'));
	}
}
