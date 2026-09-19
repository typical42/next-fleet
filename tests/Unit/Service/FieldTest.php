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
		$this->assertSame('any length', Field::text('purpose', 'any length', null));
	}

	public function testTextRefusesWhatIsNotAString(): void {
		$this->expectExceptionObject(new \InvalidArgumentException('purpose is text'));
		Field::text('purpose', 12, null);
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
		$this->assertSame(0, Field::count('value', 0));
		$this->assertSame(1234, Field::count('value', '1234'));
	}

	/** @return array<string, array{mixed}> */
	public static function notACount(): array {
		return ['negative' => [-1], 'fraction' => ['1.5'], 'word' => ['ten']];
	}

	#[DataProvider('notACount')]
	public function testCountRefusesAnythingElse(mixed $value): void {
		$this->expectExceptionObject(new \InvalidArgumentException('value is a whole number, never negative'));
		Field::count('value', $value);
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
