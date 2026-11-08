<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\ClosedDue;
use OCA\NextFleet\Service\ReminderEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the record above keeps once the close below it is taken back. */
class ClosedDueTest extends TestCase {
	/**
	 * The lower record's due, the later snooze, and the occurrence of that due; the upper record's
	 * own occurrence and the due its own close set stay.
	 *
	 * @return array<string, array{ClosedDue, ClosedDue, ClosedDue}>
	 */
	public static function handsUp(): array {
		return [
			'the lower snooze later' => [
				new ClosedDue('2027-03-01', 60000, '2032-06-01', 1, nextDay: '2028-02-10', nextOdo: 73500),
				new ClosedDue('2028-02-10', 73500, '2031-05-01', 2, nextDay: '2029-02-01', nextOdo: 88000),
				new ClosedDue('2027-03-01', 60000, '2032-06-01', 2, 1, '2029-02-01', 88000),
			],
			'the upper snooze later' => [
				new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10', nextOdo: 73500),
				new ClosedDue('2028-02-10', 73500, '2031-05-01', 2, nextDay: '2029-02-01', nextOdo: 88000),
				new ClosedDue('2027-03-01', 60000, '2031-05-01', 2, 1, '2029-02-01', 88000),
			],
			'a due the lower record took over itself' => [
				new ClosedDue('2027-03-01', 60000, null, 2, 1, '2029-02-01', 88000),
				new ClosedDue('2029-02-01', 88000, null, 3, nextDay: '2030-01-15', nextOdo: 102000),
				new ClosedDue('2027-03-01', 60000, null, 3, 1, '2030-01-15', 102000),
			],
			'a km added between the closes' => [
				new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10'),
				new ClosedDue('2028-02-10', 74000, null, 2, nextDay: '2029-02-01', nextOdo: 88000),
				new ClosedDue('2027-03-01', 74000, null, 2, 1, '2029-02-01', 88000),
			],
			'a date added between the closes' => [
				new ClosedDue(null, 60000, null, 1, nextOdo: 73500),
				new ClosedDue('2028-03-15', 73500, null, 2, nextDay: '2029-03-01', nextOdo: 88000),
				new ClosedDue('2028-03-15', 60000, null, 2, 1, '2029-03-01', 88000),
			],
			'a date removed between the closes' => [
				new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10', nextOdo: 73500),
				new ClosedDue(null, 73500, null, 2, nextOdo: 88000),
				new ClosedDue(null, 60000, null, 2, 1, null, 88000),
			],
			'a km removed between the closes' => [
				new ClosedDue('2027-03-01', 60000, null, 1, nextDay: '2028-02-10', nextOdo: 73500),
				new ClosedDue('2028-02-10', null, null, 2, nextDay: '2029-02-01'),
				new ClosedDue('2027-03-01', null, null, 2, 1, '2029-02-01'),
			],
		];
	}

	#[DataProvider('handsUp')]
	public function testTheRecordAboveTakesOverWhatTheLowerOneKept(ClosedDue $below, ClosedDue $above, ClosedDue $handed): void {
		$this->assertEquals($handed, $above->handedFrom($below));
		$this->assertTrue($handed->took($below));
		$this->assertFalse($above->took($below));
	}

	/**
	 * Every pair where the record above follows the lower one (ReminderEngine::follows()): each
	 * axis of the lower record keeps a due or none and sets a next due or none, and the record above keeps none, that next due, or another value.
	 * Pairs that keep a due with no next due on an axis, or a next due with no kept due, break the
	 * rule ClosedDue states; they are here so the round trip holds without it too.
	 *
	 * @return iterable<string, array{ClosedDue, ClosedDue}>
	 */
	public static function followingPairs(): iterable {
		$days = [[null, null, [null]], ['2027-03-01', null, [null, '2028-06-01']], [null, '2028-02-10', [null, '2028-02-10', '2028-06-01']], ['2027-03-01', '2028-02-10', [null, '2028-02-10', '2028-06-01']]];
		$kms = [[null, null, [null]], [60000, null, [null, 74000]], [null, 73500, [null, 73500, 74000]], [60000, 73500, [null, 73500, 74000]]];
		foreach ($days as [$day, $nextDay, $aboveDays]) {
			foreach ($kms as [$odo, $nextOdo, $aboveKms]) {
				$below = new ClosedDue($day, $odo, '2031-03-01', 1, nextDay: $nextDay, nextOdo: $nextOdo);
				foreach ($aboveDays as $aboveDay) {
					foreach ($aboveKms as $aboveKm) {
						$above = new ClosedDue($aboveDay, $aboveKm, '2032-06-01', 2, nextDay: '2029-02-01', nextOdo: 88000);
						if (ReminderEngine::follows($below, $above)) {
							yield json_encode([$below, $above]) => [$below, $above];
						}
					}
				}
			}
		}
	}

	/**
	 * Undo of the lower record's delete gives the record above back its own due on each axis
	 * (ReminderEngine::keptAbove()), its occurrence, and the due its own close set. The snooze and
	 * the occurrence of the due come back from what the lower record kept for its undo.
	 */
	#[DataProvider('followingPairs')]
	public function testTheRecordAboveGetsBackWhatItKept(ClosedDue $below, ClosedDue $above): void {
		$handed = $above->handedFrom($below);
		$back = ReminderEngine::keptAbove($below, $handed);

		$this->assertSame([$above->day, $above->odo, $above->occurrence, $above->nextDay, $above->nextOdo], [$back->day, $back->odo, $back->occurrence, $back->nextDay, $back->nextOdo]);
		$this->assertTrue($handed->took($below));
	}

	/** An axis the lower record kept nothing for is the person's on the record above. */
	public function testTheRecordAboveTookWhatTheLowerOneKeptOnEachAxisItKept(): void {
		$below = new ClosedDue('2027-03-01', null, null, 1, nextDay: '2028-02-10');

		$this->assertTrue((new ClosedDue('2027-03-01', 74000, null, 2))->took($below));
		$this->assertFalse((new ClosedDue('2027-03-02', 74000, null, 2))->took($below));
		$this->assertFalse((new ClosedDue('2027-03-01', 60000, null, 2))->took(new ClosedDue('2027-03-01', 60001, null, 1, nextOdo: 75001)));
	}
}
