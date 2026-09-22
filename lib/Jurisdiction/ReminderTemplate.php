<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * What a Reminder made from a template starts with (CONTEXT.md, Reminder Template). The due date
 * or km is not in it: that is the vehicle's, and the sheet asks for it.
 */
final class ReminderTemplate {
	/**
	 * @param string $key what `fleet_reminders.template_key` holds, and what the title translates from
	 * @param string $mode `Reminder::DATE`, `ODO` or `EITHER`
	 * @param ?int $recurMonths months to the next occurrence, or null where time does not count
	 * @param ?int $recurOdo km to the next occurrence, or null where the counter does not count
	 * @param ?int $leadOdo km before `due_odo` it warns at
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $mode,
		public readonly ?int $recurMonths,
		public readonly ?int $recurOdo,
		public readonly ?int $leadOdo,
	) {
	}
}
