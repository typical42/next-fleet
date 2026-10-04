<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Exception\UnreadableCellException;
use OCA\NextFleet\Import\Values;
use PHPUnit\Framework\TestCase;

/**
 * Every cell here is text someone else's tool wrote; a value is read only when it says one thing.
 */
class ValuesTest extends TestCase {
	private static function reason(callable $read): ?string {
		try {
			$read();
		} catch (UnreadableCellException $e) {
			return $e->reason;
		}

		return null;
	}

	/** @return iterable<string, array{string, string}> */
	public static function decimals(): iterable {
		yield 'a whole number' => ['1234', '1234'];
		yield 'a decimal period' => ['65.4', '65.4'];
		yield 'a decimal comma' => ['65,40', '65.40'];
		yield 'a period thousands mark under a comma' => ['1.234,56', '1234.56'];
		yield 'a comma thousands mark under a period' => ['1,234.56', '1234.56'];
		yield 'thousands marks only, repeated' => ['1.234.567', '1234567'];
		yield 'a leading zero is a decimal, never a thousands group' => ['0,123', '0.123'];
		yield 'a sign' => ['-12,5', '-12.5'];
		yield 'whitespace around' => [' 42 ', '42'];
	}

	/** @dataProvider decimals */
	public function testADecimalSaysItsOwnMarkWhenItCan(string $cell, string $read): void {
		$this->assertSame($read, Values::decimal($cell, null));
	}

	public function testAnEmptyCellIsNoValue(): void {
		$this->assertNull(Values::decimal('  ', null));
	}

	/** @return iterable<string, array{string}> */
	public static function ambiguous(): iterable {
		yield 'a comma before three digits' => ['1,234'];
		yield 'a period before three digits' => ['12.345'];
	}

	/** @dataProvider ambiguous */
	public function testOneMarkBeforeThreeDigitsIsReadOnlyWithTheColumnsEvidence(string $cell): void {
		$this->assertSame('ambiguous_number', self::reason(fn () => Values::decimal($cell, null)));
	}

	public function testTheColumnsMarkDecidesAnAmbiguousCell(): void {
		$this->assertSame('1.234', Values::decimal('1,234', ','));
		$this->assertSame('1234', Values::decimal('1,234', '.'));
	}

	public function testAColumnShowsItsMarkInAnyCellThatSaysIt(): void {
		$this->assertSame(',', Values::decimalMark(['1,234', '', '12,5', '7']));
		$this->assertSame('.', Values::decimalMark(['1,234', '1,234.5']));
	}

	public function testAColumnWithoutEvidenceOrWithContradictionsShowsNoMark(): void {
		$this->assertNull(Values::decimalMark(['1,234', '7', 'n/a']));
		$this->assertNull(Values::decimalMark(['12,5', '12.5']));
	}

	/** @return iterable<string, array{string}> */
	public static function notNumbers(): iterable {
		yield 'a formula' => ['=1+2'];
		yield 'a unit' => ['45 l'];
		yield 'a misplaced thousands mark' => ['12.34,5'];
		yield 'two decimal marks' => ['1,2,3'];
		yield 'a bare mark' => [','];
		yield 'a trailing mark' => ['12,'];
	}

	/** @dataProvider notNumbers */
	public function testWhatIsNotANumberIsUnreadable(string $cell): void {
		$this->assertSame('number', self::reason(fn () => Values::decimal($cell, ',')));
	}

	public function testAnEndlessNumberIsTooLargeRatherThanWorkedOn(): void {
		$this->assertSame('too_large', self::reason(fn () => Values::decimal(str_repeat('9', 40), null)));
	}

	/** @return iterable<string, array{string, string, string, int}> worked by hand from the unit's definition */
	public static function conversions(): iterable {
		yield 'kilometres stay' => ['12345', 'distance', 'km', 12345];
		yield 'a mile is 1.609344 km' => ['100', 'distance', 'mi', 161];
		yield 'three tenths of a mile, under half a km' => ['0.3', 'distance', 'mi', 0];
		yield 'a litre is 1000 ml' => ['45.123', 'volume', 'l', 45123];
		yield 'a US gallon is 3785.411784 ml' => ['10', 'volume', 'us_gal', 37854];
		yield 'a UK gallon is 4546.09 ml' => ['10', 'volume', 'uk_gal', 45461];
		yield 'a kWh is 1000 Wh' => ['42.5', 'energy', 'kwh', 42500];
		yield 'money in cents' => ['65.4', 'money', 'major', 6540];
	}

	/** @dataProvider conversions */
	public function testAValueBecomesItsCanonicalUnit(string $decimal, string $dimension, string $unit, int $canonical): void {
		$this->assertSame($canonical, Values::canonical($decimal, $dimension, $unit));
	}

	public function testRoundingIsHalfUpOnceAtTheEdge(): void {
		$this->assertSame(101, Values::canonical('1.005', 'money', 'major'));
		$this->assertSame(100, Values::canonical('1.00499999', 'money', 'major'));
		$this->assertSame(-101, Values::canonical('-1.005', 'money', 'major'));
	}

	public function testFloatNoiseInACellIsReadExactly(): void {
		$this->assertSame(300, Values::canonical('0.30000000000000004', 'volume', 'l'));
	}

	public function testAValueBeyondAnIntegerIsTooLarge(): void {
		$this->assertSame('too_large', self::reason(fn () => Values::canonical('99999999999999999999', 'money', 'major')));
	}

	/** Eighteen digits are read, and a half on top of them still carries; nineteen are refused. */
	public function testEighteenDigitsAreTheMostAValueKeeps(): void {
		$this->assertSame(999_999_999_999_999_999, Values::canonical('999999999999999999', 'distance', 'km'));
		$this->assertSame(1_000_000_000_000_000_000, Values::canonical('999999999999999999.5', 'distance', 'km'));
		$this->assertSame('too_large', self::reason(fn () => Values::canonical('1000000000000000000', 'distance', 'km')));
	}

	/** A million gallons shows every digit of each factor: one rounded or cut short would differ here. */
	public function testBothGallonsConvertByTheirFullDefinitions(): void {
		$this->assertSame(3_785_411_784, Values::canonical('1000000', 'volume', 'us_gal'));
		$this->assertSame(4_546_090_000, Values::canonical('1000000', 'volume', 'uk_gal'));
		// 0.5 US gal is 1892.705892 ml, which rounds up; 0.5 UK gal is 2273.045 ml, which does not.
		$this->assertSame(1893, Values::canonical('0.5', 'volume', 'us_gal'));
		$this->assertSame(2273, Values::canonical('0.5', 'volume', 'uk_gal'));
	}

	public function testAUnitOfAnotherDimensionIsAProgrammingError(): void {
		$this->expectException(\InvalidArgumentException::class);
		Values::canonical('1', 'distance', 'l');
	}

	public function testAColumnOfSlashDatesShowsItsOrderWhenOneValueFitsOnlyOne(): void {
		$this->assertSame('dmy', Values::dateOrder(['01/02/2026', '', '13/02/2026']));
		$this->assertSame('mdy', Values::dateOrder(['01/02/2026', '2/13/2026 3:07 PM']));
	}

	public function testAColumnThatFitsBothOrdersOrNeitherLeavesTheQuestionOpen(): void {
		$this->assertNull(Values::dateOrder(['01/02/2026', '12/11/2026']));
		$this->assertNull(Values::dateOrder(['13/02/2026', '02/13/2026']));
	}

	public function testDatesThatSayTheirOwnOrderAskNothing(): void {
		$this->assertSame('dmy', Values::dateOrder(['01.02.2026', '2026-02-01', 'n/a']));
	}

	/** @return iterable<string, array{string, string, string, string}> */
	public static function moments(): iterable {
		yield 'a German date is noon, in winter time' => ['01.03.2026', 'dmy', 'Europe/Berlin', '2026-03-01T12:00:00+01:00'];
		yield 'an ISO date is noon, in summer time' => ['2026-07-01', 'dmy', 'Europe/Berlin', '2026-07-01T12:00:00+02:00'];
		yield 'single digits' => ['5.1.2026', 'dmy', 'Europe/Berlin', '2026-01-05T12:00:00+01:00'];
		yield 'a slash date day first' => ['7/1/2026', 'dmy', 'Europe/Berlin', '2026-01-07T12:00:00+01:00'];
		yield 'a slash date month first' => ['7/1/2026', 'mdy', 'America/New_York', '2026-07-01T12:00:00-04:00'];
		yield 'a time' => ['01.03.2026 08:15', 'dmy', 'Europe/Berlin', '2026-03-01T08:15:00+01:00'];
		yield 'a time with seconds' => ['2026-03-01 08:15:30', 'dmy', 'Europe/Berlin', '2026-03-01T08:15:30+01:00'];
		yield 'an ISO time' => ['2026-03-01T08:15', 'dmy', 'Europe/Berlin', '2026-03-01T08:15:00+01:00'];
		yield 'an afternoon' => ['1/5/2026 3:07 PM', 'mdy', 'America/New_York', '2026-01-05T15:07:00-05:00'];
		yield 'midnight on a twelve-hour clock' => ['1/5/2026 12:30 am', 'mdy', 'America/New_York', '2026-01-05T00:30:00-05:00'];
		// .NET with ICU 72 or later puts a narrow no-break space before the half.
		yield 'a narrow space before PM' => ["1/5/2026 3:07:00\u{202F}PM", 'mdy', 'America/New_York', '2026-01-05T15:07:00-05:00'];
	}

	/** @dataProvider moments */
	public function testADateBecomesAMomentAndItsOffset(string $cell, string $order, string $zone, string $local): void {
		$moment = new \DateTimeImmutable($local);

		$this->assertSame(
			['at' => $moment->getTimestamp(), 'off' => intdiv($moment->getOffset(), 60)],
			Values::moment($cell, $order, new \DateTimeZone($zone)),
		);
	}

	/** @return iterable<string, array{string, string}> */
	public static function notDates(): iterable {
		yield 'a day the month lacks' => ['31.02.2026', 'dmy'];
		yield 'no thirteenth month' => ['13/13/2026', 'dmy'];
		yield 'a slash date against the column' => ['13/01/2026', 'mdy'];
		yield 'an hour past the day' => ['2026-03-01 25:00', 'dmy'];
		yield 'a thirteenth hour on a twelve-hour clock' => ['1/5/2026 13:00 PM', 'mdy'];
		yield 'a two-digit year' => ['01.03.26', 'dmy'];
		yield 'a formula' => ['=TODAY()', 'dmy'];
		yield 'a time the clock skipped' => ['2026-03-29 02:30', 'dmy'];
		// An entry before the epoch is one no entry route takes: the preview must not count it in.
		yield 'a day before 1970' => ['31.12.1969', 'dmy'];
		yield 'the epoch\'s own day, before it began in Berlin' => ['1970-01-01 00:30', 'dmy'];
	}

	public function testATimeTheClockShowedTwiceIsTheFirst(): void {
		$this->assertSame(
			['at' => (new \DateTimeImmutable('2026-10-25T02:30:00+02:00'))->getTimestamp(), 'off' => 120],
			Values::moment('2026-10-25 02:30', 'dmy', new \DateTimeZone('Europe/Berlin')),
		);
	}

	/** @dataProvider notDates */
	public function testWhatIsNotADateIsUnreadable(string $cell, string $order): void {
		$this->assertSame('date', self::reason(fn () => Values::moment($cell, $order, new \DateTimeZone('Europe/Berlin'))));
	}

	public function testAnEmptyDateIsNoValue(): void {
		$this->assertNull(Values::moment('', 'dmy', new \DateTimeZone('UTC')));
	}

	/** @return iterable<string, array{string, bool}> */
	public static function flags(): iterable {
		yield 'true' => ['True', true];
		yield 'false' => ['false', false];
		yield 'one' => ['1', true];
		yield 'zero' => ['0', false];
		yield 'yes' => [' YES ', true];
		yield 'no' => ['no', false];
		yield 'ja' => ['Ja', true];
		yield 'nein' => ['nein', false];
	}

	/** @dataProvider flags */
	public function testAFlagIsReadInEitherLanguage(string $cell, bool $flag): void {
		$this->assertSame($flag, Values::flag($cell));
	}

	public function testAnEmptyFlagIsNoValue(): void {
		$this->assertNull(Values::flag(''));
	}

	public function testAnythingElseIsAnUnreadableFlag(): void {
		$this->assertSame('flag', self::reason(fn () => Values::flag('on')));
		$this->assertSame('flag', self::reason(fn () => Values::flag('2')));
	}

	/** @return iterable<string, array{string, string, ?string}> */
	public static function money(): iterable {
		yield 'a bare number' => ['42.50', '42.50', null];
		yield 'a symbol before' => ['$1,234.50', '1,234.50', '$'];
		yield 'a symbol after, behind a no-break space' => ["42,50\u{00A0}€", '42,50', '€'];
		yield 'a code' => ['CHF 12.00', '12.00', 'CHF'];
		yield 'spaces as thousands marks' => ["1\u{202F}234,56\u{00A0}€", '1234,56', '€'];
		yield 'brackets for a negative' => ['($12.00)', '-12.00', '$'];
		yield 'a sign before the symbol' => ['-$12.00', '-12.00', '$'];
		yield 'empty' => ['  ', '', null];
		yield 'a Danish sign, ending in a period' => ['1.234,56 kr.', '1.234,56', 'kr.'];
		yield 'Swiss apostrophes as thousands marks' => ['CHF 1’234.50', '1234.50', 'CHF'];
		yield 'a word without a number is no mark' => ['n/a', 'n/a', null];
		yield 'nor are three capitals' => ['TBD', 'TBD', null];
		// .NET writes U+2212 in sv, nb and fi; a spreadsheet may write an en dash.
		yield 'a minus sign' => ["\u{2212}120,00\u{00A0}kr", '-120,00', 'kr'];
		yield 'an en dash' => ["\u{2013}12.00", '-12.00', null];
		yield 'a sign before a real' => ['R$ 12,00', '12,00', 'R$'];
	}

	/** @return iterable<string, array{string}> */
	public static function noMarks(): iterable {
		yield 'a tilde' => ['~12'];
		yield 'a percentage' => ['12%'];
		yield 'a hash' => ['#12'];
	}

	/**
	 * A mark is a currency sign or letters; anything else stays on the number, which then reads as
	 * none.
	 *
	 * @dataProvider noMarks
	 */
	public function testWhatIsNeitherASignNorLettersIsNoCurrencyMark(string $cell): void {
		$this->assertSame('number', self::reason(fn () => Values::decimal(Values::money($cell)[0], null)));
	}

	/** @dataProvider money */
	public function testAMoneyCellLosesItsCurrencyMarkAndSaysWhichItWas(string $cell, string $number, ?string $mark): void {
		$this->assertSame([$number, $mark], Values::money($cell));
	}

	public function testWordsAroundANumberAreNoCurrencyMark(): void {
		$this->assertSame('number', self::reason(fn () => Values::decimal(Values::money('about 40')[0], null)));
		$this->assertSame('number', self::reason(fn () => Values::decimal(Values::money('$40 €')[0], null)));
	}

	public function testAMarkMayNameOnlyTheCurrenciesWrittenWithIt(): void {
		$this->assertTrue(Values::mayName('€', 'EUR'));
		$this->assertFalse(Values::mayName('€', 'GBP'));
		$this->assertTrue(Values::mayName('CHF', 'CHF'));
		$this->assertFalse(Values::mayName('USD', 'EUR'));
		// Dollars, crowns and yen are each several currencies, and none of them the euro.
		$this->assertTrue(Values::mayName('$', 'CAD'));
		$this->assertFalse(Values::mayName('$', 'EUR'));
		$this->assertTrue(Values::mayName('kr', 'SEK'));
		$this->assertTrue(Values::mayName('kr.', 'DKK'));
		$this->assertFalse(Values::mayName('¥', 'EUR'));
	}

	public function testAMarkThisAppDoesNotKnowMayNameAny(): void {
		$this->assertTrue(Values::mayName('лв', 'EUR'));
	}
}
