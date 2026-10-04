<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Import\CsvReader;
use PHPUnit\Framework\TestCase;

/**
 * The reader is the attack surface of an import (docs/security.md): every test here is a file
 * someone else wrote.
 */
class CsvReaderTest extends TestCase {
	/** @return resource */
	private static function stream(string $bytes) {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $bytes);
		rewind($stream);

		return $stream;
	}

	/** @return array<int, list<string>> */
	private static function rows(CsvReader $reader): array {
		return iterator_to_array($reader->rows());
	}

	public function testAHeaderAndRowsAreReadAndRowsAreNumberedAsASpreadsheetShowsThem(): void {
		$reader = CsvReader::open(self::stream("Date,Odometer\n2026-03-01,12000\n2026-03-08,12450\n"));

		$this->assertSame(['Date', 'Odometer'], $reader->header);
		$this->assertSame([2 => ['2026-03-01', '12000'], 3 => ['2026-03-08', '12450']], self::rows($reader));
	}

	/** @return iterable<string, array{string, string}> */
	public static function separators(): iterable {
		yield 'semicolon' => ["Datum;Km-Stand;Kosten\r\n01.03.2026;12000;65,40\r\n", ';'];
		yield 'tab' => ["Datum\tKm-Stand\tKosten\n01.03.2026\t12000\t65,40\n", "\t"];
		yield 'comma' => ["Datum,Km-Stand,Kosten\n01.03.2026,12000,\"65,40\"\n", ','];
	}

	/**
	 * A decimal comma in a `;` file is not a separator: the header line decides, not the rows.
	 *
	 * @dataProvider separators
	 */
	public function testTheSeparatorIsTheHeaderLines(string $file, string $separator): void {
		$reader = CsvReader::open(self::stream($file));

		$this->assertSame($separator, $reader->separator);
		$this->assertSame(['Datum', 'Km-Stand', 'Kosten'], $reader->header);
		$this->assertSame([2 => ['01.03.2026', '12000', '65,40']], self::rows($reader));
	}

	public function testASeparatorInsideAQuotedHeaderDoesNotCount(): void {
		$reader = CsvReader::open(self::stream("Datum;\"Kosten (€, brutto)\";\"a,b,c\"\n01.03.2026;65,40;x\n"));

		$this->assertSame(';', $reader->separator);
		$this->assertSame(['Datum', 'Kosten (€, brutto)', 'a,b,c'], $reader->header);
	}

	/** Header prose uses `,` more than `;`. */
	public function testATieGoesToTheSemicolon(): void {
		$this->assertSame(';', CsvReader::open(self::stream("Datum;Kosten, brutto\n"))->separator);
	}

	/** A pattern over a long quoted cell would hit PCRE's limits and fall back to `,`. */
	public function testALongQuotedHeaderCellDoesNotHideTheSeparator(): void {
		$reader = CsvReader::open(self::stream('A;"' . str_repeat('a,', 30_000) . "\";B\n1;2;3\n"));

		$this->assertSame(';', $reader->separator);
		$this->assertSame([2 => ['1', '2', '3']], self::rows($reader));
	}

	/** Old Mac line breaks would read as one endless header: say so instead. */
	public function testLineBreaksOfCarriageReturnsAloneAreRefused(): void {
		$this->assertRefused('line_breaks', 1, self::stream("Date,Notes\r2026-03-01,a\r"));
	}

	public function testAQuotedCellKeepsItsQuotesSeparatorsAndLineBreaks(): void {
		$reader = CsvReader::open(self::stream("Date,Notes,Cost\r\n2026-03-01,\"Der \"\"Laden\"\", Ecke\r\nzweite Zeile\",12\r\n2026-03-02,,3\r\n"));

		$this->assertSame([
			2 => ['2026-03-01', "Der \"Laden\", Ecke\r\nzweite Zeile", '12'],
			3 => ['2026-03-02', '', '3'],
		], self::rows($reader));
	}

	/** RFC 4180 has no escape character: a backslash is a character like any other. */
	public function testABackslashIsTextUnlessTheFormatEscapesWithIt(): void {
		$this->assertSame([2 => ['a\\', 'b']], self::rows(CsvReader::open(self::stream("A;B\na\\;b\n"))));
	}

	/** Spritmonitor's export escapes with `\`, in a quoted cell or not; `""` still reads as a quote. */
	public function testAnEscapeCharacterTakesTheNextCharacterAsIs(): void {
		$reader = CsvReader::open(self::stream("A;B;C\nein \\; drin;\"Der \\\"Laden\\\" und \"\"so\"\"\";c\\\\\n"), '\\');

		$this->assertSame([2 => ['ein ; drin', 'Der "Laden" und "so"', 'c\\']], self::rows($reader));
	}

	public function testAnEscapedLineBreakContinuesTheCell(): void {
		$reader = CsvReader::open(self::stream("A;B\nerste\\\nzweite;b\nx;y\n"), '\\');

		$this->assertSame([2 => ["erste\nzweite", 'b'], 3 => ['x', 'y']], self::rows($reader));
	}

	/** No line break follows to escape, so the escape is text; no quote is open to blame. */
	public function testAnEscapeEndingTheFileIsText(): void {
		$this->assertSame([2 => ['a', 'b\\']], self::rows(CsvReader::open(self::stream("A;B\na;b\\"), '\\')));
	}

	/** @param resource $stream */
	private function assertRefused(string $reason, ?int $row, $stream): void {
		try {
			self::rows(CsvReader::open($stream));
			$this->fail('read, expected a refusal: ' . $reason);
		} catch (ImportRefusedException $e) {
			$this->assertSame([$reason, $row], [$e->reason, $e->row]);
		}
	}

	/** @return resource a header and data rows of $width bytes each, without the line break */
	private static function rowsOf(int $count, int $width) {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, "Date,Notes\n");
		$row = '2026-03-01,' . str_repeat('x', $width - 11) . "\n";
		for ($i = 0; $i < $count; $i++) {
			fwrite($stream, $row);
		}
		rewind($stream);

		return $stream;
	}

	public function testSixMegabytesAreRefusedWhereTheCapIsCrossed(): void {
		// 11 header bytes and 420 a row: row 11 906 (the 11 905th data row) ends past 5 000 000.
		$this->assertRefused('too_large', 11_906, self::rowsOf(15_000, 419));
	}

	public function testTwentyThousandRowsAreReadAndOneMoreIsRefused(): void {
		$this->assertCount(CsvReader::MAX_ROWS, self::rows(CsvReader::open(self::rowsOf(20_000, 20))));
		$this->assertRefused('too_many_rows', 20_002, self::rowsOf(20_001, 20));
	}

	/** @return resource a header and data rows of $width one-byte cells, the last row $extra cells wider */
	private static function cellsOf(int $rows, int $width, int $extra) {
		$stream = fopen('php://temp', 'r+b');
		$line = implode(',', array_fill(0, $width, 'a')) . "\n";
		fwrite($stream, $line . str_repeat($line, $rows - 1));
		fwrite($stream, implode(',', array_fill(0, $width + $extra, 'a')) . "\n");
		rewind($stream);

		return $stream;
	}

	/** 20 000 rows of 256 cells pass both of those caps and would still hold five million strings. */
	public function testHalfAMillionCellsAreReadAndOneMoreIsRefused(): void {
		// The header's 25 cells count too: 25 + 19 999 × 25 = 500 000.
		$this->assertCount(19_999, self::rows(CsvReader::open(self::cellsOf(19_999, 25, 0))));
		$this->assertRefused('too_many_cells', null, self::cellsOf(19_999, 25, 1));
	}

	/** Five million bytes are read, the last row ending on the last of them; one more is refused. */
	public function testFiveMillionBytesAreReadAndOneMoreIsRefused(): void {
		$exactly = static function (int $bytes) {
			// 11 header bytes, then rows of 420 bytes and one that takes up the rest.
			$rows = intdiv($bytes - 11, 420) - 1;
			$stream = self::rowsOf($rows, 419);
			fseek($stream, 0, SEEK_END);
			fwrite($stream, '2026-03-02,' . str_repeat('x', $bytes - 11 - $rows * 420 - 12) . "\n");
			self::assertSame($bytes, ftell($stream));
			rewind($stream);

			return $stream;
		};

		$this->assertCount(11_904, self::rows(CsvReader::open($exactly(CsvReader::MAX_BYTES))));
		$this->assertRefused('too_large', 11_905, $exactly(CsvReader::MAX_BYTES + 1));
	}

	/** The record cap counts the record, not its line break, whichever break the file uses. */
	public function testARecordOfExactlySixtyFourKibibytesIsRead(): void {
		$record = '2026-03-02,' . str_repeat('x', CsvReader::MAX_LINE - 11);
		foreach (["\n", "\r\n"] as $break) {
			$rows = self::rows(CsvReader::open(self::stream('Date,Notes' . $break . $record . $break)));
			$this->assertSame(CsvReader::MAX_LINE - 11, strlen($rows[2][1]));
			$this->assertRefused('line_too_long', 2, self::stream('Date,Notes' . $break . $record . 'x' . $break));
		}
	}

	/** An endless line must not be buffered to its end: the read stops at the cap. */
	public function testALineOverSixtyFourKibibytesEndsTheRead(): void {
		$this->assertRefused('line_too_long', 3, self::stream("Date,Notes\n2026-03-01,a\n2026-03-02," . str_repeat('x', 70 * 1024) . "\n"));
	}

	public function testAQuotedCellSpanningLinesIsBoundLikeOneLine(): void {
		$this->assertRefused('line_too_long', 2, self::stream("Date,Notes\n2026-03-01,\"" . str_repeat(str_repeat('x', 1023) . "\n", 70) . "\"\n"));
	}

	/** @return iterable<string, array{string, ?string}> a cell of about 64 KiB, almost all line breaks */
	public static function multiLineCells(): iterable {
		yield 'quoted' => ['"' . str_repeat("\n", 65_000) . '"', null];
		yield 'escaped' => [str_repeat("\\\n", 32_000), '\\'];
		yield 'escaped in quotes' => ['"' . str_repeat("\\\n", 32_000) . '"', '\\'];
	}

	/**
	 * Each appended line is scanned, not the whole record again: this took seconds while it was.
	 *
	 * @dataProvider multiLineCells
	 */
	public function testACellSpanningThousandsOfLinesIsReadInLinearTime(string $cell, ?string $escape): void {
		$started = hrtime(true);
		$rows = self::rows(CsvReader::open(self::stream("A;B\n{$cell};b\n"), $escape));
		$seconds = (hrtime(true) - $started) / 1e9;

		$this->assertSame([2], array_keys($rows));
		$this->assertSame('b', $rows[2][1]);
		$this->assertLessThan(0.5, $seconds);
	}

	/** Five megabytes of the slowest shape there is still reads in a few seconds. */
	public function testAWorstCaseFileUnderTheCapIsReadInSeconds(): void {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, "Datum;Bemerkung;Kosten\n");
		$row = '01.03.2026;"' . str_repeat("a\\\n\"\"\n", 10_000) . "\";x\\\ny\n";
		for ($i = 0; $i < 80; $i++) {
			fwrite($stream, $row);
		}
		rewind($stream);
		$this->assertLessThan(CsvReader::MAX_BYTES, fstat($stream)['size']);

		$started = hrtime(true);
		$count = 0;
		foreach (CsvReader::open($stream, '\\')->rows() as $cells) {
			$this->assertCount(3, $cells);
			$count++;
		}
		$seconds = (hrtime(true) - $started) / 1e9;

		$this->assertSame(80, $count);
		$this->assertLessThan(5.0, $seconds);
	}

	/** No export has that many columns; a file of separators would hold one array entry per byte. */
	public function testARecordOfMoreThan256CellsIsRefused(): void {
		$this->assertSame(256, count(CsvReader::open(self::stream(str_repeat('a,', 255) . "a\n"))->header));
		$this->assertRefused('too_many_cells', 1, self::stream(str_repeat('a,', 256) . "a\n"));
		$this->assertRefused('too_many_cells', 2, self::stream("A,B\n" . str_repeat(',', 256) . "\n"));
	}

	public function testANulByteIsRefusedAsBinary(): void {
		$this->assertRefused('binary', 2, self::stream("Date,Notes\n2026-03-01,a\0b\n"));
	}

	public function testUtf16IsRefusedAsAnEncodingNotGuessed(): void {
		$this->assertRefused('encoding', 1, self::stream("\xFF\xFED\0a\0t\0e\0\n\0"));
	}

	public function testAQuoteLeftOpenToTheEndIsRefused(): void {
		$this->assertRefused('unclosed_quote', 2, self::stream("Date,Notes\n2026-03-01,\"a\nb\n"));
	}

	public function testAnEmptyFileIsRefused(): void {
		$this->assertRefused('empty', null, self::stream("\u{FEFF}\r\n"));
	}

	public function testAUtf8ByteOrderMarkIsNotPartOfTheFirstHeader(): void {
		$reader = CsvReader::open(self::stream("\u{FEFF}Datum;Bemerkung\n01.03.2026;Müller\n"));

		$this->assertSame(['Datum', 'Bemerkung'], $reader->header);
		$this->assertSame([2 => ['01.03.2026', 'Müller']], self::rows($reader));
	}

	/** Latin-1 is Windows-1252 wherever a person types: €, ü and ß come out as UTF-8. */
	public function testAFileThatIsNotUtf8IsReadAsWindows1252(): void {
		$reader = CsvReader::open(self::stream(mb_convert_encoding("Datum;Bemerkung\n01.03.2026;Müller, Straße\n02.03.2026;5 €\n", 'Windows-1252', 'UTF-8')));

		$this->assertSame([2 => ['01.03.2026', 'Müller, Straße'], 3 => ['02.03.2026', '5 €']], self::rows($reader));
	}

	public function testAFileThatIsNeitherIsRefused(): void {
		$this->assertRefused('encoding', 3, self::stream("Datum;Bemerkung\n01.03.2026;Müller\n02.03.2026;M\xFCller\n"));
		$this->assertRefused('encoding', 3, self::stream("Datum;Bemerkung\n01.03.2026;M\xFCller\n02.03.2026;Müller\n"));
		$this->assertRefused('encoding', 2, self::stream("Datum;Bemerkung\n01.03.2026;\x81\n"));
		$this->assertRefused('encoding', 2, self::stream("\u{FEFF}Datum;Bemerkung\n01.03.2026;M\xFCller\n"));
	}

	/** A formula is a string like any other: nothing here evaluates, strips or escapes it. */
	public function testAFormulaCellIsText(): void {
		$reader = CsvReader::open(self::stream("Date,Notes,Cost\n2026-03-01,\"=HYPERLINK(\"\"http://x\"\",\"\"y\"\")\",=1+1\n"));

		$this->assertSame([2 => ['2026-03-01', '=HYPERLINK("http://x","y")', '=1+1']], self::rows($reader));
	}

	public function testBlankLinesAreNoRowsButStillCountAsSpreadsheetRows(): void {
		$reader = CsvReader::open(self::stream("Date\n\n2026-03-01\n\r\n2026-03-02"));

		$this->assertSame([3 => ['2026-03-01'], 5 => ['2026-03-02']], self::rows($reader));
	}
}
