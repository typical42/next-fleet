<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;

/**
 * A Reminder at one day and one reading of the main chain (docs/architecture.md#reminder-engine).
 * Pure: the caller brings the day and the counter, so the job, the banner and a test all move
 * the same clock.
 */
final class ReminderEngine {
	public const MONTH_BEFORE = 'month_before';
	public const MONTH_START = 'month_start';
	public const DUE_DATE = 'due_date';
	public const ODO = 'odo';
	public const ODO_DUE = 'odo_due';
	public const OVERDUE = 'overdue';

	/** How far along an occurrence is, so "whichever comes first" is the larger. */
	private const RANK = [Reminder::PLANNED => 0, Reminder::WARNED => 1, Reminder::DUE => 2, Reminder::OVERDUE => 3];

	/** The pace looks back 90 days and needs 30 of them (rule 5). */
	private const PACE_WINDOW = 90 * 86400;
	private const PACE_FLOOR = 30 * 86400;

	/**
	 * The state the reminder is in, and the newest point it has reached — the one a notification
	 * for this state is receipted under. Null when it has reached none, or is silenced.
	 *
	 * @param string $today a plain day, YYYY-MM-DD
	 * @param ?int $odo the main chain's value, null when it has no Reading
	 * @return array{state: string, point: ?string}
	 */
	public static function evaluate(Reminder $reminder, string $today, ?int $odo): array {
		$state = $reminder->getState();
		if ($state === Reminder::DONE || $state === Reminder::DISMISSED) {
			return ['state' => $state, 'point' => null];
		}
		$until = $reminder->getSnoozedUntil()?->format('Y-m-d');
		if ($until !== null && $today < $until) {
			return ['state' => Reminder::SNOOZED, 'point' => null];
		}

		$mode = $reminder->getMode();
		$byDate = $mode !== Reminder::ODO ? self::byDate($reminder, $today) : null;
		$byOdo = $mode !== Reminder::DATE ? self::byOdo($reminder, $odo) : null;
		if ($byDate === null || $byOdo === null) {
			return $byDate ?? $byOdo ?? ['state' => Reminder::PLANNED, 'point' => null];
		}

		return self::RANK[$byOdo['state']] > self::RANK[$byDate['state']] ? $byOdo : $byDate;
	}

	/**
	 * Moves the reminder to its next occurrence, each axis counted from the base it is given: the
	 * planned due for a dismissal, the actual completion for a maintenance record (rule 4).
	 *
	 * @param ?string $fromDay a plain day, YYYY-MM-DD
	 * @return bool false, and nothing changed, when the reminder does not recur
	 */
	public static function advance(Reminder $reminder, ?string $fromDay, ?int $fromOdo): bool {
		$months = $reminder->getRecurMonths();
		$km = $reminder->getRecurOdo();
		if ($months === null && $km === null) {
			return false;
		}

		if ($months !== null && $fromDay !== null) {
			$reminder->setDueDate(new \DateTime(self::addMonths($fromDay, $months)));
		}
		if ($km !== null && $fromOdo !== null) {
			$reminder->setDueOdo($fromOdo + $km);
		}
		$reminder->setOccurrence($reminder->getOccurrence() + 1);
		$reminder->setSnoozedUntil(null);

		return true;
	}

	/**
	 * Takes back advance() from that base, when the reminder still stands where it put it: the
	 * record that moved it on was withdrawn. The planned due is not kept, so the occurrence comes
	 * back due at the base itself.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 * @return bool false, and nothing changed, when something else has moved the reminder since
	 */
	public static function retreat(Reminder $reminder, string $fromDay, ?int $fromOdo): bool {
		$advanced = clone $reminder;
		if ($reminder->getOccurrence() < 2 || !self::advance($advanced, $fromDay, $fromOdo)
			|| $advanced->getDueDate()?->format('Y-m-d') !== $reminder->getDueDate()?->format('Y-m-d')
			|| $advanced->getDueOdo() !== $reminder->getDueOdo()) {
			return false;
		}

		if ($reminder->getRecurMonths() !== null) {
			$reminder->setDueDate(new \DateTime($fromDay));
		}
		if ($reminder->getRecurOdo() !== null && $fromOdo !== null) {
			$reminder->setDueOdo($fromOdo);
		}
		$reminder->setOccurrence($reminder->getOccurrence() - 1);

		return true;
	}

	/**
	 * Whether the reminder stands where retreat() from that base left it, so an undo may advance
	 * it again.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 */
	public static function standsAt(Reminder $reminder, string $fromDay, ?int $fromOdo): bool {
		return ($reminder->getRecurMonths() === null || $reminder->getDueDate()?->format('Y-m-d') === $fromDay)
			&& ($reminder->getRecurOdo() === null || $fromOdo === null || $reminder->getDueOdo() === $fromOdo);
	}

	/**
	 * The day the pace of the main chain reaches the due km (rule 5). Display only: the state
	 * never reads it.
	 *
	 * @param list<OdoReading> $chain the main chain, in findChain()'s order
	 * @param \DateTimeImmutable $now its zone is the zone of the day returned
	 * @return ?string a plain day, YYYY-MM-DD, never before today; null without an honest pace
	 */
	public static function estimate(Reminder $reminder, array $chain, \DateTimeImmutable $now): ?string {
		$due = $reminder->getDueOdo();
		if ($reminder->getMode() === Reminder::DATE || $due === null) {
			return null;
		}

		$segment = [];
		$from = $now->getTimestamp() - self::PACE_WINDOW;
		foreach ($chain as $reading) {
			if ($reading->getFlagged() || $reading->getReadAt() < $from || $reading->getReadAt() > $now->getTimestamp()) {
				continue;
			}
			// An unflagged drop is an answered reset (odometer rule 3): the pace starts again.
			if ($segment !== [] && $reading->getValue() < end($segment)->getValue()) {
				$segment = [];
			}
			$segment[] = $reading;
		}
		if (count($segment) < 2) {
			return null;
		}
		$first = $segment[0];
		$last = end($segment);
		$span = $last->getReadAt() - $first->getReadAt();
		$driven = $last->getValue() - $first->getValue();
		$left = $due - $last->getValue();
		if ($span < self::PACE_FLOOR || $driven <= 0 || $left <= 0) {
			return null;
		}

		// Integer arithmetic: km × seconds stays far inside 64 bits.
		$at = $last->getReadAt() + intdiv($left * $span, $driven);
		$day = (new \DateTimeImmutable('@' . $at))->setTimezone($now->getTimezone())->format('Y-m-d');

		return max($day, $now->format('Y-m-d'));
	}

	/**
	 * @return ?array{state: string, point: ?string} null for a reminder without a due date
	 */
	private static function byDate(Reminder $reminder, string $today): ?array {
		$due = $reminder->getDueDate()?->format('Y-m-d');
		if ($due === null) {
			return null;
		}
		if ($today > $due) {
			return ['state' => Reminder::OVERDUE, 'point' => self::OVERDUE];
		}

		// In the order they fall, so the last one reached is the newest.
		$points = array_filter([
			self::MONTH_BEFORE => $reminder->getWarnMonthBefore() ? self::addMonths($due, -1) : null,
			self::MONTH_START => $reminder->getWarnMonthStart() ? substr($due, 0, 8) . '01' : null,
			self::DUE_DATE => $reminder->getWarnDueDate() ? $due : null,
		]);
		$reached = array_keys(array_filter($points, static fn (string $day): bool => $day <= $today));
		$point = $reached === [] ? null : end($reached);

		if ($today === $due) {
			return ['state' => Reminder::DUE, 'point' => $point];
		}

		return ['state' => $point === null ? Reminder::PLANNED : Reminder::WARNED, 'point' => $point];
	}

	/**
	 * A counter has no day after, so by km a reminder is due and stays due; overdue is the date's.
	 *
	 * @return ?array{state: string, point: ?string} null for a reminder without a due km
	 */
	private static function byOdo(Reminder $reminder, ?int $odo): ?array {
		$due = $reminder->getDueOdo();
		if ($due === null) {
			return null;
		}
		$lead = $reminder->getLeadOdo() ?? 0;

		return match (true) {
			$odo === null => ['state' => Reminder::PLANNED, 'point' => null],
			$odo >= $due => ['state' => Reminder::DUE, 'point' => self::ODO_DUE],
			$lead > 0 && $odo >= $due - $lead => ['state' => Reminder::WARNED, 'point' => self::ODO],
			default => ['state' => Reminder::PLANNED, 'point' => null],
		};
	}

	/**
	 * Calendar months on a plain day. A day past the target month's end clamps to it, and the last
	 * day of a month lands on the last day of the target: a HU/AU is due at a month's end, and
	 * would otherwise creep back from the 31st to the 28th.
	 */
	private static function addMonths(string $day, int $months): string {
		[$year, $month, $date] = array_map('intval', explode('-', $day));
		$index = $year * 12 + $month - 1 + $months;
		$toYear = intdiv($index, 12);
		$toMonth = $index % 12 + 1;
		$fromLength = self::length($year, $month);
		$toLength = self::length($toYear, $toMonth);
		$toDate = $date === $fromLength ? $toLength : min($date, $toLength);

		return sprintf('%04d-%02d-%02d', $toYear, $toMonth, $toDate);
	}

	/** Days in a month, without the calendar extension a Nextcloud image need not have. */
	private static function length(int $year, int $month): int {
		return (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
	}
}
