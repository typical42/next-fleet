<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Service\ReminderService;
use PHPUnit\Framework\TestCase;

/** What ReminderService::keep() leaves on a record that already kept other values. */
class KeepTest extends TestCase {
	private static function keptBefore(): Maintenance {
		return Maintenance::fromRow([
			'closed_due_date' => '2027-03-01', 'closed_due_odo' => '60000', 'closed_snoozed_until' => '2031-02-20',
			'closed_occurrence' => '2', 'next_snoozed_until' => '2031-04-02', 'handed_to' => '12', 'kept_occurrence' => '1',
			'next_due_date' => '2028-02-10', 'next_due_odo' => '73500', 'took_back' => '0',
			'done_at' => (string)strtotime('2032-04-25 10:00 UTC'), 'done_at_off' => '0', 'odo' => '88000',
		]);
	}

	public function testAClosingRecordKeepsTheReminderAsItStands(): void {
		$reminder = new Reminder();
		$reminder->setDueDate('2032-05-01');
		$reminder->setDueOdo(90000);
		$reminder->setSnoozedUntil('2032-04-20');
		$reminder->setOccurrence(4);
		$record = self::keptBefore();

		ReminderService::keep($record, $reminder);

		$this->assertSame('2032-05-01', $record->getClosedDueDate()?->format('Y-m-d'));
		$this->assertSame(90000, $record->getClosedDueOdo());
		$this->assertSame('2032-04-20', $record->getClosedSnoozedUntil()?->format('Y-m-d'));
		$this->assertSame(4, $record->getClosedOccurrence());
		$this->assertNull($record->getNextSnoozedUntil());
		$this->assertNull($record->getHandedTo());
		$this->assertNull($record->getKeptOccurrence());
		$this->assertNull($record->getTookBack());
		// A one-off sets no next due.
		$this->assertNull($record->getNextDueDate());
		$this->assertNull($record->getNextDueOdo());
	}

	/**
	 * The due the close sets: what advance() gives from the record's day and counter, on each axis
	 * it recurs by. It keeps a due on each axis the reminder has, here the same ones (the null
	 * rule, ClosedDue).
	 */
	public function testAClosingRecordKeepsTheDueItsCloseSets(): void {
		$reminder = new Reminder();
		$reminder->setDueDate('2032-05-01');
		$reminder->setRecurMonths(12);
		$reminder->setOccurrence(4);
		$record = self::keptBefore();

		ReminderService::keep($record, $reminder);

		$this->assertSame('2033-04-25', $record->getNextDueDate()?->format('Y-m-d'));
		$this->assertNull($record->getNextDueOdo());
		$this->assertNull($record->getClosedDueOdo());

		$reminder->setDueOdo(90000);
		$reminder->setRecurOdo(15000);
		ReminderService::keep($record, $reminder);

		$this->assertSame('2033-04-25', $record->getNextDueDate()?->format('Y-m-d'));
		$this->assertSame(103000, $record->getNextDueOdo());
	}

	public function testARecordThatClosesNoReminderKeepsNothing(): void {
		$record = self::keptBefore();

		ReminderService::keep($record, null);

		$this->assertNull($record->getClosedDueDate());
		$this->assertNull($record->getClosedDueOdo());
		$this->assertNull($record->getClosedSnoozedUntil());
		$this->assertNull($record->getClosedOccurrence());
		$this->assertNull($record->getNextSnoozedUntil());
		$this->assertNull($record->getHandedTo());
		$this->assertNull($record->getKeptOccurrence());
		$this->assertNull($record->getTookBack());
		$this->assertNull($record->getNextDueDate());
		$this->assertNull($record->getNextDueOdo());
	}
}
