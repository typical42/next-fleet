<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\ImportRefusedException;

/**
 * One record of a CsvReader, scanned as its lines arrive. The scan keeps where it stopped — in a
 * quote, or before an escape that waits for its byte — so each byte is read once, however many
 * lines a cell spans. Rescanning the whole record per line would make one 64 KiB cell take seconds.
 *
 * A quote opens a cell only at its start; anywhere else it is a character, as is anything between
 * a closing quote and the next separator.
 */
final class CsvRecord {
	private string $bytes = '';
	/** The record's own bytes: the last line's break is not counted, an earlier one is a cell's. */
	private int $length = 0;
	/** Where the scan goes on. */
	private int $at = 0;
	/** @var list<string> */
	private array $cells = [];
	private string $cell = '';
	private bool $start = true;
	private bool $quoted = false;

	/**
	 * @param ?string $escape as CsvReader::open() takes it
	 * @param int $row the spreadsheet row the record starts on, for a refusal
	 */
	public function __construct(
		private string $separator,
		private ?string $escape,
		private int $row,
	) {
	}

	/**
	 * @throws ImportRefusedException
	 */
	public function append(string $line): void {
		$this->bytes .= $line;
		$this->length = strlen($this->bytes);
		if (str_ends_with($this->bytes, "\n")) {
			$this->length--;
		}
		if ($this->length > 0 && $this->bytes[$this->length - 1] === "\r") {
			$this->length--;
		}
		if ($this->length > CsvReader::MAX_LINE) {
			throw new ImportRefusedException('line_too_long', $this->row);
		}
		// What came before was checked when it came.
		if (str_contains($line, "\0")) {
			throw new ImportRefusedException('binary', $this->row);
		}
	}

	/**
	 * @return list<string>|null null while a quote is still open, or an escape at the end waits
	 *                           for the line break it escapes
	 * @throws ImportRefusedException
	 */
	public function cells(): ?array {
		$special = '"' . $this->escape;
		$plain = $this->separator . $this->escape;
		while (true) {
			if ($this->quoted) {
				$stop = $this->at + strcspn($this->bytes, $special, $this->at, $this->length - $this->at);
				$this->cell .= substr($this->bytes, $this->at, $stop - $this->at);
				$this->at = $stop;
				if ($stop >= $this->length) {
					return null;
				}
				if ($this->bytes[$stop] === $this->escape) {
					if ($stop + 1 >= $this->length) {
						return null;
					}
					$this->cell .= $this->bytes[$stop + 1];
					$this->at = $stop + 2;
				} elseif ($stop + 1 < $this->length && $this->bytes[$stop + 1] === '"') {
					$this->cell .= '"';
					$this->at = $stop + 2;
				} else {
					$this->quoted = false;
					$this->at = $stop + 1;
				}
				continue;
			}
			if ($this->start && $this->at < $this->length && $this->bytes[$this->at] === '"') {
				$this->quoted = true;
				$this->start = false;
				$this->at++;
				continue;
			}
			$this->start = false;
			$stop = $this->at + strcspn($this->bytes, $plain, $this->at, $this->length - $this->at);
			$this->cell .= substr($this->bytes, $this->at, $stop - $this->at);
			$this->at = $stop;
			if ($stop >= $this->length) {
				return [...$this->cells, $this->cell];
			}
			if ($this->bytes[$stop] === $this->escape) {
				if ($stop + 1 >= $this->length) {
					return null;
				}
				$this->cell .= $this->bytes[$stop + 1];
				$this->at = $stop + 2;
				continue;
			}
			// A separator promises one cell more.
			if (count($this->cells) + 2 > CsvReader::MAX_CELLS) {
				throw new ImportRefusedException('too_many_cells', $this->row);
			}
			$this->cells[] = $this->cell;
			$this->cell = '';
			$this->start = true;
			$this->at = $stop + 1;
		}
	}

	/**
	 * The cells when the file ends here, after cells() answered null: nothing follows for a
	 * trailing escape to escape, so it is text. Null while a quote is open.
	 *
	 * @return list<string>|null
	 */
	public function last(): ?array {
		return $this->quoted ? null : [...$this->cells, $this->cell . $this->escape];
	}

	/** The record's bytes, its line break not counted. */
	public function text(): string {
		return substr($this->bytes, 0, $this->length);
	}
}
