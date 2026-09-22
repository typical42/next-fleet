<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\Generic;

use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Jurisdiction\IServiceTemplates;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;

/**
 * Common intervals, for a vehicle whose manufacturer nobody has written up. A starting point the
 * sheet lets the user change, not a manufacturer's figure.
 */
class ServiceTemplates implements IServiceTemplates {
	public function all(): array {
		return [
			new ReminderTemplate('oil_change', Reminder::EITHER, 12, 15000, 1000),
			new ReminderTemplate('brake_fluid', Reminder::DATE, 24, null, null),
			new ReminderTemplate('tyre_swap', Reminder::DATE, 6, null, null),
		];
	}
}
