<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IRateProvider;

/**
 * German rates by date, cited by URL and not legal advice (docs/legal.md).
 */
class RateProvider implements IRateProvider {
	/**
	 * The standard rate by the first day it applied, newest last. The table starts at 2007: an
	 * earlier day is not stated rather than guessed.
	 */
	private const VAT = [
		'2007-01-01' => 1900,
		'2020-07-01' => 1600,
		'2021-01-01' => 1900,
	];

	public function vatRateAt(\DateTimeInterface $when): ?int {
		// Compared as ISO dates, which sort as strings do.
		$day = $when->format('Y-m-d');
		$rate = null;
		foreach (self::VAT as $from => $bp) {
			if ($day >= $from) {
				$rate = $bp;
			}
		}

		return $rate;
	}

	/** §12 UStG, the statute itself. */
	public function vatSourceUrl(): string {
		return 'https://www.gesetze-im-internet.de/ustg_1980/__12.html';
	}
}
