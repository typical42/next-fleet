<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Service\ClosedDue;
use OCA\NextFleet\Service\ReminderEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reminder evaluated at a day and a counter reading (docs/architecture.md#reminder-engine),
 * with the clock moved across every edge. The expected days are worked out by hand from the
 * calendar, not from the code.
 */
class ReminderEngineTest extends TestCase {
	/**
	 * @param array{month_before?: bool, month_start?: bool, due_date?: bool} $points
	 */
	private static function byDate(string $due, array $points = []): Reminder {
		$reminder = new Reminder();
		$reminder->setMode(Reminder::DATE);
		$reminder->setState(Reminder::PLANNED);
		$reminder->setDueDate(new \DateTime($due));
		$reminder->setWarnMonthBefore($points['month_before'] ?? true);
		$reminder->setWarnMonthStart($points['month_start'] ?? false);
		$reminder->setWarnDueDate($points['due_date'] ?? true);

		return $reminder;
	}

	private static function byOdo(int $due, ?int $lead): Reminder {
		$reminder = new Reminder();
		$reminder->setMode(Reminder::ODO);
		$reminder->setState(Reminder::PLANNED);
		$reminder->setDueOdo($due);
		$reminder->setLeadOdo($lead);

		return $reminder;
	}

	/**
	 * @return array<string, array{string, array<string, bool>, string, string, ?string}>
	 */
	public static function dateEdges(): array {
		$default = [];

		return [
			'the day before a month before' => ['2027-03-31', $default, '2027-02-27', Reminder::PLANNED, null],
			'a month before a 31st is the last of February' => ['2027-03-31', $default, '2027-02-28', Reminder::WARNED, 'month_before'],
			'a leap February ends on the 29th' => ['2028-03-31', $default, '2028-02-28', Reminder::PLANNED, null],
			'and warns on it' => ['2028-03-31', $default, '2028-02-29', Reminder::WARNED, 'month_before'],
			'a month before a 30th that ends its month is the 31st' => ['2027-04-30', $default, '2027-03-31', Reminder::WARNED, 'month_before'],
			'the 30th of March is not the end of it' => ['2027-03-30', $default, '2027-02-28', Reminder::WARNED, 'month_before'],
			'and the 27th of February is before it' => ['2027-03-30', $default, '2027-02-27', Reminder::PLANNED, null],
			'mid-month counts back to the same day' => ['2027-03-15', $default, '2027-02-14', Reminder::PLANNED, null],
			'on that day' => ['2027-03-15', $default, '2027-02-15', Reminder::WARNED, 'month_before'],
			'a month before a January day is in December' => ['2027-01-15', $default, '2026-12-15', Reminder::WARNED, 'month_before'],
			'still warned the day before' => ['2027-03-31', $default, '2027-03-30', Reminder::WARNED, 'month_before'],
			'due on the due date' => ['2027-03-31', $default, '2027-03-31', Reminder::DUE, 'due_date'],
			'overdue the day after' => ['2027-03-31', $default, '2027-04-01', Reminder::OVERDUE, 'overdue'],
			'overdue across the year' => ['2027-12-31', $default, '2028-01-01', Reminder::OVERDUE, 'overdue'],
			'and it stays overdue' => ['2027-03-31', $default, '2028-06-01', Reminder::OVERDUE, 'overdue'],
			'the start of the month alone waits for the 1st' => ['2027-03-31', ['month_before' => false, 'month_start' => true], '2027-02-28', Reminder::PLANNED, null],
			'and warns on the 1st' => ['2027-03-31', ['month_before' => false, 'month_start' => true], '2027-03-01', Reminder::WARNED, 'month_start'],
			'the later point is the one reached' => ['2027-03-31', ['month_start' => true], '2027-03-01', Reminder::WARNED, 'month_start'],
			'a due date on the 1st is due, not warned' => ['2027-03-01', ['month_start' => true], '2027-03-01', Reminder::DUE, 'due_date'],
			'which a month before was warned' => ['2027-03-01', ['month_start' => true], '2027-02-01', Reminder::WARNED, 'month_before'],
			'with the due date unticked the 1st still sends its point' => ['2027-03-01', ['month_before' => false, 'month_start' => true, 'due_date' => false], '2027-03-01', Reminder::DUE, 'month_start'],
			'no point ticked stays planned' => ['2027-03-31', ['month_before' => false, 'due_date' => false], '2027-03-30', Reminder::PLANNED, null],
			'and is due without a point' => ['2027-03-31', ['month_before' => false, 'due_date' => false], '2027-03-31', Reminder::DUE, null],
			'overdue sends whatever was ticked' => ['2027-03-31', ['month_before' => false, 'due_date' => false], '2027-04-01', Reminder::OVERDUE, 'overdue'],
		];
	}

	/**
	 * @param array{month_before?: bool, month_start?: bool, due_date?: bool} $points
	 */
	#[DataProvider('dateEdges')]
	public function testADateReminderMovesAtItsPoints(string $due, array $points, string $today, string $state, ?string $point): void {
		$this->assertSame(['state' => $state, 'point' => $point], ReminderEngine::evaluate(self::byDate($due, $points), $today, null));
	}

	/**
	 * @return array<string, array{?int, ?int, string, ?string}>
	 */
	public static function odoEdges(): array {
		return [
			'no reading yet' => [1000, null, Reminder::PLANNED, null],
			'a km short of the lead' => [1000, 133999, Reminder::PLANNED, null],
			'at due minus lead' => [1000, 134000, Reminder::WARNED, 'odo'],
			'a km short of due' => [1000, 134999, Reminder::WARNED, 'odo'],
			'at due' => [1000, 135000, Reminder::DUE, 'odo_due'],
			'far past due is still due, a counter has no day after' => [1000, 200000, Reminder::DUE, 'odo_due'],
			'no lead warns nothing' => [null, 134999, Reminder::PLANNED, null],
			'nor does a lead of 0' => [0, 134999, Reminder::PLANNED, null],
		];
	}

	#[DataProvider('odoEdges')]
	public function testAnOdometerReminderMovesWithTheMainChain(?int $lead, ?int $odo, string $state, ?string $point): void {
		$this->assertSame(['state' => $state, 'point' => $point], ReminderEngine::evaluate(self::byOdo(135000, $lead), '2027-01-01', $odo));
	}

	/** Due by km before the date warns: the km win. */
	public function testEitherIsWhicheverComesFirst(): void {
		$reminder = self::byDate('2027-03-31');
		$reminder->setMode(Reminder::EITHER);
		$reminder->setDueOdo(135000);
		$reminder->setLeadOdo(1000);

		$this->assertSame(['state' => Reminder::PLANNED, 'point' => null], ReminderEngine::evaluate($reminder, '2027-01-01', 120000));
		$this->assertSame(['state' => Reminder::DUE, 'point' => 'odo_due'], ReminderEngine::evaluate($reminder, '2027-01-01', 135000));
		$this->assertSame(['state' => Reminder::WARNED, 'point' => 'month_before'], ReminderEngine::evaluate($reminder, '2027-02-28', 120000));
		$this->assertSame(['state' => Reminder::OVERDUE, 'point' => 'overdue'], ReminderEngine::evaluate($reminder, '2027-04-01', 135000));
	}

	/** A snooze silences until its day, and on that day the reminder is back where it stands. */
	public function testASnoozeHoldsUntilItsDay(): void {
		$reminder = self::byDate('2027-03-31');
		$reminder->setState(Reminder::SNOOZED);
		$reminder->setSnoozedUntil(new \DateTime('2027-04-10'));

		$this->assertSame(['state' => Reminder::SNOOZED, 'point' => null], ReminderEngine::evaluate($reminder, '2027-04-09', null));
		$this->assertSame(['state' => Reminder::OVERDUE, 'point' => 'overdue'], ReminderEngine::evaluate($reminder, '2027-04-10', null));
	}

	/** Done and dismissed are the end of an occurrence; no day moves them. */
	public function testDoneAndDismissedStay(): void {
		foreach ([Reminder::DONE, Reminder::DISMISSED] as $end) {
			$reminder = self::byDate('2027-03-31');
			$reminder->setState($end);

			$this->assertSame(['state' => $end, 'point' => null], ReminderEngine::evaluate($reminder, '2027-04-01', null));
		}
	}

	/**
	 * @return array<string, array{string, int, string}>
	 */
	public static function recurrences(): array {
		return [
			'a year on' => ['2027-03-15', 12, '2028-03-15'],
			'the end of a month stays the end' => ['2027-08-31', 6, '2028-02-29'],
			'and comes back to the 31st' => ['2028-02-29', 6, '2028-08-31'],
			'a 30th clamps into February' => ['2027-01-30', 1, '2027-02-28'],
			'across two years' => ['2027-05-31', 24, '2029-05-31'],
		];
	}

	#[DataProvider('recurrences')]
	public function testARecurrenceCountsFromTheDayItIsGiven(string $from, int $months, string $next): void {
		$reminder = self::byDate('2020-01-01');
		$reminder->setRecurMonths($months);
		$reminder->setState(Reminder::SNOOZED);
		$reminder->setSnoozedUntil(new \DateTime('2020-01-05'));

		$this->assertTrue(ReminderEngine::advance($reminder, $from, null));
		$this->assertSame($next, $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(2, $reminder->getOccurrence());
		$this->assertNull($reminder->getSnoozedUntil());
	}

	/** Each axis recurs from its own base: an oil change done early is next due early on both. */
	public function testEachAxisRecursFromItsOwnBase(): void {
		$reminder = self::byDate('2027-03-31');
		$reminder->setMode(Reminder::EITHER);
		$reminder->setDueOdo(135000);
		$reminder->setRecurMonths(12);
		$reminder->setRecurOdo(15000);

		$this->assertTrue(ReminderEngine::advance($reminder, '2027-03-02', 133500));
		$this->assertSame(148500, $reminder->getDueOdo());
		$this->assertSame('2028-03-02', $reminder->getDueDate()?->format('Y-m-d'));
	}

	/** A reminder without a recurrence has no next occurrence, and is left as it was. */
	public function testNoRecurrenceNoNext(): void {
		$reminder = self::byDate('2027-03-31');

		$this->assertFalse(ReminderEngine::advance($reminder, '2027-03-31', null));
		$this->assertSame('2027-03-31', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(1, $reminder->getOccurrence());
	}

	/** An oil change due 2027-03-31 or at 135 000 km, every 12 months or 15 000 km. */
	private static function oilChange(): Reminder {
		$reminder = self::byDate('2027-03-31');
		$reminder->setMode(Reminder::EITHER);
		$reminder->setDueOdo(135000);
		$reminder->setRecurMonths(12);
		$reminder->setRecurOdo(15000);

		return $reminder;
	}

	/** Taking back the close of a record from before puts the occurrence back at its day and km. */
	public function testARetreatTakesBackTheAdvanceFromTheSameBase(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-08-31', 133500);

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-08-31', 133500, new ClosedDue()));
		$this->assertSame('2027-08-31', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(133500, $reminder->getDueOdo());
		$this->assertSame(1, $reminder->getOccurrence());
	}

	/** Oil every 12 months or 15 000 km, due 2027-03-01 or at 60 000 km, done early. */
	private static function doneEarly(): Reminder {
		$reminder = self::oilChange();
		$reminder->setDueDate(new \DateTime('2027-03-01'));
		$reminder->setDueOdo(60000);
		ReminderEngine::advance($reminder, '2027-02-10', 58500);

		return $reminder;
	}

	/** What the record that closed doneEarly() kept, and the due its close set. */
	private static function closedEarly(): ClosedDue {
		return new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10', nextOdo: 73500);
	}

	/** Taking the close back puts back the due it replaced, not the record's own day and km. */
	public function testARetreatPutsBackTheDueTheRecordKept(): void {
		$reminder = self::doneEarly();

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, self::closedEarly()));
		$this->assertSame('2027-03-01', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(60000, $reminder->getDueOdo());
		$this->assertSame(1, $reminder->getOccurrence());
	}

	/** Each axis that recurs takes back its own kept value. */
	public function testARetreatPutsBackOneAxisAsWellAsTwo(): void {
		$byDate = self::byDate('2027-03-01');
		$byDate->setRecurMonths(12);
		ReminderEngine::advance($byDate, '2027-02-10', null);
		$byOdo = self::byOdo(60000, 1000);
		$byOdo->setRecurOdo(15000);
		ReminderEngine::advance($byOdo, '2027-02-10', 58500);

		$this->assertTrue(ReminderEngine::retreat($byDate, '2027-02-10', null, new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10')));
		$this->assertSame('2027-03-01', $byDate->getDueDate()?->format('Y-m-d'));
		$this->assertNull($byDate->getDueOdo());
		$this->assertTrue(ReminderEngine::retreat($byOdo, '2027-02-10', 58500, new ClosedDue(null, 60000, null, 1, nextOdo: 73500)));
		$this->assertSame(60000, $byOdo->getDueOdo());
		$this->assertNull($byOdo->getDueDate());
	}

	/**
	 * Made km-only after the close: the date the close set no longer tells, so the km alone takes
	 * the close back, and closes it again.
	 */
	public function testACloseOnAReminderMadeKmOnlyIsTakenBackAndClosedAgainByTheKm(): void {
		$reminder = self::doneEarly();
		$reminder->setMode(Reminder::ODO);
		$reminder->setDueDate(null);
		$reminder->setRecurMonths(null);

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, self::closedEarly()));
		$this->assertSame([null, 60000, 1], [$reminder->getDueDate()?->format('Y-m-d'), $reminder->getDueOdo(), $reminder->getOccurrence()]);
		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, self::closedEarly()));
		$this->assertTrue(ReminderEngine::reclose($reminder, self::closedEarly()));
		$this->assertSame([null, 73500, 2], [$reminder->getDueDate()?->format('Y-m-d'), $reminder->getDueOdo(), $reminder->getOccurrence()]);
	}

	/**
	 * A record from before kept no occurrence: the due comes back at its own day and km, whatever
	 * else it holds.
	 */
	public function testARetreatOfARecordFromBeforeFallsBackToTheBase(): void {
		foreach ([new ClosedDue(), new ClosedDue('2027-03-01', 60000, null)] as $closed) {
			$reminder = self::doneEarly();

			$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed));
			$this->assertSame('2027-02-10', $reminder->getDueDate()?->format('Y-m-d'));
			$this->assertSame(58500, $reminder->getDueOdo());
			$this->assertSame(1, $reminder->getOccurrence());
		}
	}

	/**
	 * By date every 12 months, closed on 2027-02-10 at 58 500 km. Then it is edited to recur by km
	 * too, due at 74 000 km. The record kept no km, so the km the person entered stays, though it is
	 * not the one the close would have given.
	 */
	public function testAnAxisAddedAfterTheCloseStaysAsEntered(): void {
		$reminder = self::byDate('2027-03-01');
		$reminder->setRecurMonths(12);
		$closed = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');
		ReminderEngine::advance($reminder, '2027-02-10', 58500);
		$reminder->setMode(Reminder::EITHER);
		$reminder->setRecurOdo(15000);
		$reminder->setDueOdo(74000);

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed));
		$this->assertSame('2027-03-01', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(74000, $reminder->getDueOdo());
		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
	}

	/** The same the other way round: by km, a due date added after the close. */
	public function testADueDateAddedAfterTheCloseStaysAsEntered(): void {
		$reminder = self::byOdo(60000, 1000);
		$reminder->setRecurOdo(15000);
		$closed = new ClosedDue(null, 60000, null, 1, nextOdo: 73500);
		ReminderEngine::advance($reminder, '2027-02-10', 58500);
		$reminder->setMode(Reminder::EITHER);
		$reminder->setRecurMonths(12);
		$reminder->setDueDate(new \DateTime('2028-03-15'));

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed));
		$this->assertSame(60000, $reminder->getDueOdo());
		$this->assertSame('2028-03-15', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
	}

	/**
	 * Each snooze means "quiet until", so the later one keeps both wishes; none counts as no wish.
	 *
	 * @return array<string, array{?string, ?string, ?string}>
	 */
	public static function snoozes(): array {
		return [
			'kept, none since' => ['2027-02-20', null, '2027-02-20'],
			'none kept, one since' => [null, '2027-04-15', '2027-04-15'],
			'the kept one later' => ['2027-05-01', '2027-04-15', '2027-05-01'],
			'the one since later' => ['2027-02-20', '2027-04-15', '2027-04-15'],
			'none at all' => [null, null, null],
		];
	}

	#[DataProvider('snoozes')]
	public function testARetreatKeepsTheLaterSnooze(?string $kept, ?string $since, ?string $snoozed): void {
		$reminder = self::doneEarly();
		$reminder->setSnoozedUntil($since === null ? null : new \DateTime($since));

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60000, $kept, 1, nextDay: '2028-02-10', nextOdo: 73500)));
		$this->assertSame($snoozed, $reminder->getSnoozedUntil()?->format('Y-m-d'));
	}

	/** A snooze put back after its day has passed silences nothing. */
	public function testASnoozePutBackInThePastIsNoSnooze(): void {
		$reminder = self::doneEarly();
		ReminderEngine::retreat($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60000, '2027-02-20', 1, nextDay: '2028-02-10', nextOdo: 73500));

		$this->assertSame('2027-02-20', $reminder->getSnoozedUntil()?->format('Y-m-d'));
		$this->assertSame(['state' => Reminder::DUE, 'point' => 'due_date'], ReminderEngine::evaluate($reminder, '2027-03-01', 59000));
	}

	/**
	 * A reminder that moved on from somewhere else - a dismissal, an edit, another record - is not
	 * this close's to take back.
	 */
	public function testARetreatFromAnotherBaseChangesNothing(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-03-02', 133500);
		$reminder->setSnoozedUntil(new \DateTime('2031-02-01'));
		// The kept snooze is the later one, so a merge would show.
		$elsewhere = static fn (string $day, int $odo): ClosedDue => new ClosedDue('2027-03-31', 135000, '2031-02-20', 1, nextDay: $day, nextOdo: $odo);

		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-03-02', 133500, $elsewhere('2028-03-01', 148500)));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-03-02', 133500, $elsewhere('2028-03-02', 148400)));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-03-01', 133500, new ClosedDue()));
		$this->assertSame('2028-03-02', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(148500, $reminder->getDueOdo());
		$this->assertSame('2031-02-01', $reminder->getSnoozedUntil()?->format('Y-m-d'));
		$this->assertSame(2, $reminder->getOccurrence());
	}

	/** An undo may close it again only while nothing else has moved it. */
	public function testItStandsAtTheBaseARetreatLeftItAt(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-03-02', 133500);
		ReminderEngine::retreat($reminder, '2027-03-02', 133500, new ClosedDue());

		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-03-02', 133500, new ClosedDue()));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-03-03', 133500, new ClosedDue()));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-03-02', 133501, new ClosedDue()));
	}

	/** It stands where retreat() put it back: at the kept due, no longer at the record's own day. */
	public function testItStandsAtTheDueARetreatPutBack(): void {
		$closed = self::closedEarly();
		$reminder = self::doneEarly();
		ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed);

		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, new ClosedDue()));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-02', 60000, null, 1)));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60001, null, 1)));
	}

	/**
	 * Dismissed since, then edited back to the very same due: another occurrence stands there now,
	 * not the one the retreat put back. A record from before kept no occurrence to tell them apart.
	 */
	public function testItStandsAtTheOccurrenceARetreatPutBack(): void {
		$closed = self::closedEarly();
		$reminder = self::doneEarly();
		ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed);
		$reminder->setOccurrence(2);
		$legacy = self::doneEarly();
		ReminderEngine::retreat($legacy, '2027-02-10', 58500, new ClosedDue());
		$legacy->setOccurrence(2);

		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
		$this->assertTrue(ReminderEngine::standsAt($legacy, '2027-02-10', 58500, new ClosedDue()));
	}

	/**
	 * A one-off's close does not move its occurrence, so the record that made it done stands at the
	 * occurrence it closed, also with a due handed up from the occurrence before. A close taken back
	 * while the reminder recurred put back the occurrence of the kept due; made a one-off since, it
	 * stands there too.
	 */
	public function testAOneOffStandsAtTheOccurrenceTheRecordClosed(): void {
		$reminder = self::byDate('2028-02-10');
		$reminder->setOccurrence(2);
		$retreated = self::byDate('2027-03-01');
		$retreated->setOccurrence(1);

		$this->assertTrue(ReminderEngine::standsAt($reminder, '2028-02-01', null, new ClosedDue('2027-03-01', null, null, 2, 1)));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', null, new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10')));
		$this->assertTrue(ReminderEngine::standsAt($retreated, '2028-02-01', null, new ClosedDue('2027-03-01', null, null, 2, 1)));
	}

	/**
	 * Two records on the same day closed two occurrences in turn. The first one's close is not on
	 * top, though the second one's set the very same due.
	 */
	public function testARetreatTakesBackOnlyTheCloseOnTop(): void {
		$first = self::closedEarly();
		$reminder = self::doneEarly();
		$second = new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2028-02-10', nextOdo: 73500);
		ReminderEngine::advance($reminder, '2027-02-10', 58500);

		$this->assertFalse(ReminderEngine::onTop($reminder, '2027-02-10', 58500, $first));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $first));
		$this->assertSame(3, $reminder->getOccurrence());
		$this->assertTrue(ReminderEngine::onTop($reminder, '2027-02-10', 58500, $second));
		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $second));
		$this->assertSame('2028-02-10', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73500, $reminder->getDueOdo());
		$this->assertSame(2, $reminder->getOccurrence());
	}

	/**
	 * The record closed the third occurrence, but keeps the due of the first, handed up from the
	 * records below. Taking its close back returns the reminder to the occurrence of that due, so
	 * the close below it is on top again.
	 */
	public function testARetreatReturnsToTheOccurrenceOfTheKeptDue(): void {
		$closed = new ClosedDue('2027-03-01', 60000, null, 3, 1, '2028-02-10', 73500);
		$reminder = self::doneEarly();
		$reminder->setOccurrence(4);

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed));
		$this->assertSame(1, $reminder->getOccurrence());
		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
		$this->assertTrue(ReminderEngine::onTop(self::doneEarly(), '2027-02-10', 58500, self::closedEarly()));
		$reminder->setOccurrence(3);
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-02-10', 58500, $closed));
	}

	/**
	 * The interval changed after the close, the due did not: the close is still on top, the record
	 * above still follows, and taking the close back puts back the due it replaced. A record from
	 * before can only count from its base, with the interval as it is now.
	 */
	public function testAnIntervalChangedAfterTheCloseLeavesItOnTop(): void {
		$reminder = self::doneEarly();
		$reminder->setRecurMonths(24);
		$reminder->setRecurOdo(20000);
		$legacy = clone $reminder;
		$closed = self::closedEarly();

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::onTop($reminder, '2027-02-10', 58500, $closed));
		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-02-10', 58500, $closed));
		$this->assertSame('2027-03-01', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(60000, $reminder->getDueOdo());
		$this->assertSame(1, $reminder->getOccurrence());
		$this->assertFalse(ReminderEngine::onTop($legacy, '2027-02-10', 58500, new ClosedDue()));
	}

	/**
	 * The occurrence the close started is over: dismissed, or done while the reminder did not recur,
	 * then made to recur again. That ended it after the close, so the close is not on top, for a
	 * record from before either.
	 */
	#[DataProvider('over')]
	public function testACloseUnderAnOccurrenceThatIsOverIsNotOnTop(string $state): void {
		$reminder = self::doneEarly();
		$reminder->setState($state);

		$this->assertFalse(ReminderEngine::onTop($reminder, '2027-02-10', 58500, self::closedEarly()));
		$this->assertFalse(ReminderEngine::onTop($reminder, '2027-02-10', 58500, new ClosedDue()));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-02-10', 58500, self::closedEarly()));
		$this->assertSame(2, $reminder->getOccurrence());
	}

	/** @return array<string, array{string}> */
	public static function over(): array {
		return ['dismissed' => [Reminder::DISMISSED], 'done' => [Reminder::DONE]];
	}

	/**
	 * The due km was edited by hand after the close, then the record's counter cleared. The close
	 * set a km, and the reminder no longer stands there, so the close is neither on top nor followed.
	 */
	public function testACounterClearedOnACloseNoLongerInEffectLeavesItOff(): void {
		$reminder = self::doneEarly();
		$reminder->setDueOdo(74000);
		$closed = self::closedEarly();

		$this->assertFalse(ReminderEngine::onTop($reminder, '2027-02-10', null, $closed));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-02-10', null, $closed));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01')));
		$this->assertSame('2028-02-10', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(74000, $reminder->getDueOdo());
	}

	/**
	 * By date when this record closed it, by km alone since: its close set nothing the reminder
	 * still recurs by, so nothing tells whether it is on top. The other record kept no date, so the
	 * two share no axis, and nothing tells whether it came right after it either.
	 */
	public function testACloseThatSetNoAxisTheReminderRecursByIsNeitherOnTopNorFollowed(): void {
		$reminder = self::byOdo(73500, null);
		$reminder->setRecurOdo(15000);
		$reminder->setOccurrence(2);
		$closed = new ClosedDue('2027-03-01', null, '2027-02-20', 1, nextDay: '2028-02-10');

		$this->assertFalse(ReminderEngine::onTop($reminder, '2027-02-10', 58500, $closed));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue(null, 73500, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * An undo closes the reminder again at the due the close stored, whatever the interval is now.
	 * The km was added after the close, so it stays as entered.
	 */
	public function testACloseAgainSetsTheStoredDue(): void {
		$reminder = self::byDate('2027-03-01');
		$reminder->setMode(Reminder::EITHER);
		$reminder->setDueOdo(74000);
		$reminder->setRecurMonths(24);
		$reminder->setRecurOdo(15000);
		$reminder->setSnoozedUntil(new \DateTime('2027-02-20'));

		$this->assertTrue(ReminderEngine::reclose($reminder, new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10')));
		$this->assertSame('2028-02-10', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(74000, $reminder->getDueOdo());
		$this->assertSame(2, $reminder->getOccurrence());
		$this->assertNull($reminder->getSnoozedUntil());
	}

	/**
	 * A one-off closed by a record from 2027-02-27, made to recur after the close was taken back:
	 * the close set no due, so the due it sets now is counted from the record's day, as a new close
	 * would count it. Without the record's counter the km stays without.
	 */
	public function testAOneOffMadeToRecurIsCountedFromTheRecord(): void {
		$reminder = self::oilChange();
		$closed = new ClosedDue('2027-03-01', 60000, '2031-03-01', 1);

		$this->assertEquals(
			new ClosedDue('2027-03-01', 60000, '2031-03-01', 1, nextDay: '2028-02-27', nextOdo: 73500),
			ReminderEngine::recurred($reminder, '2027-02-27', 58500, $closed),
		);
		$this->assertEquals(
			new ClosedDue('2027-03-01', 60000, '2031-03-01', 1, nextDay: '2028-02-27'),
			ReminderEngine::recurred($reminder, '2027-02-27', null, $closed),
		);
	}

	/**
	 * Only an axis the reminder recurs by, that the record kept a due on and its close set none on,
	 * is counted. A due the close set stays, whatever the interval is now; an axis the record kept
	 * nothing for stays without; a record from before stays as it is.
	 */
	public function testOnlyAnAxisTheCloseKeptButSetNothingOnIsCounted(): void {
		$reminder = self::oilChange();
		$reminder->setRecurMonths(24);
		$set = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');
		$byDate = self::byDate('2027-03-01');
		$byDate->setRecurMonths(12);

		$this->assertEquals($set, ReminderEngine::recurred($reminder, '2027-02-10', 58500, $set));
		$this->assertEquals(new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10'), ReminderEngine::recurred($byDate, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60000, null, 1)));
		$this->assertEquals(new ClosedDue(), ReminderEngine::recurred($reminder, '2027-02-10', 58500, new ClosedDue()));
	}

	/** A close tells where the reminder stands only on an axis it set and the reminder recurs by. */
	public function testACloseTellsOnAnAxisItSetThatTheReminderRecursBy(): void {
		$closed = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');
		$byDate = self::byDate('2028-02-10');
		$byDate->setRecurMonths(12);
		$byOdo = self::byOdo(61000, null);
		$byOdo->setRecurOdo(15000);

		$this->assertTrue(ReminderEngine::tells($byDate, $closed));
		$this->assertFalse(ReminderEngine::tells($byOdo, $closed));
		$this->assertFalse(ReminderEngine::tells($byDate, new ClosedDue('2027-03-01', null, null, 1)));
	}

	/** A one-off has no next due: the close makes it done. */
	public function testAOneOffClosesAgainAsDone(): void {
		$once = self::byDate('2027-03-01');

		$this->assertFalse(ReminderEngine::reclose($once, new ClosedDue('2027-03-01', null, null, 1)));
	}

	/** A record from before stored no due, so only closeBy() can close again from its day and counter. */
	public function testARecordFromBeforeCannotCloseAgainFromAStoredDue(): void {
		$legacy = self::byDate('2027-03-01');
		$legacy->setRecurMonths(12);

		$this->expectException(\LogicException::class);
		ReminderEngine::reclose($legacy, new ClosedDue());
	}

	/**
	 * Made a one-off after its close was taken back, the reminder stands at the occurrence of the
	 * due the record kept. Closed again, it is done at the occurrence the record closed, as a
	 * one-off's close leaves it, so deleting the record again finds the close its own.
	 */
	public function testAOneOffClosedAgainStandsAtTheOccurrenceTheRecordClosed(): void {
		$once = self::byDate('2027-03-01');
		$once->setOccurrence(1);

		$this->assertFalse(ReminderEngine::reclose($once, new ClosedDue('2027-03-01', null, null, 2, 1, '2029-02-01')));
		$this->assertSame(2, $once->getOccurrence());
		$this->assertSame('2027-03-01', $once->getDueDate()?->format('Y-m-d'));
	}

	/**
	 * The record's day and km are corrected: the close moves with them. The occurrence it started
	 * and the snooze on it stay.
	 */
	public function testAMoveCountsTheCloseFromTheNewBase(): void {
		$closed = self::closedEarly();
		$reminder = self::doneEarly();
		$reminder->setSnoozedUntil(new \DateTime('2031-01-15'));

		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, $closed, '2027-02-12', 58700));
		$this->assertSame('2028-02-12', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73700, $reminder->getDueOdo());
		$this->assertSame(2, $reminder->getOccurrence());
		$this->assertSame('2031-01-15', $reminder->getSnoozedUntil()?->format('Y-m-d'));
	}

	/** Only the axes it recurs by move: a date reminder keeps its km, whatever the new counter. */
	public function testAMoveLeavesAnAxisThatDoesNotRecur(): void {
		$reminder = self::byDate('2027-03-01');
		$reminder->setRecurMonths(12);
		$reminder->setDueOdo(60000);
		$closed = new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10');
		ReminderEngine::advance($reminder, '2027-02-10', 58500);

		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, $closed, '2027-02-11', 58700));
		$this->assertSame('2028-02-11', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(60000, $reminder->getDueOdo());
	}

	/** The km the person gave the reminder after the close is not the close's to move. */
	public function testAMoveLeavesAnAxisAddedAfterTheClose(): void {
		$reminder = self::byDate('2027-03-01');
		$reminder->setRecurMonths(12);
		$closed = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');
		ReminderEngine::advance($reminder, '2027-02-10', 58500);
		$reminder->setMode(Reminder::EITHER);
		$reminder->setRecurOdo(15000);
		$reminder->setDueOdo(74000);

		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, $closed, '2027-02-12', 58700));
		$this->assertSame('2028-02-12', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(74000, $reminder->getDueOdo());
	}

	/** The same for a date the person gave a reminder by km after the close. */
	public function testAMoveLeavesADateAddedAfterTheClose(): void {
		$reminder = self::byOdo(60000, null);
		$reminder->setRecurOdo(15000);
		$closed = new ClosedDue(null, 60000, null, 1, nextOdo: 73500);
		ReminderEngine::advance($reminder, '2027-02-10', 58500);
		$reminder->setMode(Reminder::EITHER);
		$reminder->setRecurMonths(12);
		$reminder->setDueDate(new \DateTime('2028-03-15'));

		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, $closed, '2027-02-12', 58700));
		$this->assertSame('2028-03-15', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73700, $reminder->getDueOdo());
	}

	/** A close that is not on top is not this record's to move; one from before needs no occurrence. */
	public function testAMoveNeedsTheCloseOnTop(): void {
		$reminder = self::doneEarly();
		$legacy = self::doneEarly();

		$this->assertFalse(ReminderEngine::move($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60000, null, 2, nextDay: '2028-02-10', nextOdo: 73500), '2027-02-12', 58700));
		$this->assertFalse(ReminderEngine::move($reminder, '2027-02-10', 58500, new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-11', nextOdo: 73500), '2027-02-12', 58700));
		$this->assertSame('2028-02-10', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73500, $reminder->getDueOdo());
		$this->assertTrue(ReminderEngine::move($legacy, '2027-02-10', 58500, new ClosedDue(), '2027-02-12', 58700));
		$this->assertSame('2028-02-12', $legacy->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73700, $legacy->getDueOdo());
	}

	/**
	 * The close counted from a corrected base sets the due that base gives with the interval as it
	 * is now, on each axis it set. A record from before stored no due to count.
	 */
	public function testARebasedCloseSetsTheDueTheNewBaseGives(): void {
		$reminder = self::oilChange();
		$dateOnly = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');

		$this->assertEquals(
			new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73700),
			ReminderEngine::rebase($reminder, self::closedEarly(), '2027-02-12', 58700),
		);
		$this->assertEquals(
			new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-12'),
			ReminderEngine::rebase($reminder, $dateOnly, '2027-02-12', 58700),
		);
		$reminder->setRecurMonths(24);
		$this->assertSame('2029-02-12', ReminderEngine::rebase($reminder, $dateOnly, '2027-02-12', 58700)->nextDay);
		$this->assertEquals(new ClosedDue(), ReminderEngine::rebase($reminder, new ClosedDue(), '2027-02-12', 58700));
	}

	/**
	 * Only the day was corrected, after the interval changed: the km the close set stays, as no new
	 * counter counts it again.
	 */
	public function testARebaseLeavesAnAxisWithoutANewBase(): void {
		$reminder = self::doneEarly();
		$reminder->setRecurOdo(20000);

		$this->assertEquals(
			new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73500),
			ReminderEngine::rebase($reminder, self::closedEarly(), '2027-02-12', null),
		);
		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, self::closedEarly(), '2027-02-12', null));
		$this->assertSame('2028-02-12', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73500, $reminder->getDueOdo());
	}

	/** The same for the date, when only the counter was corrected. */
	public function testARebaseLeavesTheDateWithoutANewDay(): void {
		$reminder = self::doneEarly();
		$reminder->setRecurMonths(24);

		$this->assertEquals(
			new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10', nextOdo: 73700),
			ReminderEngine::rebase($reminder, self::closedEarly(), null, 58700),
		);
		$this->assertTrue(ReminderEngine::move($reminder, '2027-02-10', 58500, self::closedEarly(), null, 58700));
		$this->assertSame('2028-02-10', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(73700, $reminder->getDueOdo());
	}

	/**
	 * The record that closed the next occurrence follows this one when it kept the due this
	 * record's close set. A dismissal or a hand edit in between gave another due.
	 */
	public function testARecordFollowsWhenItKeptTheDueThisCloseSet(): void {
		$closed = self::closedEarly();

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2029-02-10', 73500, null, 3, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * Dismissed, then edited back to the very due this close set, and closed again: that record kept
	 * this close's due, but for another occurrence, so it does not follow. One that took the due over
	 * with its occurrence does.
	 */
	public function testARecordAcrossADismissalDoesNotFollow(): void {
		$this->assertFalse(ReminderEngine::follows(self::closedEarly(), new ClosedDue('2028-02-10', 73500, null, 3, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows(self::closedEarly(), new ClosedDue('2028-02-10', 73500, null, 3, 2, nextDay: '2029-02-01')));
	}

	/** Only the axes this close set count; the km the record above kept is whatever it was. */
	public function testARecordFollowsOnTheAxesThisCloseSet(): void {
		$closed = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 99000, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', null, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * By km alone since this close: a record above that kept another date than the one this close
	 * set had a hand edit in between, so it does not follow. One that kept no date, as the reminder
	 * had none when it closed, does.
	 */
	public function testARecordWithAnotherDueOnAnAxisRemovedSinceDoesNotFollow(): void {
		$closed = self::closedEarly();

		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-06-01', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue(null, 73500, null, 2, nextDay: '2029-02-01')));
	}

	/** The same for the km of a reminder by date alone since this close. */
	public function testARecordWithAnotherKmOnAnAxisRemovedSinceDoesNotFollow(): void {
		$closed = self::closedEarly();

		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', null, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * The km was removed between the two closes and added back after them: the record above kept no
	 * km, as the reminder had none when it closed, so only the date tells. A record above that kept
	 * nothing on either axis has nothing to tell.
	 */
	public function testARecordWithoutAnAxisAddedBackSinceFollowsOnTheOther(): void {
		$closed = self::closedEarly();

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', null, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-06-01', null, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue(null, null, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * By date when this record closed it, by km too since: its close set no km, so the km the record
	 * above kept is the person's, whatever it is. A record from before stored nothing to tell.
	 */
	public function testARecordFollowsWithoutAnAxisThisCloseDidNotSet(): void {
		$closed = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-03-10', 74000, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows(new ClosedDue(), new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01')));
	}

	/** The same for a date the reminder by km was given after this close. */
	public function testARecordFollowsWithoutADateThisCloseDidNotSet(): void {
		$closed = new ClosedDue(null, 60000, null, 1, nextOdo: 73500);

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-03-15', 73500, null, 2, nextDay: '2029-02-01')));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-03-15', 74000, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * By date when this record closed it, given a km before the record above closed it, by km alone
	 * since. The record above kept the date this close set, so it follows: the axis the two share
	 * tells, whatever the reminder recurs by now.
	 */
	public function testARecordFollowsOnAnAxisTheReminderNoLongerRecursBy(): void {
		$closed = new ClosedDue('2027-03-01', null, '2027-04-15', 1, nextDay: '2028-02-10');

		$this->assertTrue(ReminderEngine::follows($closed, new ClosedDue('2028-02-10', 75000, null, 2, nextDay: '2029-01-20', nextOdo: 85000)));
		$this->assertFalse(ReminderEngine::follows($closed, new ClosedDue('2028-03-10', 75000, null, 2, nextDay: '2029-01-20', nextOdo: 85000)));
	}

	/**
	 * The other record closed the next occurrence while the reminder was a one-off, so its close set
	 * no due. Taking a one-off's close back reopens its own occurrence and never returns to this
	 * one, so it is no record above, though it kept the due this close set.
	 */
	public function testAOneOffCloseIsNoRecordAbove(): void {
		$this->assertFalse(ReminderEngine::follows(self::closedEarly(), new ClosedDue('2028-02-10', 73500, null, 2)));
		$this->assertTrue(ReminderEngine::follows(self::closedEarly(), new ClosedDue('2028-02-10', 73500, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * By km when this record closed it, by date alone since: the record above kept no km and this
	 * close set no date, so the two share no axis and nothing tells.
	 */
	public function testARecordSharingNoAxisWithThisCloseDoesNotFollow(): void {
		$this->assertFalse(ReminderEngine::follows(new ClosedDue(null, 60000, '2031-03-01', 1, nextOdo: 73500), new ClosedDue('2028-05-01', null, null, 2, nextDay: '2029-02-01')));
	}

	/**
	 * The lower record's day is corrected, or its close comes back: the record above keeps the due
	 * that close sets. Its km, entered after the lower close, its snooze and its own close stay.
	 */
	public function testTheRecordAboveKeepsTheDueThisCloseSets(): void {
		$above = new ClosedDue('2028-02-10', 74000, '2031-04-01', 2, nextDay: '2029-02-01', nextOdo: 89000);

		$this->assertEquals(
			new ClosedDue('2028-02-12', 74000, '2031-04-01', 2, nextDay: '2029-02-01', nextOdo: 89000),
			ReminderEngine::keptAbove(new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-12'), $above),
		);
		$this->assertEquals(
			new ClosedDue('2028-02-12', 73700, '2031-04-01', 2, nextDay: '2029-02-01', nextOdo: 89000),
			ReminderEngine::keptAbove(new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73700), $above),
		);
	}

	/**
	 * The reminder no longer recurs by km, but the lower close set one, and the record above kept it
	 * before it was handed one: it gets that km back, not the one handed to it.
	 */
	public function testTheRecordAboveGetsBackAnAxisTheReminderNoLongerRecursBy(): void {
		$handed = new ClosedDue('2027-03-01', 60000, null, 2, 1);

		$this->assertEquals(new ClosedDue('2028-02-10', 73500, null, 2, 1), ReminderEngine::keptAbove(self::closedEarly(), $handed));
	}

	/**
	 * The record above kept no date, as the reminder had none when it closed: it gets none from the
	 * lower close either, so taking its own close back leaves a date entered since.
	 */
	public function testTheRecordAboveKeepsNoDueOnAnAxisItKeptNoneFor(): void {
		$this->assertEquals(
			new ClosedDue(null, 73700, null, 2),
			ReminderEngine::keptAbove(new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73700), new ClosedDue(null, 73500, null, 2)),
		);
	}

	/** The same for a km the record above kept none for. */
	public function testTheRecordAboveKeepsNoKmOnAnAxisItKeptNoneFor(): void {
		$this->assertEquals(
			new ClosedDue('2028-02-12', null, null, 2),
			ReminderEngine::keptAbove(new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73700), new ClosedDue('2028-02-10', null, null, 2)),
		);
	}

	/** A due the record above took over keeps its occurrence when the lower day is corrected. */
	public function testTheRecordAboveKeepsTheOccurrenceOfItsDue(): void {
		$above = new ClosedDue('2028-02-10', 73500, null, 3, 2);

		$this->assertEquals(
			new ClosedDue('2028-02-12', 73700, null, 3, 2),
			ReminderEngine::keptAbove(new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-12', nextOdo: 73700), $above),
		);
	}

	/** The first occurrence was never advanced to, and one that does not recur never advances. */
	public function testARetreatNeedsAnAdvanceToTakeBack(): void {
		$first = self::oilChange();
		$first->setDueDate(new \DateTime('2028-03-02'));
		$first->setDueOdo(148500);
		$oneOff = self::byDate('2028-03-02');
		$oneOff->setOccurrence(2);

		$this->assertFalse(ReminderEngine::retreat($first, '2027-03-02', 133500, new ClosedDue()));
		$this->assertFalse(ReminderEngine::retreat($oneOff, '2027-03-02', null, new ClosedDue()));
	}

	/**
	 * Main-chain Readings, each an ISO instant and a value.
	 *
	 * @param array<string, int> $readings
	 * @param list<string> $flagged the instants whose Reading is flagged
	 * @return list<OdoReading>
	 */
	private static function chain(array $readings, array $flagged = []): array {
		$chain = [];
		foreach ($readings as $at => $value) {
			$reading = new OdoReading();
			$reading->setReadAt((new \DateTimeImmutable($at))->getTimestamp());
			$reading->setValue($value);
			$reading->setFlagged(in_array($at, $flagged, true));
			$chain[] = $reading;
		}

		return $chain;
	}

	private static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable('2026-09-22T12:00:00Z');
	}

	/**
	 * @return array<string, array{int, array<string, int>, list<string>, ?string}>
	 */
	public static function estimates(): array {
		return [
			// 1 000 km in 50 days is 20 a day; 1 000 more is 50 days after the last Reading.
			'the pace carries on from the last Reading' => [12000, ['2026-08-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11000], [], '2026-11-09'],
			// 1 500 km in 50 days is 30 a day; 500 more is 16⅔ days, early on 7 October.
			'a part day lands on the day it falls in' => [12000, ['2026-08-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11500], [], '2026-10-07'],
			'one Reading is no pace' => [12000, ['2026-09-20T12:00:00Z' => 11000], [], null],
			'no Reading is no pace' => [12000, [], [], null],
			'29 days is short of the floor' => [12000, ['2026-08-22T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 10580], [], null],
			// 600 km in 30 days, 20 a day; 1 400 more is 70 days.
			'30 days meets it' => [12000, ['2026-08-21T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 10600], [], '2026-11-29'],
			'a Reading older than 90 days is not counted' => [12000, ['2026-06-23T11:59:59Z' => 9000, '2026-09-20T12:00:00Z' => 11000], [], null],
			// 89 days back, 2 000 km in 87 days; 900 more is 39.15 days.
			'one inside 90 days is' => [12900, ['2026-06-25T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 12000], [], '2026-10-29'],
			'a car standing still has no date' => [12000, ['2026-08-01T12:00:00Z' => 11000, '2026-09-20T12:00:00Z' => 11000], [], null],
			'a due km already reached needs no estimate' => [11000, ['2026-08-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11000], [], null],
			'a flagged Reading is not counted' => [12000, ['2026-08-01T12:00:00Z' => 10000, '2026-09-01T12:00:00Z' => 500, '2026-09-20T12:00:00Z' => 11000], ['2026-09-01T12:00:00Z'], '2026-11-09'],
			// Answered as a reset, the counter starts again: only the segment after it has a pace.
			'a reset starts the pace again' => [12000, ['2026-07-01T12:00:00Z' => 90000, '2026-08-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11000], [], '2026-11-09'],
			'and a short segment after it is no pace' => [12000, ['2026-07-01T12:00:00Z' => 90000, '2026-09-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11000], [], null],
			// 100 a day would have reached it on 4 August; a date in the past would read as overdue.
			'a date already past is today' => [14000, ['2026-06-25T12:00:00Z' => 10000, '2026-07-30T12:00:00Z' => 13500], [], '2026-09-22'],
		];
	}

	/**
	 * @param array<string, int> $readings
	 * @param list<string> $flagged
	 */
	#[DataProvider('estimates')]
	public function testAnOdometerReminderEstimatesItsDayFromThePace(int $due, array $readings, array $flagged, ?string $estimate): void {
		$this->assertSame($estimate, ReminderEngine::estimate(self::byOdo($due, 1000), self::chain($readings, $flagged), self::now()));
	}

	/** Either mode is due by km too, so it has a pace to go by; a date reminder has its date. */
	public function testOnlyAReminderByKmIsEstimated(): void {
		$chain = self::chain(['2026-08-01T12:00:00Z' => 10000, '2026-09-20T12:00:00Z' => 11000]);
		$either = self::byOdo(12000, 1000);
		$either->setMode(Reminder::EITHER);
		$either->setDueDate(new \DateTime('2027-03-31'));

		$this->assertSame('2026-11-09', ReminderEngine::estimate($either, $chain, self::now()));
		$this->assertNull(ReminderEngine::estimate(self::byDate('2027-03-31'), $chain, self::now()));
	}

	/** The estimated day is a day where the server is, as "today" is. */
	public function testTheDayIsTheZoneOfNow(): void {
		$chain = self::chain(['2026-08-01T23:30:00Z' => 10000, '2026-09-20T23:30:00Z' => 11000]);
		$berlin = self::now()->setTimezone(new \DateTimeZone('Europe/Berlin'));

		// 20 a day again: 23:30 UTC on 9 November, which in Berlin is 00:30 on the 10th.
		$this->assertSame('2026-11-09', ReminderEngine::estimate(self::byOdo(12000, 1000), $chain, self::now()));
		$this->assertSame('2026-11-10', ReminderEngine::estimate(self::byOdo(12000, 1000), $chain, $berlin));
	}

	/** @return array<string, array{\Closure(Reminder): void, list<string>}> */
	public static function edits(): array {
		return [
			'a new due date moves every date point' => [static fn (Reminder $r) => $r->setDueDate(new \DateTime('2027-04-30')), ['month_before', 'month_start', 'due_date', 'overdue']],
			'a warning ticked off moves its own point' => [static fn (Reminder $r) => $r->setWarnMonthBefore(false), ['month_before']],
			'a warning ticked on too' => [static fn (Reminder $r) => $r->setWarnMonthStart(true), ['month_start']],
			'and the day itself' => [static fn (Reminder $r) => $r->setWarnDueDate(false), ['due_date']],
			'a new due km moves both km points' => [static fn (Reminder $r) => $r->setDueOdo(13000), ['odo', 'odo_due']],
			'a new lead moves the warning by km' => [static fn (Reminder $r) => $r->setLeadOdo(2000), ['odo']],
			'a new title moves nothing' => [static fn (Reminder $r) => $r->setTitle('Tyres'), []],
		];
	}

	/** An edit that moves a point lets it ring again (NotificationService::rearm()). */
	#[DataProvider('edits')]
	public function testAnEditMovesThePointsItChanges(\Closure $edit, array $moved): void {
		$was = self::byOdo(12000, 1000);
		$was->setMode(Reminder::EITHER);
		$was->setDueDate(new \DateTime('2027-03-31'));
		$is = clone $was;
		$edit($is);

		$this->assertSame($moved, ReminderEngine::moved($was, $is));
	}

	/** A receipt is for the due on its point's own axis (ReminderReceiptMapper::claim()). */
	public function testTheKmPointsAreOnTheKmAxisAndTheRestOnTheDate(): void {
		$points = [ReminderEngine::MONTH_BEFORE, ReminderEngine::MONTH_START, ReminderEngine::DUE_DATE, ReminderEngine::OVERDUE, ReminderEngine::ODO, ReminderEngine::ODO_DUE];

		$this->assertSame([ReminderEngine::ODO, ReminderEngine::ODO_DUE], array_values(array_filter($points, ReminderEngine::byKm(...))));
	}
}
