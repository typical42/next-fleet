<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\UnreadableCellException;

/**
 * Reading one cell as what it states. Every reader takes an empty cell as nothing, as Field::read
 * does, and refuses a cell that states something else or more than one thing.
 */
final class Values {
	/** Far beyond any odometer or bill, and short enough that the arithmetic below stays cheap. */
	private const MAX_DIGITS = 30;

	/** A sign, then digits and marks in any order: decimal() sorts out which order is a number. */
	private const NUMBER = '/^([+-]?)(\d[\d.,]*)$/';

	/**
	 * What one of each unit is in the canonical one, exactly: a mile and both gallons by their
	 * legal definitions, money in its minor unit. An export states no unit, so the user does.
	 */
	public const UNITS = [
		'distance' => ['km' => '1', 'mi' => '1.609344'],
		'volume' => ['l' => '1000', 'us_gal' => '3785.411784', 'uk_gal' => '4546.09'],
		'energy' => ['kwh' => '1000'],
		'money' => ['major' => '100'],
	];

	/**
	 * The currencies written with each sign. `$`, `£`, `kr` and `¥` are several, so the list must
	 * hold every one of them: a currency missing here would refuse its own sign.
	 */
	private const CURRENCY_SIGNS = [
		'€' => ['EUR'],
		'£' => ['GBP', 'EGP', 'FKP', 'GIP', 'SHP', 'SSP'],
		'$' => [
			'USD', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD', 'TWD', 'MXN', 'ARS', 'CLP', 'COP', 'UYU', 'CUP',
			'DOP', 'BND', 'BSD', 'BBD', 'BZD', 'BMD', 'KYD', 'XCD', 'FJD', 'GYD', 'JMD', 'LRD', 'NAD',
			'SBD', 'SRD', 'TTD', 'TVD', 'KID', 'ZWL',
		],
		'¥' => ['JPY', 'CNY'],
		'￥' => ['JPY', 'CNY'],
		'kr' => ['SEK', 'NOK', 'DKK', 'ISK'],
		'kr.' => ['SEK', 'NOK', 'DKK', 'ISK'],
		'zł' => ['PLN'],
		'Kč' => ['CZK'],
		'Ft' => ['HUF'],
		'₹' => ['INR'],
		'₽' => ['RUB'],
		'R$' => ['BRL'],
		'₺' => ['TRY'],
		'₩' => ['KRW', 'KPW'],
		'₪' => ['ILS'],
		'₴' => ['UAH'],
	];

	/**
	 * A money cell as a spreadsheet or .NET's currency format writes it, `$1,234.50`,
	 * `1.234,50 €`, `($12.00)`, `CHF 1’234.50`, `12,00 kr.`: the number left for decimal(), and the
	 * currency mark it carried. Spaces and apostrophes between digits go too, since some locales
	 * group thousands with them. A mark is at most three letters or signs on one side, a period
	 * after it allowed, so words stay where they are and decimal() refuses them.
	 *
	 * @return array{string, ?string}
	 */
	public static function money(string $cell): array {
		$text = preg_replace(['/[\s\x{00A0}\x{2009}\x{202F}]+/u', '/(?<=\d)[\'\x{2019}](?=\d)/u'], '', $cell);
		// Not UTF-8: CsvReader hands none such on, and decimal() refuses it unread.
		if ($text === null) {
			return [$cell, null];
		}
		$bracketed = preg_match('/^\((.*)\)$/u', $text, $inner) === 1;
		$text = $bracketed ? $inner[1] : $text;
		$money = '/^(?<sign>[+-]?)(?<before>[^\d.,+-]{0,3})(?<sign2>[+-]?)(?<number>[\d.,]*)(?<after>(?:[^\d.,+-]{1,3}\.?)?)$/u';
		if (preg_match($money, $text, $parts) !== 1
			|| ($parts['before'] !== '' && $parts['after'] !== '')
			|| ($parts['sign'] !== '' && $parts['sign2'] !== '')
			// `n/a` or `TBD` is a word, not a sign without its amount.
			|| $parts['number'] === '') {
			return [$text, null];
		}
		$negative = $bracketed || $parts['sign'] === '-' || $parts['sign2'] === '-';
		$number = ($negative ? '-' : '') . $parts['number'];

		return [$number, ($parts['before'] . $parts['after']) ?: null];
	}

	/**
	 * Whether a currency mark may stand for an ISO 4217 code: a code for itself, a sign for the
	 * currencies written with it. A mark not listed may stand for any, since nothing says otherwise.
	 */
	public static function mayName(string $mark, string $currency): bool {
		if (preg_match('/^[A-Z]{3}$/', $mark) === 1) {
			return $mark === $currency;
		}

		return in_array($currency, self::CURRENCY_SIGNS[$mark] ?? [$currency], true);
	}

	/**
	 * A number as a plain decimal, `-1234.5`. The cell's own marks decide when they can; a lone mark
	 * before three digits, `1,234`, could be either and takes the column's mark.
	 *
	 * @param ?string $mark the column's decimal mark, from decimalMark()
	 * @throws UnreadableCellException
	 */
	public static function decimal(string $cell, ?string $mark): ?string {
		$cell = trim($cell);
		if ($cell === '') {
			return null;
		}
		if (preg_match(self::NUMBER, $cell, $parts) !== 1) {
			throw new UnreadableCellException('number');
		}
		[, $sign, $number] = $parts;
		if (strlen($number) > self::MAX_DIGITS) {
			throw new UnreadableCellException('too_large');
		}
		$mark = self::markOf($number) ?? $mark;
		if ($mark === null && preg_match('/^\d+$/', $number) !== 1) {
			throw new UnreadableCellException('ambiguous_number');
		}
		$mark ??= '.';
		$group = preg_quote($mark === ',' ? '.' : ',', '/');
		if (preg_match('/^(\d{1,3}(?:' . $group . '\d{3})+|\d+)(?:' . preg_quote($mark, '/') . '(\d+))?$/', $number, $read) !== 1) {
			throw new UnreadableCellException('number');
		}
		$whole = str_replace(['.', ','], '', $read[1]);

		return ($sign === '-' ? '-' : '') . $whole . (isset($read[2]) ? '.' . $read[2] : '');
	}

	/**
	 * The decimal mark a column shows: the one every cell that says one agrees on, or null when
	 * none says one or two cells disagree.
	 *
	 * @param iterable<string> $cells
	 */
	public static function decimalMark(iterable $cells): ?string {
		$marks = [];
		foreach ($cells as $cell) {
			$cell = trim($cell);
			if (preg_match(self::NUMBER, $cell, $parts) === 1 && strlen($parts[2]) <= self::MAX_DIGITS) {
				$mark = self::markOf($parts[2]);
				if ($mark !== null) {
					$marks[$mark] = true;
				}
			}
		}

		return count($marks) === 1 ? array_key_first($marks) : null;
	}

	/**
	 * What one number says about its decimal mark: the later of two different marks, the other one
	 * of a repeated mark, a lone mark unless three digits follow it after a non-zero group.
	 */
	private static function markOf(string $number): ?string {
		$period = strrpos($number, '.');
		$comma = strrpos($number, ',');
		if ($period !== false && $comma !== false) {
			return $period > $comma ? '.' : ',';
		}
		$only = $period !== false ? '.' : ($comma !== false ? ',' : null);
		if ($only === null) {
			return null;
		}
		if (substr_count($number, $only) > 1) {
			return $only === '.' ? ',' : '.';
		}

		return preg_match('/^[1-9]\d{0,2}[.,]\d{3}$/', $number) === 1 ? null : $only;
	}

	/**
	 * A decimal from decimal() in the column's canonical unit (docs/architecture.md#data-model),
	 * rounded half up once, here: exact digits throughout, since a float holds 1.005 as 1.00499….
	 * Half up by size, the sign kept, as round() does elsewhere: a refund rounds as its charge.
	 *
	 * @param string $dimension a key of UNITS
	 * @throws UnreadableCellException
	 * @throws \InvalidArgumentException for a unit the dimension does not have
	 */
	public static function canonical(string $decimal, string $dimension, string $unit): int {
		$factor = self::UNITS[$dimension][$unit] ?? throw new \InvalidArgumentException($dimension . ' has no unit ' . $unit);
		$negative = str_starts_with($decimal, '-');
		[$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '+-')), 2, '');
		[$factorWhole, $factorFraction] = array_pad(explode('.', $factor), 2, '');
		$scale = strlen($fraction) + strlen($factorFraction);
		$digits = str_pad(self::times($whole . $fraction, $factorWhole . $factorFraction), $scale + 1, '0', STR_PAD_LEFT);
		$kept = ltrim(substr($digits, 0, strlen($digits) - $scale), '0');
		// One digit under PHP_INT_MAX's nineteen, so the carry below cannot overflow.
		if (strlen($kept) > 18) {
			throw new UnreadableCellException('too_large');
		}
		$value = (int)$kept + ($scale > 0 && $digits[strlen($digits) - $scale] >= '5' ? 1 : 0);

		return $negative ? -$value : $value;
	}

	/** Two strings of digits multiplied as at school: short, since decimal() caps their length. */
	private static function times(string $a, string $b): string {
		$product = array_fill(0, strlen($a) + strlen($b), 0);
		for ($i = strlen($a) - 1; $i >= 0; $i--) {
			for ($j = strlen($b) - 1; $j >= 0; $j--) {
				$sum = $product[$i + $j + 1] + (int)$a[$i] * (int)$b[$j];
				$product[$i + $j + 1] = $sum % 10;
				$product[$i + $j] += intdiv($sum, 10);
			}
		}

		return implode('', $product);
	}

	/**
	 * The order a column's slash dates are written in, `dmy` or `mdy`: the one every slash date
	 * fits. Null while they fit both or neither, which only the user can settle. A column without
	 * slash dates says its order in every cell, so `dmy` is as true as `mdy` there.
	 *
	 * @param iterable<string> $cells
	 */
	public static function dateOrder(iterable $cells): ?string {
		$fits = ['dmy' => true, 'mdy' => true];
		$slashes = false;
		foreach ($cells as $cell) {
			$date = self::dateParts(trim($cell));
			if ($date !== null && $date['style'] === 'slash') {
				$slashes = true;
				$fits['dmy'] = $fits['dmy'] && (int)$date['second'] <= 12;
				$fits['mdy'] = $fits['mdy'] && (int)$date['first'] <= 12;
			}
		}
		$orders = array_keys(array_filter($fits));

		return match (true) {
			!$slashes => 'dmy',
			count($orders) === 1 => $orders[0],
			default => null,
		};
	}

	/**
	 * A date, optionally with its time, as the moment it names in `$zone` and that moment's own
	 * offset in minutes (docs/architecture.md#time). A date alone is noon: whatever offset later
	 * reads it, noon stays on its day. A time the clock skipped is refused; one it showed twice is
	 * the first, as a browser reads a time typed into the entry sheet.
	 *
	 * @param string $order of slash dates, `dmy` or `mdy`, from dateOrder() or the user
	 * @return ?array{at: int, off: int}
	 * @throws UnreadableCellException
	 */
	public static function moment(string $cell, string $order, \DateTimeZone $zone): ?array {
		$cell = trim($cell);
		if ($cell === '') {
			return null;
		}
		$date = self::dateParts($cell) ?? throw new UnreadableCellException('date');
		[$day, $month] = match ($date['style']) {
			'iso' => [$date['third'], $date['second']],
			'dot' => [$date['first'], $date['second']],
			default => $order === 'mdy' ? [$date['second'], $date['first']] : [$date['first'], $date['second']],
		};
		$year = $date['style'] === 'iso' ? $date['first'] : $date['third'];
		[$hour, $minute, $second] = self::time($date);
		if (!checkdate((int)$month, (int)$day, (int)$year) || $hour === null) {
			throw new UnreadableCellException('date');
		}
		// From the epoch, never the clock (docs/architecture.md#time): every part is set below.
		$moment = (new \DateTimeImmutable('@0'))->setTimezone($zone)
			->setDate((int)$year, (int)$month, (int)$day)
			->setTime($hour, $minute, $second);
		if ((int)$moment->format('G') !== $hour) {
			throw new UnreadableCellException('date');
		}

		return ['at' => $moment->getTimestamp(), 'off' => intdiv($moment->getOffset(), 60)];
	}

	/**
	 * Each date part as written: ISO year first, a dot day first, a slash either way round. A
	 * two-digit year is none of them: its century would be a guess.
	 *
	 * @return ?array<string, string>
	 */
	private static function dateParts(string $cell): ?array {
		$formats = [
			'iso' => '(?<first>\d{4})-(?<second>\d{2})-(?<third>\d{2})',
			'dot' => '(?<first>\d{1,2})\.(?<second>\d{1,2})\.(?<third>\d{4})',
			'slash' => '(?<first>\d{1,2})\/(?<second>\d{1,2})\/(?<third>\d{4})',
		];
		// .NET with ICU 72 or later puts a narrow no-break space before the half.
		$time = '(?:[ T](?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second_>\d{2}))?(?:[\s\x{00A0}\x{202F}]*(?<half>[AaPp][Mm]))?)?';
		foreach ($formats as $style => $format) {
			if (preg_match('/^' . $format . $time . '$/u', $cell, $parts) === 1) {
				return ['style' => $style] + array_filter($parts, 'is_string', ARRAY_FILTER_USE_KEY);
			}
		}

		return null;
	}

	/**
	 * Noon without a time; a twelve-hour clock read as one, `12:30 AM` being half past midnight.
	 *
	 * @param array<string, string> $date
	 * @return array{?int, int, int} the hour null when the clock cannot show it
	 */
	private static function time(array $date): array {
		if (($date['hour'] ?? '') === '') {
			return [12, 0, 0];
		}
		$hour = (int)$date['hour'];
		$minute = (int)$date['minute'];
		$second = (int)($date['second_'] ?? 0);
		$half = strtolower($date['half'] ?? '');
		if ($half !== '') {
			$hour = $hour < 1 || $hour > 12 ? null : $hour % 12 + ($half === 'pm' ? 12 : 0);
		}
		if ($hour === null || $hour > 23 || $minute > 59 || $second > 59) {
			return [null, 0, 0];
		}

		return [$hour, $minute, $second];
	}

	/**
	 * A yes or no, in English or German. Not FILTER_VALIDATE_BOOL: it takes `on`, which no export
	 * here writes, and not `ja`.
	 *
	 * @throws UnreadableCellException
	 */
	public static function flag(string $cell): ?bool {
		return match (strtolower(trim($cell))) {
			'' => null,
			'true', '1', 'yes', 'ja' => true,
			'false', '0', 'no', 'nein' => false,
			default => throw new UnreadableCellException('flag'),
		};
	}
}
