<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checks every service runs on a posted field. The message names the field, because it is what
 * the sheet shows the person.
 */
class FieldTest extends TestCase {
	public function testTextPassesAStringWithinItsLength(): void {
		$this->assertSame('Büro', Field::text('purpose', 'Büro', 4));
	}

	public function testTextRefusesWhatIsNotAString(): void {
		$this->expectExceptionObject(new \InvalidArgumentException('purpose is text'));
		Field::text('purpose', 12, 255);
	}

	public function testTextRefusesRatherThanTruncates(): void {
		$this->expectExceptionObject(new \InvalidArgumentException('purpose is longer than 3 characters'));
		Field::text('purpose', 'Büro', 3);
	}

	public function testWordPassesOneOfItsVocabulary(): void {
		$this->assertSame('private', Field::word('category', 'private', ['business', 'private']));
	}

	/** @return array<string, array{mixed}> */
	public static function notAWord(): array {
		return ['unknown' => ['leisure'], 'not a string' => [1]];
	}

	#[DataProvider('notAWord')]
	public function testWordRefusesAnythingElse(mixed $value): void {
		$this->expectExceptionObject(new \InvalidArgumentException('category is one of business, private'));
		Field::word('category', $value, ['business', 'private']);
	}

	public function testCountPassesAWholeNumberFromJsonOrAForm(): void {
		$this->assertSame(0, Field::count('value', 0, Field::COUNTER));
		$this->assertSame(1234, Field::count('value', '1234', Field::COUNTER));
	}

	/** @return array<string, array{mixed}> */
	public static function notACount(): array {
		return ['negative' => [-1], 'fraction' => ['1.5'], 'word' => ['ten']];
	}

	#[DataProvider('notACount')]
	public function testCountRefusesAnythingElse(mixed $value): void {
		$this->expectExceptionObject(new \InvalidArgumentException('value is a whole number, never negative'));
		Field::count('value', $value, Field::COUNTER);
	}

	/** The bounds docs/security.md states: a counter stops at a billion, money at 10^12 cents. */
	public function testCountTakesItsMaximumAndRefusesOneMore(): void {
		$this->assertSame(1_000_000_000, Field::count('odo', 1_000_000_000, Field::COUNTER));
		$this->assertSame(1_000_000_000_000, Field::count('cost', '1000000000000', Field::MONEY));
		$this->expectExceptionObject(new \InvalidArgumentException('odo is 1000000000 at most'));
		Field::count('odo', 1_000_000_001, Field::COUNTER);
	}

	public function testMoneyRefusesOneCentOverItsMaximum(): void {
		$this->expectExceptionObject(new \InvalidArgumentException('cost is 1000000000000 at most'));
		Field::read('cost', 'count', Field::MONEY, 1_000_000_000_001);
	}

	public function testANoteTakesTenThousandCharactersAndNoMore(): void {
		$page = str_repeat('ä', 10_000);
		$this->assertSame($page, Field::read('notes', 'text', Field::TEXT, $page));
		$this->expectExceptionObject(new \InvalidArgumentException('notes is longer than 10000 characters'));
		Field::read('notes', 'text', Field::TEXT, $page . 'ä');
	}

	/**
	 * A count or a text without a bound is a mistake in the caller, not a field to let through: a
	 * 500, not a 400 that blames the request.
	 */
	public function testReadRefusesACountOrATextWithoutABound(): void {
		foreach ([['count', 1], ['text', 'a']] as [$kind, $value]) {
			try {
				Field::read('odo', $kind, null, $value);
				$this->fail($kind . ' read without a bound');
			} catch (\LogicException $e) {
				$this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
				$this->assertSame('odo has no bound', $e->getMessage());
			}
		}
	}

	public function testOffsetPassesTheRealRange(): void {
		$this->assertSame(-720, Field::offset('read_at_off', -720));
		$this->assertSame(840, Field::offset('read_at_off', '840'));
	}

	/** @return array<string, array{mixed}> */
	public static function notAnOffset(): array {
		return ['below -12:00' => [-721], 'above +14:00' => [841], 'word' => ['CET']];
	}

	#[DataProvider('notAnOffset')]
	public function testOffsetRefusesAnythingElse(mixed $value): void {
		$this->expectExceptionObject(new \InvalidArgumentException('read_at_off is a UTC offset in minutes'));
		Field::offset('read_at_off', $value);
	}
}
