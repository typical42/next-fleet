<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * A CSV file for a spreadsheet: UTF-8 with a BOM, since Excel reads it as the local code page
 * without one; `,` and CRLF as RFC 4180 has them.
 */
final class Csv {
	/**
	 * A string is defused before it is quoted: one a spreadsheet would read as a formula gets a
	 * leading apostrophe (docs/security.md). A number is ours and goes out as it is.
	 *
	 * @param list<string> $header
	 * @param list<list<int|string|null>> $rows
	 */
	public static function of(array $header, array $rows): string {
		$lines = [self::line($header)];
		foreach ($rows as $row) {
			$lines[] = self::line($row);
		}

		return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
	}

	/** @param list<int|string|null> $cells */
	private static function line(array $cells): string {
		return implode(',', array_map(static fn (int|string|null $cell): string => is_string($cell) ? self::cell($cell) : (string)$cell, $cells));
	}

	private static function cell(string $value): string {
		if (strspn($value, "=+-@\t\r", 0, 1) === 1) {
			$value = "'" . $value;
		}

		return strpbrk($value, ",\"\r\n") === false ? $value : '"' . str_replace('"', '""', $value) . '"';
	}
}
