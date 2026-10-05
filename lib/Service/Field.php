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
	/*
	 * The bounds every count and text is read under (docs/security.md). Each sits far above what a
	 * person enters and far below what its column holds, so an absurd number is a 400 naming the
	 * field, never a 500 from the database or a total that overflows.
	 */
	/** Free text a person types: a few pages, and the timeline sends it with every row. */
	public const TEXT = 10_000;
	/** Cents, or tenths of a cent for a unit price: ten billion of the vehicle's currency. */
	public const MONEY = 1_000_000_000_000;
	/** An odometer, an hour counter or a distance: a billion of its unit. */
	public const COUNTER = 1_000_000_000;
	/** Millilitres or watt-hours of one fill-up: a billion litres or a terawatt-hour. */
	public const AMOUNT = 1_000_000_000_000;
	/** A tank or a battery: smaller than AMOUNT, since its column is a 32-bit INTEGER. */
	public const CAPACITY = 1_000_000_000;
	/** A VAT rate in basis points: 100 %. */
	public const RATE = 10_000;
	/** A recurrence or a retention: a hundred years. */
	public const MONTHS = 1_200;
	/** An instant in seconds: the last one a four-digit year prints. */
	public const MOMENT = 253_402_300_799;

	/**
	 * Whether `$value` is an ISO 4217 code as the vehicle stores it. One rule for the vehicle's
	 * write and the import's check, so a currency one takes the other never refuses.
	 * `VehicleSheet.vue` (`currencyCode`) asks the same before sending; change both together.
	 */
	public static function isCurrency(string $value): bool {
		return preg_match('/^[A-Z]{3}$/', $value) === 1;
	}

	/**
	 * One field, as its column holds it, by the name of the check it takes. An absent value and an
	 * empty one are the same fact, so both come back as null.
	 *
	 * @param int|list<string>|null $limit a text's length, a count's maximum or a word's vocabulary
	 * @throws \InvalidArgumentException
	 * @throws \LogicException if a text or a count is read without its bound
	 */
	public static function read(string $column, string $kind, int|array|null $limit, mixed $value): string|int|bool|null {
		// Before the empty check, so a caller that forgot the bound fails on its first request.
		$bound = in_array($kind, ['text', 'count'], true) ? self::bound($column, $limit) : 0;
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '') {
			return null;
		}

		return match ($kind) {
			'text' => self::text($column, $value, $bound),
			'word' => self::word($column, $value, is_array($limit) ? $limit : []),
			'count' => self::count($column, $value, $bound),
			'offset' => self::offset($column, $value),
			'flag' => self::flag($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
	}

	/**
	 * @param int|list<string>|null $limit
	 * @throws \LogicException
	 */
	public static function bound(string $column, int|array|null $limit): int {
		if (!is_int($limit)) {
			throw new \LogicException($column . ' has no bound');
		}

		return $limit;
	}

	/** @throws \InvalidArgumentException */
	public static function text(string $column, mixed $value, int $length): string {
		if (!is_string($value)) {
			throw new \InvalidArgumentException($column . ' is text');
		}
		// A form-encoded body or a query string carries bytes a JSON body cannot. Stored, they would
		// make every JSON answer that carries the row throw, for everyone who reads the vehicle.
		if (!mb_check_encoding($value, 'UTF-8')) {
			throw new \InvalidArgumentException($column . ' is not UTF-8 text');
		}
		// Refused rather than truncated: the database would refuse it too, and a 500 tells the
		// user nothing about which field was too long.
		if (mb_strlen($value) > $length) {
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
	public static function count(string $column, mixed $value, int $max): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false || $number < 0) {
			throw new \InvalidArgumentException($column . ' is a whole number, never negative');
		}
		if ($number > $max) {
			throw new \InvalidArgumentException($column . ' is ' . $max . ' at most');
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

	/**
	 * An IANA zone. The backward-compatible names count too: browsers still report some zones by
	 * them.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function zone(string $column, mixed $value): \DateTimeZone {
		if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
			throw new \InvalidArgumentException($column . ' is an IANA time zone');
		}

		return new \DateTimeZone($value);
	}
}
