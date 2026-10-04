<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\ImportRefusedException;

/**
 * A CSV file someone else wrote, read as text and nothing more, under the bounds docs/security.md
 * names. A cell is a string until a field parses it.
 */
final class CsvReader {
	public const MAX_BYTES = 5_000_000;
	public const MAX_ROWS = 20_000;
	/** A record, its line break not counted; a quoted cell's line breaks are. */
	public const MAX_LINE = 64 * 1024;
	/** No export has more; a record of separators alone would hold one cell per byte. */
	public const MAX_CELLS = 256;
	/**
	 * The file, header included: the caps above still let it hold five million strings. Refused as
	 * `too_many_cells` with no row, since no single row is to blame.
	 */
	public const MAX_FILE_CELLS = 500_000;

	/** Tab first: it never stands in text. `;` before `,`, which a header's prose uses more. */
	private const SEPARATORS = ["\t", ';', ','];
	/** Bytes Windows-1252 leaves undefined: a file holding one is neither encoding. */
	private const UNDEFINED_1252 = '/[\x81\x8D\x8F\x90\x9D]/';

	/** @var list<string> */
	public readonly array $header;
	public readonly string $separator;
	/** The spreadsheet row the last record started on. */
	private int $number = 0;
	private int $bytes = 0;
	private int $cells = 0;
	/** What the file has shown so far; null while every byte was ASCII, which both read alike. */
	private ?string $encoding = null;

	/** @param resource $stream */
	private function __construct(
		private $stream,
		private ?string $escape,
	) {
		$first = $this->line();
		if ($first === null) {
			throw new ImportRefusedException('empty');
		}
		$unquoted = self::unquoted($first);
		if (str_contains(self::withoutTerminator($unquoted), "\r")) {
			throw new ImportRefusedException('line_breaks', 1);
		}
		$this->separator = self::separatorOf($unquoted);
		$header = $this->record($first);
		if ($header === ['']) {
			throw new ImportRefusedException('empty');
		}
		$this->header = $header;
		$this->cells = count($header);
	}

	/**
	 * A file is refused here for its header, and while rows() runs for a row.
	 *
	 * @param resource $stream
	 * @param ?string $escape a byte that takes the next one as is, quote, separator or line break
	 *                        alike (IImporter::escape()); null for RFC 4180, which has none
	 * @throws ImportRefusedException
	 */
	public static function open($stream, ?string $escape = null): self {
		return new self($stream, $escape);
	}

	/**
	 * Keyed by the row number a spreadsheet shows: the header is row 1, a blank line is a row
	 * without cells, and a cell spanning lines is one row.
	 *
	 * @return \Generator<int, list<string>>
	 * @throws ImportRefusedException
	 */
	public function rows(): \Generator {
		$count = 0;
		while (($line = $this->line()) !== null) {
			$cells = $this->record($line);
			if ($cells === ['']) {
				continue;
			}
			if (++$count > self::MAX_ROWS) {
				throw new ImportRefusedException('too_many_rows', $this->number);
			}
			$this->cells += count($cells);
			if ($this->cells > self::MAX_FILE_CELLS) {
				throw new ImportRefusedException('too_many_cells');
			}
			yield $this->number => $cells;
		}
	}

	/**
	 * At most a record's cap and its CRLF per call, so an endless line is never buffered whole.
	 */
	private function line(): ?string {
		$line = fgets($this->stream, self::MAX_LINE + 3);
		if ($line === false) {
			return null;
		}
		if ($this->bytes === 0) {
			if (str_starts_with($line, "\xFF\xFE") || str_starts_with($line, "\xFE\xFF")) {
				throw new ImportRefusedException('encoding', 1);
			}
			if (str_starts_with($line, "\u{FEFF}")) {
				$this->encoding = 'UTF-8';
				$this->bytes = 3;
				$line = substr($line, 3);
			}
		}
		$this->bytes += strlen($line);

		return $line;
	}

	/**
	 * Every other part between quotes is outside them; an escaped `""` flips twice. No pattern:
	 * one over a long quoted cell hits PCRE's limits.
	 */
	private static function unquoted(string $line): string {
		$parts = explode('"', $line);

		return implode('', array_filter($parts, static fn (int $at): bool => $at % 2 === 0, ARRAY_FILTER_USE_KEY));
	}

	/** Quoted parts do not count: a header cell may well say `Kosten (€, brutto)`. */
	private static function separatorOf(string $unquoted): string {
		$best = ',';
		$most = 0;
		foreach (self::SEPARATORS as $separator) {
			$count = substr_count($unquoted, $separator);
			if ($count > $most) {
				$best = $separator;
				$most = $count;
			}
		}

		return $best;
	}

	/**
	 * One record, starting with $line and taking further lines while a quote or an escape is open.
	 *
	 * @return list<string>
	 */
	private function record(string $line): array {
		$this->number++;
		$record = new CsvRecord($this->separator, $this->escape, $this->number);
		while (true) {
			if ($this->bytes > self::MAX_BYTES) {
				throw new ImportRefusedException('too_large', $this->number);
			}
			$record->append($line);
			$cells = $record->cells();
			if ($cells !== null) {
				return $this->decoded($record->text(), $cells);
			}
			$line = $this->line();
			if ($line === null) {
				return $this->decoded($record->text(), $record->last() ?? throw new ImportRefusedException('unclosed_quote', $this->number));
			}
		}
	}

	private static function withoutTerminator(string $record): string {
		if (str_ends_with($record, "\n")) {
			$record = substr($record, 0, -1);
		}

		return str_ends_with($record, "\r") ? substr($record, 0, -1) : $record;
	}

	/**
	 * The first record that is not ASCII decides the file's encoding: UTF-8 if it is valid UTF-8
	 * or a BOM said so, else Windows-1252. A later record that contradicts it refuses the file —
	 * nothing else is guessed. Text in Windows-1252 that happens to be valid UTF-8 (`Ã¼`) is not
	 * text a person typed.
	 *
	 * @param list<string> $cells
	 * @return list<string>
	 */
	private function decoded(string $record, array $cells): array {
		if (preg_match('/[\x80-\xFF]/', $record) !== 1) {
			return $cells;
		}
		$utf8 = mb_check_encoding($record, 'UTF-8');
		if ($utf8 && $this->encoding !== 'Windows-1252') {
			$this->encoding = 'UTF-8';

			return $cells;
		}
		if ($utf8 || $this->encoding === 'UTF-8' || preg_match(self::UNDEFINED_1252, $record) === 1) {
			throw new ImportRefusedException('encoding', $this->number);
		}
		$this->encoding = 'Windows-1252';

		return array_map(static fn (string $cell): string => mb_convert_encoding($cell, 'UTF-8', 'Windows-1252'), $cells);
	}
}
