<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\UnreadableCellException;
use OCA\NextFleet\Service\Field;

/**
 * One source row, read by the name of the value a column holds rather than by position. A cell
 * it cannot read blames that column's header, so the preview can say where.
 */
final class Cells {
	/** The entry services' bound per dimension (Field), so the preview refuses what the write would. */
	private const BOUNDS = ['distance' => Field::COUNTER, 'volume' => Field::AMOUNT, 'energy' => Field::AMOUNT, 'money' => Field::MONEY];

	/**
	 * @param list<string> $row as CsvReader yields it; a short row lacks its last cells
	 * @param array<string, array{?int, string}> $columns value => its position, null when the file
	 *                                                    has no such column, and the header to blame
	 * @param array<string, ?string> $marks value => the decimal mark its column shows
	 * @param string $order of slash dates, `dmy` or `mdy`
	 * @param ?string $currency the vehicle's, from Answers::currency(); null when it states none
	 */
	public function __construct(
		private array $row,
		private array $columns,
		private array $marks,
		private string $order,
		private \DateTimeZone $zone,
		private ?string $currency,
	) {
	}

	/**
	 * The lower-case headers a value is known by: its own name, and the aliases of the values
	 * listed. An alias of another value places nothing.
	 *
	 * @param list<string> $values
	 * @param array<string, string> $aliases lower-case header => the value it holds
	 * @return array<string, string> lower-case header => the value it holds
	 */
	public static function names(array $values, array $aliases): array {
		$names = [];
		foreach ($values as $value) {
			$names[mb_strtolower($value)] = $value;
		}

		return $names + array_filter($aliases, static fn (string $value): bool => in_array($value, $values, true));
	}

	/**
	 * Which header became which field and which became nothing, as IImporter::columns() answers.
	 *
	 * @param list<string> $header
	 * @param array<string, int> $positions from positions()
	 * @param array<string, string> $fields value => the field it becomes
	 * @return array{placed: array<string, string>, ignored: list<string>}
	 */
	public static function report(array $header, array $positions, array $fields): array {
		$values = array_flip($positions);
		$columns = ['placed' => [], 'ignored' => []];
		foreach ($header as $position => $name) {
			if (isset($values[$position])) {
				$columns['placed'][$name] = $fields[$values[$position]];
			} else {
				$columns['ignored'][] = $name;
			}
		}

		return $columns;
	}

	/**
	 * Each value's position and the header to blame for it, as the constructor takes them. A value
	 * the file has no column for blames its own name.
	 *
	 * @param list<string> $header
	 * @param array<string, int> $positions from positions()
	 * @param list<string> $values
	 * @return array<string, array{?int, string}>
	 */
	public static function located(array $header, array $positions, array $values): array {
		$columns = [];
		foreach ($values as $value) {
			$position = $positions[$value] ?? null;
			$columns[$value] = [$position, $position === null ? $value : $header[$position]];
		}

		return $columns;
	}

	/**
	 * Notes from several cells, one per line; a cell of `0` is a note too. Past the entry
	 * services' length it blames $blame, the column most of a note comes from.
	 */
	public function notes(string $blame, ?string ...$lines): ?string {
		$lines = array_filter($lines, 'is_string');
		$notes = $lines === [] ? null : implode("\n", $lines);
		if ($notes !== null && mb_strlen($notes) > Field::TEXT) {
			$this->refuse($blame, 'too_long');
		}

		return $notes;
	}

	/**
	 * The first position of each value in a header: a second header for the same value is
	 * ignored, never merged into the first.
	 *
	 * @param list<string> $header
	 * @param array<string, string> $names lower-case header => the value it holds
	 * @return array<string, int> value => position
	 */
	public static function positions(array $header, array $names): array {
		$positions = [];
		foreach ($header as $position => $name) {
			// Not strtolower(): Spritmonitor's headers carry umlauts.
			$value = $names[mb_strtolower(trim($name))] ?? null;
			if ($value !== null && !isset($positions[$value])) {
				$positions[$value] = $position;
			}
		}

		return $positions;
	}

	/**
	 * One column of every row, for the evidence only the whole column gives: Values::decimalMark()
	 * and Values::dateOrder().
	 *
	 * @param array<int, list<string>> $rows
	 * @return list<string>
	 */
	public static function column(array $rows, ?int $position): array {
		return $position === null ? [] : array_map(static fn (array $row): string => $row[$position] ?? '', array_values($rows));
	}

	/**
	 * A row wider than the header cannot say which cell is which, so no column is blamed. A
	 * Spritmonitor note ending in a lone `\` escapes its line break and swallows the next row.
	 *
	 * @param list<string> $header
	 * @throws UnreadableCellException
	 */
	public function fits(array $header): void {
		if (count($this->row) > count($header)) {
			throw new UnreadableCellException('cells');
		}
	}

	/** Trimmed; an empty cell is nothing. */
	public function text(string $value, ?int $length = null): ?string {
		$text = trim($this->cell($value));
		if ($length !== null && mb_strlen($text) > $length) {
			$this->refuse($value, 'too_long');
		}

		return $text === '' ? null : $text;
	}

	/**
	 * A count in its canonical unit, as the entry services take it: never negative.
	 *
	 * @param string $dimension a key of Values::UNITS
	 */
	public function count(string $value, string $dimension, string $unit): ?int {
		return $this->blaming($value, fn (string $cell): ?int => $this->counted($value, $cell, $dimension, $unit));
	}

	/**
	 * Cents, from a cell that may carry a currency mark (Values::money()). One that cannot stand
	 * for the vehicle's currency is refused: there is no rate to convert at. So is any amount
	 * while the vehicle's currency is no code, blaming no column: the vehicle is to be fixed.
	 */
	public function money(string $value): ?int {
		$cents = $this->blaming($value, function (string $cell) use ($value): ?int {
			[$number, $mark] = Values::money($cell);
			$this->vehicleCurrency($mark);

			return $this->counted($value, $number, 'money', 'major');
		});
		if ($cents !== null && !$this->coded()) {
			throw new UnreadableCellException('currency');
		}

		return $cents;
	}

	/** A column of its own naming the row's currency, refused as in money(). */
	public function sameCurrency(string $value): void {
		$this->blaming($value, fn (string $cell) => $this->vehicleCurrency(trim($cell) === '' ? null : trim($cell)));
	}

	/** @return ?array{at: int, off: int} */
	public function moment(string $value): ?array {
		return $this->blaming($value, fn (string $cell): ?array => Values::moment($cell, $this->order, $this->zone));
	}

	public function flag(string $value): ?bool {
		return $this->blaming($value, static fn (string $cell): ?bool => Values::flag($cell));
	}

	/**
	 * What a row cannot be created without.
	 *
	 * @template T
	 * @param ?T $read
	 * @return T
	 */
	public function must(string $value, mixed $read): mixed {
		return $read ?? $this->refuse($value, 'missing');
	}

	/** The row, for a reason only the importer knows, blaming the value's column. */
	public function refuse(string $value, string $reason): never {
		throw new UnreadableCellException($reason, $this->columns[$value][1]);
	}

	/** @throws UnreadableCellException when the mark cannot stand for the vehicle's currency */
	private function vehicleCurrency(?string $mark): void {
		if ($this->currency !== null && $this->coded() && $mark !== null && !Values::mayName($mark, $this->currency)) {
			throw new UnreadableCellException('currency');
		}
	}

	/** Whether the vehicle's currency, when it states one, is an ISO 4217 code. */
	private function coded(): bool {
		return $this->currency === null || Field::isCurrency($this->currency);
	}

	private function counted(string $value, string $cell, string $dimension, string $unit): ?int {
		$decimal = Values::decimal($cell, $this->marks[$value] ?? null);
		if ($decimal === null) {
			return null;
		}
		$count = Values::canonical($decimal, $dimension, $unit);
		if ($count < 0) {
			throw new UnreadableCellException('negative');
		}
		if ($count > (self::BOUNDS[$dimension] ?? throw new \LogicException($dimension . ' has no bound'))) {
			throw new UnreadableCellException('too_large');
		}

		return $count;
	}

	private function cell(string $value): string {
		$position = $this->columns[$value][0] ?? null;

		return $position === null ? '' : ($this->row[$position] ?? '');
	}

	/**
	 * @template T
	 * @param callable(string): T $read
	 * @return T
	 */
	private function blaming(string $value, callable $read): mixed {
		try {
			return $read($this->cell($value));
		} catch (UnreadableCellException $e) {
			throw new UnreadableCellException($e->reason, $this->columns[$value][1]);
		}
	}
}
