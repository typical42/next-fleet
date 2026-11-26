<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCP\IL10N;

/** The budget's warning in words, for the notification and the mail alike. */
class BudgetWords {
	/** @param int $share BudgetWarnings::SHARES */
	public static function line(IL10N $l, int $year, int $share): string {
		return $share >= 100
			? $l->t('The budget for %s is used up', [(string)$year])
			: $l->t('%1$s %% of the budget for %2$s is used', [(string)$share, (string)$year]);
	}
}
