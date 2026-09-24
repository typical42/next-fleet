<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCA\NextFleet\Service\ReminderEngine;
use OCP\IL10N;

/**
 * What a reached point says, without the plate: the notification puts the plate in front, the
 * mail digest groups the lines under it.
 */
class ReminderWords {
	/**
	 * @param array{template_key: ?string, title: ?string, due_date: ?string, due_odo: ?int, ...} $p
	 * @throws \UnexpectedValueException for a point this app does not send
	 */
	public static function line(IL10N $l, string $point, array $p): string {
		$title = self::title($l, $p);
		$date = $p['due_date'] === null ? '' : (string)$l->l('date', new \DateTime($p['due_date']), ['width' => 'medium']);
		$km = (string)$p['due_odo'];

		return match ($point) {
			ReminderEngine::MONTH_BEFORE, ReminderEngine::MONTH_START => $l->t('%1$s is due on %2$s', [$title, $date]),
			ReminderEngine::ODO => $l->t('%1$s is due at %2$s km', [$title, $km]),
			ReminderEngine::DUE_DATE => $l->t('%1$s is due today', [$title]),
			ReminderEngine::ODO_DUE => $l->t('%1$s is due', [$title]),
			ReminderEngine::OVERDUE => $l->t('%1$s is overdue (due %2$s)', [$title, $date]),
			default => throw new \UnexpectedValueException('No words for point ' . $point),
		};
	}

	/**
	 * What src/utils/reminders.js reminderTitle() calls the same reminder: a typed title is the
	 * user's, a template's translates.
	 *
	 * @param array{template_key: ?string, title: ?string, ...} $p
	 */
	public static function title(IL10N $l, array $p): string {
		return $p['title'] ?? match ((string)$p['template_key']) {
			'oil_change' => $l->t('Oil change'),
			'brake_fluid' => $l->t('Brake fluid'),
			'tyre_swap' => $l->t('Tyre swap'),
			// "HU/AU" has no English equivalent, and never "TÜV" (docs/ui.md#languages).
			'hu_au' => $l->t('Technical inspection (HU/AU)'),
			default => (string)$p['template_key'],
		};
	}
}
