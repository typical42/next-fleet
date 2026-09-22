<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * The checks a service runs on one posted field. Each refusal names the field, because its message
 * is what the sheet shows the person.
 */
final class Field {
	/**
	 * One field, as its column holds it, by the name of the check it takes. An absent value and an
	 * empty one are the same fact, so both come back as null.
	 *
	 * @param int|list<string>|null $limit a text's length or a word's vocabulary
	 * @throws \InvalidArgumentException
	 */
	public static function read(string $column, string $kind, int|array|null $limit, mixed $value): string|int|bool|null {
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '') {
			return null;
		}

		return match ($kind) {
			'text' => self::text($column, $value, is_int($limit) ? $limit : null),
			'word' => self::word($column, $value, is_array($limit) ? $limit : []),
			'count' => self::count($column, $value),
			'offset' => self::offset($column, $value),
			'flag' => self::flag($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
	}

	/** @throws \InvalidArgumentException */
	public static function text(string $column, mixed $value, ?int $length): string {
		if (!is_string($value)) {
			throw new \InvalidArgumentException($column . ' is text');
		}
		// Refused rather than truncated: the database would refuse it too, and a 500 tells the
		// user nothing about which field was too long.
		if ($length !== null && mb_strlen($value) > $length) {
			throw new \InvalidArgumentException($column . ' is longer than ' . $length . ' characters');
		}

		return $value;
	}

	/**
	 * @param list<string> $vocabulary
	 * @throws \InvalidArgumentException
	 */
	public static function word(string $column, mixed $value, array $vocabulary): string {
		if (!is_string($value) || !in_array($value, $vocabulary, true)) {
			throw new \InvalidArgumentException($column . ' is one of ' . implode(', ', $vocabulary));
		}

		return $value;
	}

	/** @throws \InvalidArgumentException */
	public static function count(string $column, mixed $value): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false || $number < 0) {
			throw new \InvalidArgumentException($column . ' is a whole number, never negative');
		}

		return $number;
	}

	/**
	 * A boolean column, which a form posts as a word and JSON as itself. `false` never reaches
	 * here as an empty value - only `''` does, and that is a field nobody answered, which for a
	 * three-valued boolean column is its own state (docs/architecture.md#data-model).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function flag(string $column, mixed $value): bool {
		$flag = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		if ($flag === null) {
			throw new \InvalidArgumentException($column . ' is true or false');
		}

		return $flag;
	}

	/**
	 * A calendar day, one fact, so it carries no offset and no time of day
	 * (docs/architecture.md#time). `!` zeroes what the format does not name, which is also what
	 * keeps the clock out of it.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function day(string $column, mixed $value): \DateTime {
		$day = is_string($value) ? \DateTime::createFromFormat('!Y-m-d', $value) : false;
		if ($day === false || $day->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException($column . ' is a day, as YYYY-MM-DD');
		}

		return $day;
	}

	/**
	 * The offset a moment was entered at, in minutes (docs/architecture.md#time). Real ones run
	 * from -12:00 to +14:00, and a number outside that is a field that did not mean minutes.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function offset(string $column, mixed $value): int {
		$minutes = filter_var($value, FILTER_VALIDATE_INT);
		if ($minutes === false || $minutes < -720 || $minutes > 840) {
			throw new \InvalidArgumentException($column . ' is a UTC offset in minutes');
		}

		return $minutes;
	}
}
