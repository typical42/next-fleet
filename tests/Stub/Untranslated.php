<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Stub;

use OCP\IL10N;

/**
 * An English reader's `IL10N` for a test with no server: the source strings as written, a date as
 * `Y-m-d` and a time as `H:i`. Both are read in the zone the `DateTime` carries, as Nextcloud's own
 * does, so a test sees the wall clock a caller meant.
 */
final class Untranslated implements IL10N {
	public function t(string $text, $parameters = []): string {
		return vsprintf($text, (array)$parameters);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural), $parameters);
	}

	public function l(string $type, $data, array $options = []) {
		$format = ['date' => 'Y-m-d', 'time' => 'H:i', 'datetime' => 'Y-m-d H:i'][$type] ?? 'c';

		return $data instanceof \DateTimeInterface ? $data->format($format) : gmdate($format, (int)$data);
	}

	public function getLanguageCode(): string {
		return 'en';
	}

	public function getLocaleCode(): string {
		return 'en';
	}
}
