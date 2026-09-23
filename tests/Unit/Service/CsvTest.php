<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\Csv;
use PHPUnit\Framework\TestCase;

/**
 * The file a spreadsheet opens (docs/security.md): UTF-8 with a BOM, comma, CRLF, RFC 4180 quoting,
 * and no cell a spreadsheet would run as a formula.
 */
class CsvTest extends TestCase {
	public function testAFileIsABomAHeaderAndCrlfLines(): void {
		$csv = Csv::of(['date', 'distance'], [['2026-03-01 08:00', 450], ['2026-03-02 08:00', null]]);

		$this->assertSame("\u{FEFF}date,distance\r\n2026-03-01 08:00,450\r\n2026-03-02 08:00,\r\n", $csv);
	}

	public function testACellWithASeparatorQuoteOrNewlineIsQuoted(): void {
		$csv = Csv::of(['to'], [['Hamburg, Hafenstraße 1'], ['Der "Laden"'], ["zwei\nZeilen"]]);

		$this->assertSame("\u{FEFF}to\r\n\"Hamburg, Hafenstraße 1\"\r\n\"Der \"\"Laden\"\"\"\r\n\"zwei\nZeilen\"\r\n", $csv);
	}

	/** @return iterable<string, array{string, string}> */
	public static function formulas(): iterable {
		yield 'equals' => ['=HYPERLINK("http://x")', "\"'=HYPERLINK(\"\"http://x\"\")\""];
		yield 'plus' => ['+49 170', "'+49 170"];
		yield 'minus' => ['-2+3', "'-2+3"];
		yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
		yield 'tab' => ["\tx", "'\tx"];
		yield 'carriage return' => ["\rx", "\"'\rx\""];
	}

	/**
	 * A purpose of `=cmd|…` must reach a colleague's Excel as text.
	 *
	 * @dataProvider formulas
	 */
	public function testAStringAFormulaWouldStartWithIsDefused(string $value, string $cell): void {
		$this->assertSame("\u{FEFF}purpose\r\n" . $cell . "\r\n", Csv::of(['purpose'], [[$value]]));
	}

	/** A number is ours, not the user's: a refund of -500 cents stays a number. */
	public function testANegativeNumberIsANumber(): void {
		$this->assertSame("\u{FEFF}amount\r\n-500\r\n", Csv::of(['amount'], [[-500]]));
	}
}
