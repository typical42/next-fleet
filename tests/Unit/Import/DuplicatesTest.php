<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Import\Duplicates;
use OCA\NextFleet\Import\Proposal;
use PHPUnit\Framework\TestCase;

/**
 * Importing the same export twice must not double a vehicle's history.
 */
class DuplicatesTest extends TestCase {
	/** 2026-03-14 09:30 in Berlin (UTC+1). */
	private const MORNING = 1773477000;

	private static function fill(int $at, int $off, ?int $odo, int $amount): Energy {
		$fill = new Energy();
		$fill->setFilledAt($at);
		$fill->setFilledAtOff($off);
		$fill->setOdo($odo);
		$fill->setAmount($amount);

		return $fill;
	}

	private static function reading(string $source, string $counter, int $value): OdoReading {
		$reading = new OdoReading();
		$reading->setReadAt(self::MORNING);
		$reading->setReadAtOff(60);
		$reading->setValue($value);
		$reading->setSourceType($source);
		$reading->setCounter($counter);

		return $reading;
	}

	public function testAFillUpOnTheSameDayAtTheSameCounterIsADuplicate(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, 52000, 40000)]);
		$proposal = new Proposal(Proposal::ENERGY, [
			'filled_at' => self::MORNING + 3 * 3600,
			'filled_at_off' => 60,
			'odo' => 52000,
			'amount' => 41000,
		], 7);

		$marked = $duplicates->mark($proposal);

		$this->assertSame(Proposal::DUPLICATE, $marked->outcome);
		$this->assertSame(7, $marked->row);
		$this->assertSame($proposal->fields, $marked->fields);
	}

	public function testTheDayIsTheLocalOneEachWasWrittenAt(): void {
		// 23:30 on the 14th in Berlin; an hour later it is the 15th there, still the 14th in UTC.
		$late = self::MORNING + 14 * 3600;
		$duplicates = Duplicates::of([self::fill($late, 60, 52000, 40000)]);

		$nextDay = new Proposal(Proposal::ENERGY, ['filled_at' => $late + 3600, 'filled_at_off' => 60, 'odo' => 52000, 'amount' => 40000], 2);
		$sameDayInLisbon = new Proposal(Proposal::ENERGY, ['filled_at' => $late + 1800, 'filled_at_off' => 0, 'odo' => 52000, 'amount' => 40000], 3);

		$this->assertNull($duplicates->mark($nextDay)->outcome);
		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark($sameDayInLisbon)->outcome);
	}

	public function testAnotherCounterOnTheSameDayIsNoDuplicate(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, 52000, 40000)]);
		$proposal = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'odo' => 52400, 'amount' => 40000], 2);

		$this->assertNull($duplicates->mark($proposal)->outcome);
	}

	public function testWithoutACounterTheSameAmountIsADuplicate(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, 52000, 40000)]);
		$same = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'odo' => null, 'amount' => 40000], 2);
		$other = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'odo' => null, 'amount' => 39000], 3);

		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark($same)->outcome);
		$this->assertNull($duplicates->mark($other)->outcome);
	}

	public function testAnEntryWithoutACounterIsMatchedOnItsAmount(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, null, 40000)]);
		$proposal = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'odo' => 52000, 'amount' => 40000], 2);

		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark($proposal)->outcome);
	}

	public function testARowWithNeitherCounterNorAmountIsNeverADuplicate(): void {
		$record = new Maintenance();
		$record->setDoneAt(self::MORNING);
		$record->setDoneAtOff(60);
		$duplicates = Duplicates::of([$record]);
		$proposal = new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING, 'done_at_off' => 60, 'odo' => null, 'cost' => null], 2);

		$this->assertNull($duplicates->mark($proposal)->outcome);
	}

	public function testAChargeIsNoDuplicateOfAFillUpAtTheSameCounter(): void {
		$fill = self::fill(self::MORNING, 60, 52000, 40000);
		$fill->setEnergy('petrol');
		$duplicates = Duplicates::of([$fill]);
		$charge = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'energy' => 'electric', 'odo' => 52000, 'amount' => 9000], 2);

		$this->assertNull($duplicates->mark($charge)->outcome);
	}

	public function testEachKindIsMatchedOnItsOwnCounterAndAmount(): void {
		$record = new Maintenance();
		$record->setDoneAt(self::MORNING);
		$record->setDoneAtOff(60);
		$record->setOdo(51000);
		$record->setCost(28900);
		$tax = new Expense();
		$tax->setSpentAt(self::MORNING);
		$tax->setSpentAtOff(60);
		$tax->setAmount(14000);
		$duplicates = Duplicates::of([$record, $tax, self::reading(OdoReading::MANUAL, OdoReading::MAIN, 50000)]);

		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark(new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING, 'done_at_off' => 60, 'odo' => 51000, 'cost' => null], 2))->outcome);
		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark(new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING, 'done_at_off' => 60, 'odo' => null, 'cost' => 28900], 3))->outcome);
		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark(new Proposal(Proposal::EXPENSE, ['spent_at' => self::MORNING, 'spent_at_off' => 60, 'amount' => 14000], 4))->outcome);
		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark(new Proposal(Proposal::ODOMETER, ['read_at' => self::MORNING, 'read_at_off' => 60, 'value' => 50000], 5))->outcome);
	}

	public function testAnotherKindOnTheSameDayAndCounterIsNoDuplicate(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, 52000, 40000)]);
		$proposal = new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING, 'done_at_off' => 60, 'odo' => 52000, 'cost' => 40000], 2);

		$this->assertNull($duplicates->mark($proposal)->outcome);
	}

	public function testOnlyAnOdometerEntryOnTheSameChainIsAnOdometerDuplicate(): void {
		// A fill-up's own Reading is a Reading, not an Odometer Entry (CONTEXT.md).
		$duplicates = Duplicates::of([
			self::reading(OdoReading::ENERGY, OdoReading::MAIN, 50000),
			self::reading(OdoReading::MANUAL, OdoReading::SECOND, 1200),
		]);

		$this->assertNull($duplicates->mark(new Proposal(Proposal::ODOMETER, ['read_at' => self::MORNING, 'read_at_off' => 60, 'value' => 50000], 2))->outcome);
		$this->assertNull($duplicates->mark(new Proposal(Proposal::ODOMETER, ['read_at' => self::MORNING, 'read_at_off' => 60, 'value' => 1200], 3))->outcome);
		$this->assertSame(Proposal::DUPLICATE, $duplicates->mark(new Proposal(Proposal::ODOMETER, ['read_at' => self::MORNING, 'read_at_off' => 60, 'value' => 1200, 'counter' => OdoReading::SECOND], 4))->outcome);
	}

	public function testAnUnreadableRowStaysUnreadable(): void {
		$duplicates = Duplicates::of([self::fill(self::MORNING, 60, 52000, 40000)]);
		$proposal = new Proposal(Proposal::ENERGY, ['odo' => 52000], 9, Proposal::UNREADABLE, 'date', 'Date');

		$this->assertSame($proposal, $duplicates->mark($proposal));
	}

	public function testADeletedEntryIsNoLongerThere(): void {
		$deleted = self::fill(self::MORNING, 60, 52000, 40000);
		$deleted->setDeletedAt(self::MORNING + 86400);
		$duplicates = Duplicates::of([$deleted]);
		$proposal = new Proposal(Proposal::ENERGY, ['filled_at' => self::MORNING, 'filled_at_off' => 60, 'odo' => 52000, 'amount' => 40000], 2);

		$this->assertNull($duplicates->mark($proposal)->outcome);
	}

	/** What the import loads to compare with: per kind, two days around its rows, none unread. */
	public function testTheSpanToLoadCoversEachKindsRowsWithTwoDaysEitherSide(): void {
		$day = 86400;
		$span = Duplicates::span([
			new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING + 10 * $day, 'done_at_off' => 60, 'title' => 'Inspektion'], 2),
			new Proposal(Proposal::EXPENSE, ['spent_at' => self::MORNING, 'spent_at_off' => 60, 'amount' => 12000], 3),
			new Proposal(Proposal::MAINTENANCE, ['done_at' => self::MORNING, 'done_at_off' => 60, 'title' => 'Reifen'], 4),
			new Proposal(Proposal::EXPENSE, [], 5, Proposal::UNREADABLE, 'date', 'Datum'),
		]);

		$this->assertSame([
			Proposal::MAINTENANCE => [self::MORNING - 2 * $day, self::MORNING + 12 * $day + 1],
			Proposal::EXPENSE => [self::MORNING - 2 * $day, self::MORNING + 2 * $day + 1],
		], $span);
		$this->assertSame([], Duplicates::span([new Proposal(Proposal::ENERGY, [], 2, Proposal::UNREADABLE, 'date', 'Date')]));
	}
}
