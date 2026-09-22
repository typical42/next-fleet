<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;
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

	/** Withdrawing the work that moved it on puts the occurrence back at that work's day and km. */
	public function testARetreatTakesBackTheAdvanceFromTheSameBase(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-08-31', 133500);

		$this->assertTrue(ReminderEngine::retreat($reminder, '2027-08-31', 133500));
		$this->assertSame('2027-08-31', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(133500, $reminder->getDueOdo());
		$this->assertSame(1, $reminder->getOccurrence());
	}

	/**
	 * A reminder that moved on from somewhere else - a dismissal, an edit, another record - is not
	 * this base's to take back.
	 */
	public function testARetreatFromAnotherBaseChangesNothing(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-03-02', 133500);

		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-03-01', 133500));
		$this->assertFalse(ReminderEngine::retreat($reminder, '2027-03-02', 133400));
		$this->assertSame('2028-03-02', $reminder->getDueDate()?->format('Y-m-d'));
		$this->assertSame(148500, $reminder->getDueOdo());
		$this->assertSame(2, $reminder->getOccurrence());
	}

	/** Undoing the withdrawal may move it on again only while nothing else has moved it. */
	public function testItStandsAtTheBaseARetreatLeftItAt(): void {
		$reminder = self::oilChange();
		ReminderEngine::advance($reminder, '2027-03-02', 133500);
		ReminderEngine::retreat($reminder, '2027-03-02', 133500);

		$this->assertTrue(ReminderEngine::standsAt($reminder, '2027-03-02', 133500));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-03-03', 133500));
		$this->assertFalse(ReminderEngine::standsAt($reminder, '2027-03-02', 133501));
	}

	/** The first occurrence was never advanced to, and one that does not recur never advances. */
	public function testARetreatNeedsAnAdvanceToTakeBack(): void {
		$first = self::oilChange();
		$first->setDueDate(new \DateTime('2028-03-02'));
		$first->setDueOdo(148500);
		$oneOff = self::byDate('2028-03-02');
		$oneOff->setOccurrence(2);

		$this->assertFalse(ReminderEngine::retreat($first, '2027-03-02', 133500));
		$this->assertFalse(ReminderEngine::retreat($oneOff, '2027-03-02', null));
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
}
