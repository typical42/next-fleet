<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * What a reminder held just before a maintenance record closed it, so taking the close back can
 * put it back, and the due the close set, so the engine can tell whether the reminder still stands
 * there. Plain values, so ReminderEngine never reads the record. Null where nothing was kept: on a
 * record written before it was kept, on one that closes no reminder, and for an axis the reminder
 * did not have; the due the close set is null also on an axis it did not recur by.
 *
 * The null rule: on a record another can follow, a kept due is null exactly where the due its
 * close set is null. A recurring reminder has a due on exactly the axes it recurs by
 * (ReminderService::apply()), and its close needs the record's counter where it recurs by km. A
 * one-off keeps a due and sets none, but no record follows one (ReminderEngine::follows()). Under
 * the rule the kept due and the due the close set name the same axes; handedFrom() reads both, so
 * a hand-up and its undo hold either way.
 */
final class ClosedDue {
	/**
	 * @param ?string $day the due date, YYYY-MM-DD
	 * @param ?string $snoozedUntil a plain day, YYYY-MM-DD
	 * @param ?int $occurrence the reminder's occurrence; null marks a record written before the occurrence was kept
	 * @param ?int $keptOccurrence the occurrence of the kept due, where a record below handed it up; null means $occurrence
	 * @param ?string $nextDay the due date the close set, YYYY-MM-DD
	 * @param ?int $nextOdo the due km the close set
	 */
	public function __construct(
		public readonly ?string $day = null,
		public readonly ?int $odo = null,
		public readonly ?string $snoozedUntil = null,
		public readonly ?int $occurrence = null,
		public readonly ?int $keptOccurrence = null,
		public readonly ?string $nextDay = null,
		public readonly ?int $nextOdo = null,
	) {
	}

	/**
	 * The occurrence of the due this record keeps: the reminder returns to it when the close is
	 * taken back. Null for a record written before the occurrence was kept.
	 */
	public function returnsTo(): ?int {
		return $this->keptOccurrence ?? $this->occurrence;
	}

	/**
	 * What the record above keeps once the close below it is taken back: the due the lower record
	 * kept with its occurrence, and the later of the two snoozes, so both wishes hold. Its own
	 * occurrence and the due its own close set stay. An axis the lower record kept no due on, or
	 * its close set none on, as the reminder did not have it then, keeps this record's value: that
	 * one was entered later. Asking for both lets ReminderEngine::keptAbove() undo it exactly, with
	 * or without the null rule (see the class). An axis this record kept nothing for, as the
	 * reminder had lost it by its close, stays without: what the lower record kept there belongs to
	 * an axis the person removed.
	 */
	public function handedFrom(self $below): self {
		return new self(
			$this->day === null || $below->nextDay === null ? $this->day : $below->day ?? $this->day,
			$this->odo === null || $below->nextOdo === null ? $this->odo : $below->odo ?? $this->odo,
			// Plain days sort as strings; null sorts first.
			max($below->snoozedUntil, $this->snoozedUntil), $this->occurrence, $below->returnsTo(), $this->nextDay, $this->nextOdo,
		);
	}

	/** Whether this record holds the due the lower record kept, as handedFrom() leaves it. */
	public function took(self $below): bool {
		return ($below->day === null || $below->nextDay === null || $this->day === null || $this->day === $below->day)
			&& ($below->odo === null || $below->nextOdo === null || $this->odo === null || $this->odo === $below->odo);
	}
}
