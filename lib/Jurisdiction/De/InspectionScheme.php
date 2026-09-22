<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Jurisdiction\IInspectionScheme;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;

/**
 * HU/AU (§29 StVZO, Anlage VIII), never "TÜV" (docs/legal.md). The vehicle type does not carry
 * weight or use, which the law turns on, so these are defaults the vehicle sheet lets the user
 * change - not legal advice.
 */
class InspectionScheme implements IInspectionScheme {
	/** The types inspected yearly; every other one every two years. */
	private const YEARLY = ['truck', 'tractor'];

	public function template(string $vehicleType): ReminderTemplate {
		return new ReminderTemplate('hu_au', Reminder::DATE, $this->cadence($vehicleType), null, null);
	}

	/** A new car waits 36 months for its first HU; every other type its usual cadence. */
	public function firstDueMonths(string $vehicleType): int {
		return $vehicleType === 'car' ? 36 : $this->cadence($vehicleType);
	}

	private function cadence(string $vehicleType): int {
		return in_array($vehicleType, self::YEARLY, true) ? 12 : 24;
	}
}
