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
	public const PACE_WINDOW = 90 * 86400;
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
	 * planned due for a dismissal, the day and counter of the work for a maintenance record
	 * (rule 4).
	 *
	 * @param ?string $fromDay a plain day, YYYY-MM-DD
	 * @return bool false, and nothing changed, when the reminder does not recur
	 */
	public static function advance(Reminder $reminder, ?string $fromDay, ?int $fromOdo): bool {
		if (!self::recurs($reminder)) {
			return false;
		}

		self::setDue($reminder, ...self::nextDue($reminder, $fromDay, $fromOdo));
		$reminder->setOccurrence($reminder->getOccurrence() + 1);
		$reminder->setSnoozedUntil(null);

		return true;
	}

	/**
	 * The points an edit moved, in evaluate()'s order: each one falls on another day or km now, or
	 * was ticked on or off. A receipt for one of them names a point that is no longer where it was.
	 *
	 * @return list<string>
	 */
	public static function moved(Reminder $was, Reminder $is): array {
		$day = $was->getDueDate()?->format('Y-m-d') !== $is->getDueDate()?->format('Y-m-d');
		$km = $was->getDueOdo() !== $is->getDueOdo();

		return array_keys(array_filter([
			self::MONTH_BEFORE => $day || $was->getWarnMonthBefore() !== $is->getWarnMonthBefore(),
			self::MONTH_START => $day || $was->getWarnMonthStart() !== $is->getWarnMonthStart(),
			self::DUE_DATE => $day || $was->getWarnDueDate() !== $is->getWarnDueDate(),
			self::OVERDUE => $day,
			self::ODO => $km || $was->getLeadOdo() !== $is->getLeadOdo(),
			self::ODO_DUE => $km,
		]));
	}

	/**
	 * Whether the point is on the km axis: the warning by km and the due km. The others are on the
	 * date. A receipt is for the due on its point's own axis (ReminderReceiptMapper::claim()).
	 */
	public static function byKm(string $point): bool {
		return $point === self::ODO || $point === self::ODO_DUE;
	}

	/**
	 * Takes back the close from that base, when the reminder stands where it left it (onTop()). Each
	 * axis that recurs comes back to the due the record kept, or to the base for a record from
	 * before; an axis the record kept nothing for stays. The snooze is the later of the kept one and the reminder's own, so
	 * both wishes hold. The occurrence comes back to the one of the kept due (ClosedDue::returnsTo()),
	 * or one less for a record from before. The number is reused as it was: a receipt of that
	 * occurrence counts only for the due it was sent for (ReminderReceiptMapper::claim()).
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 * @return bool false, and nothing changed, when the close is not on top
	 */
	public static function retreat(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed): bool {
		if (!self::onTop($reminder, $fromDay, $fromOdo, $closed)) {
			return false;
		}

		self::setDue($reminder, ...self::putBack($reminder, $fromDay, $fromOdo, $closed));
		// Plain days sort as strings; null sorts first, so it counts as no snooze.
		$snoozed = max($closed->snoozedUntil, $reminder->getSnoozedUntil()?->format('Y-m-d'));
		$reminder->setSnoozedUntil($snoozed === null ? null : new \DateTime($snoozed));
		$reminder->setOccurrence($closed->returnsTo() ?? $reminder->getOccurrence() - 1);

		return true;
	}

	/**
	 * Closes the reminder again where the close put it, for an undo once standsAt() holds: the due
	 * the record stored, on each axis it set that the reminder still recurs by, and the occurrence
	 * after the one it closed. An axis it set nothing for stays as entered. The interval and the
	 * record's counter play no part, so an interval changed since does not move the due.
	 *
	 * A one-off has no next due. Its close leaves the occurrence the record closed, so it goes back
	 * there: standsAt() also finds it at an earlier one, where a retreat left it before it stopped
	 * recurring.
	 *
	 * @return bool false for a one-off, which the close makes done
	 * @throws \LogicException for a record from before, which stored no due: it closes again from
	 *                         its own day and counter (ReminderService::closeBy())
	 */
	public static function reclose(Reminder $reminder, ClosedDue $closed): bool {
		if ($closed->occurrence === null) {
			// Not false: the caller reads false as done, and a recurring reminder would end.
			throw new \LogicException('a record from before stored no due to close again at');
		}
		if (!self::recurs($reminder)) {
			$reminder->setOccurrence($closed->occurrence);

			return false;
		}

		self::setDue($reminder, ...self::stored($reminder, $closed));
		$reminder->setOccurrence($closed->occurrence + 1);
		$reminder->setSnoozedUntil(null);

		return true;
	}

	/**
	 * Moves the close from one base to another, when it is on top: the record's day or counter was
	 * corrected. Each axis the close set is counted from the new base (rebase()); the occurrence and
	 * its snooze stay, so a snooze set on it is not lost.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 * @param ?string $toDay a plain day, YYYY-MM-DD; null, as a null km, leaves that axis
	 * @return bool false, and nothing changed, when the close is not on top
	 */
	public static function move(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed, ?string $toDay, ?int $toOdo): bool {
		if (!self::onTop($reminder, $fromDay, $fromOdo, $closed)) {
			return false;
		}
		self::setDue($reminder, ...self::gave($reminder, $toDay, $toOdo, self::rebase($reminder, $closed, $toDay, $toOdo)));

		return true;
	}

	/**
	 * The close as it stands once counted from another base: on each axis it set, the due advance()
	 * from that base gives with the interval as it is now, for the person corrected the record. An
	 * axis the reminder no longer recurs by, or given no base, keeps what the close set. A record
	 * from before stored no due, so it stays as it is.
	 *
	 * @param ?string $toDay a plain day, YYYY-MM-DD
	 */
	public static function rebase(Reminder $reminder, ClosedDue $closed, ?string $toDay, ?int $toOdo): ClosedDue {
		if ($closed->occurrence === null) {
			return $closed;
		}
		[$day, $odo] = self::nextDue($reminder, $toDay, $toOdo);

		return new ClosedDue(
			$closed->day, $closed->odo, $closed->snoozedUntil, $closed->occurrence, $closed->keptOccurrence,
			$closed->nextDay === null ? null : ($day ?? $closed->nextDay),
			$closed->nextOdo === null ? null : ($odo ?? $closed->nextOdo),
		);
	}

	/**
	 * Whether the reminder stands where the close left it, the reminder's half of on top
	 * (ReminderService::onTop() adds the other records): at the due that close set (gave()), and for
	 * a record that kept its occurrence, at the occurrence that close started. A close that set
	 * no axis the reminder still recurs by has nothing to tell, so it is not on top. Nor is one
	 * under an occurrence that is over: it was dismissed, or done while the reminder did not recur,
	 * after the close. A record from before has no occurrence, so of two closes from the same base
	 * its close counts as on top.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 */
	public static function onTop(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed): bool {
		if (!self::recurs($reminder) || self::over($reminder) || $reminder->getOccurrence() < 2
			|| ($closed->occurrence !== null && $reminder->getOccurrence() !== $closed->occurrence + 1)) {
			return false;
		}

		return ($closed->occurrence === null || self::tells($reminder, $closed)) && self::holds($reminder, ...self::gave($reminder, $fromDay, $fromOdo, $closed));
	}

	/**
	 * Whether the close set a due on an axis the reminder recurs by, so the reminder's due tells
	 * whether it still stands where the close left it.
	 */
	public static function tells(Reminder $reminder, ClosedDue $closed): bool {
		return self::stored($reminder, $closed) !== [null, null];
	}

	/**
	 * The close once the reminder recurs by an axis the record kept a due on but its close set none
	 * on, as it was a one-off then: that axis is counted from the record's day or counter, as a new
	 * close counts it (advance()). Every other value stays, so a due the close set is not counted
	 * again with an interval changed since. A record from before stored no due, so it stays as it is.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 */
	public static function recurred(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed): ClosedDue {
		if ($closed->occurrence === null) {
			return $closed;
		}
		[$day, $odo] = self::nextDue($reminder, $fromDay, $fromOdo);

		return new ClosedDue(
			$closed->day, $closed->odo, $closed->snoozedUntil, $closed->occurrence, $closed->keptOccurrence,
			$closed->nextDay ?? ($closed->day === null ? null : $day),
			$closed->nextOdo ?? ($closed->odo === null ? null : $odo),
		);
	}

	/**
	 * Whether the record that closed the next occurrence kept the due this close set, and returns to
	 * the occurrence this close started. Then it is the record above this one, and taking this close
	 * back hands what this record kept to it. A dismissal or a hand edit in between gives another
	 * due or occurrence. An axis is compared only where both records hold a value: a record above
	 * that kept none had none to keep, as the reminder lacked that axis when it closed, whatever the
	 * reminder holds now. At least one axis must be compared, also one the reminder no longer
	 * recurs by: both records were written while it did. A record from before stored nothing, so no
	 * record follows it. Nor does a one-off's close, which set no due: taking it back reopens its own
	 * occurrence and never returns to this one.
	 *
	 * @param ClosedDue $closed what this record kept
	 * @param ClosedDue $above what the other record kept
	 */
	public static function follows(ClosedDue $closed, ClosedDue $above): bool {
		if ($closed->occurrence === null || $above->returnsTo() !== $closed->occurrence + 1
			|| ($above->nextDay === null && $above->nextOdo === null)) {
			return false;
		}

		return (($closed->nextDay !== null && $above->day !== null) || ($closed->nextOdo !== null && $above->odo !== null))
			&& ($closed->nextDay === null || $above->day === null || $above->day === $closed->nextDay)
			&& ($closed->nextOdo === null || $above->odo === null || $above->odo === $closed->nextOdo);
	}

	/**
	 * What the record above keeps once the close below it comes back, or is counted from another
	 * base (rebase()): the due that close set, on each axis it set, also one the reminder no longer
	 * recurs by, where the record above held that due before it was handed one. An axis the record
	 * above kept nothing for, as the reminder had none when it closed, stays without. Every other
	 * value stays as that record kept it, the occurrence of its due too.
	 *
	 * @param ClosedDue $closed what the lower record kept
	 * @param ClosedDue $above what the record above kept
	 */
	public static function keptAbove(ClosedDue $closed, ClosedDue $above): ClosedDue {
		return new ClosedDue(
			$above->day === null ? null : $closed->nextDay ?? $above->day,
			$above->odo === null ? null : $closed->nextOdo ?? $above->odo,
			$above->snoozedUntil, $above->occurrence, $above->keptOccurrence, $above->nextDay, $above->nextOdo,
		);
	}

	/**
	 * The due the close set, on each axis the reminder recurs by: the one the record stored, or for
	 * a record from before, which stored none, the one advance() gives from its base with the
	 * interval as it is now.
	 *
	 * @param ?string $fromDay a plain day, YYYY-MM-DD
	 * @return array{?string, ?int} a plain day, YYYY-MM-DD, and a km; null where it set none
	 */
	private static function gave(Reminder $reminder, ?string $fromDay, ?int $fromOdo, ClosedDue $closed): array {
		return $closed->occurrence === null ? self::nextDue($reminder, $fromDay, $fromOdo) : self::stored($reminder, $closed);
	}

	/**
	 * The due the record stored for its close, on each axis the reminder recurs by. Null where the
	 * close set none: the reminder did not recur by that axis then, or no longer does.
	 *
	 * @return array{?string, ?int} a plain day, YYYY-MM-DD, and a km
	 */
	private static function stored(Reminder $reminder, ClosedDue $closed): array {
		return [$reminder->getRecurMonths() !== null ? $closed->nextDay : null, $reminder->getRecurOdo() !== null ? $closed->nextOdo : null];
	}

	/**
	 * The due advance() from that base gives, on each axis the reminder recurs by. Null on an
	 * axis it does not recur by, or has no base for.
	 *
	 * @param ?string $fromDay a plain day, YYYY-MM-DD
	 * @return array{?string, ?int} a plain day, YYYY-MM-DD, and a km
	 */
	public static function nextDue(Reminder $reminder, ?string $fromDay, ?int $fromOdo): array {
		$months = $reminder->getRecurMonths();
		$km = $reminder->getRecurOdo();

		return [
			$months !== null && $fromDay !== null ? self::addMonths($fromDay, $months) : null,
			$km !== null && $fromOdo !== null ? $fromOdo + $km : null,
		];
	}

	/**
	 * Whether the reminder stands where retreat() with the same arguments left it, so an undo may
	 * close it again: at the due and, for a record that kept its occurrence, the occurrence retreat()
	 * put back. A one-off's close does not move the occurrence, nor does taking it back, so one that
	 * does not recur also stands at the occurrence the record closed.
	 *
	 * @param string $fromDay a plain day, YYYY-MM-DD
	 */
	public static function standsAt(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed): bool {
		$occurrence = $reminder->getOccurrence();

		return ($closed->occurrence === null || $occurrence === $closed->returnsTo() || (!self::recurs($reminder) && $occurrence === $closed->occurrence))
			&& self::holds($reminder, ...self::putBack($reminder, $fromDay, $fromOdo, $closed));
	}

	/**
	 * The due retreat() puts back on each axis the reminder recurs by, so standsAt() checks the
	 * very same one: the kept due, or the base for a record from before. Null leaves the axis as
	 * the reminder holds it: one the record kept nothing for, as the reminder did not have it.
	 *
	 * @return array{?string, ?int} a plain day, YYYY-MM-DD, and a km
	 */
	private static function putBack(Reminder $reminder, string $fromDay, ?int $fromOdo, ClosedDue $closed): array {
		[$day, $odo] = $closed->occurrence === null ? [$fromDay, $fromOdo] : [$closed->day, $closed->odo];

		return [$reminder->getRecurMonths() !== null ? $day : null, $reminder->getRecurOdo() !== null ? $odo : null];
	}

	/**
	 * Whether the reminder is due there on each axis given; an axis given null is not checked.
	 *
	 * @param ?string $day a plain day, YYYY-MM-DD
	 */
	private static function holds(Reminder $reminder, ?string $day, ?int $odo): bool {
		return ($day === null || $reminder->getDueDate()?->format('Y-m-d') === $day)
			&& ($odo === null || $reminder->getDueOdo() === $odo);
	}

	/** Whether its occurrence is over, done or dismissed, so there is nothing left to silence, skip or close. */
	public static function over(Reminder $reminder): bool {
		return in_array($reminder->getState(), [Reminder::DONE, Reminder::DISMISSED], true);
	}

	/** Whether the reminder comes back after a close; a one-off is done by it. */
	public static function recurs(Reminder $reminder): bool {
		return $reminder->getRecurMonths() !== null || $reminder->getRecurOdo() !== null;
	}

	/**
	 * An axis given null stays as it is.
	 *
	 * @param ?string $day a plain day, YYYY-MM-DD
	 */
	private static function setDue(Reminder $reminder, ?string $day, ?int $odo): void {
		if ($day !== null) {
			$reminder->setDueDate(new \DateTime($day));
		}
		if ($odo !== null) {
			$reminder->setDueOdo($odo);
		}
	}

	/**
	 * The day the pace of the main chain reaches the due km (rule 5). Display only: the state
	 * never reads it.
	 *
	 * @param list<OdoReading> $chain the main chain, in findChain()'s order, at least over the
	 *                                last PACE_WINDOW
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
