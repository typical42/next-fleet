<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\BudgetWarnings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The share of a budget the year's costs have reached, which the warning tells at 80 % and at
 * 100 % (CONTEXT.md, Budget).
 */
class BudgetWarningsTest extends TestCase {
	/** @return array<string, array{int, int, int}> amount, spent, share reached */
	public static function shares(): array {
		return [
			'nothing spent' => [100000, 0, 0],
			'a cent short of 80 %' => [100000, 79999, 0],
			'80 % to the cent' => [100000, 80000, 80],
			'a cent short of all' => [100000, 99999, 80],
			'all of it' => [100000, 100000, 100],
			'more than all' => [100000, 250000, 100],
			// 80 % of 1.01 is 0.808: 0.80 is short of it, 0.81 is past.
			'an amount 80 % of is no whole cent, short' => [101, 80, 0],
			'an amount 80 % of is no whole cent, past' => [101, 81, 80],
		];
	}

	#[DataProvider('shares')]
	public function testTheShareReachedIsTheHighestThresholdTheCostsMeet(int $amount, int $spent, int $share): void {
		$this->assertSame($share, BudgetWarnings::share($amount, $spent));
	}
}
