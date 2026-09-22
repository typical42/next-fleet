<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\De;

use OCA\NextFleet\Jurisdiction\De;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Tests\Country\JurisdictionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Germany takes the shared kit; what it answers is asserted where it is registered, the HU/AU here. */
class ProfileTest extends JurisdictionTestCase {
	public static function profile(): IJurisdiction {
		return new De\Profile();
	}

	/** @return array<string, array{string, int, int}> type, cadence, first inspection */
	public static function inspections(): array {
		return [
			'car' => ['car', 24, 36],
			'van' => ['van', 24, 24],
			'truck' => ['truck', 12, 12],
			'tractor' => ['tractor', 12, 12],
			'trailer' => ['trailer', 24, 24],
			'generator' => ['generator', 24, 24],
		];
	}

	#[DataProvider('inspections')]
	public function testItsHuAuRecursAtTheCadenceOfTheVehicleType(string $type, int $cadence, int $first): void {
		$scheme = static::profile()->inspectionScheme();
		$this->assertNotNull($scheme);

		$template = $scheme->template($type);
		$this->assertSame('hu_au', $template->key);
		$this->assertSame($cadence, $template->recurMonths);
		$this->assertSame($first, $scheme->firstDueMonths($type));
	}
}
