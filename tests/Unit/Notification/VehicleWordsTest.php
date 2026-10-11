<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Notification;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Notification\VehicleWords;
use OCA\NextFleet\Tests\Fixture\SharedCases;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A vehicle named in a notice or a mail as the screen names it: src/utils/format.spec.js `nameOf` reads the same cases. */
class VehicleWordsTest extends TestCase {
	/** @return array<string, array{array<string, string>, string}> */
	public static function vehicles(): array {
		$cases = [];
		/** @var array{case: string, vehicle: array<string, string>, name: string} $case */
		foreach (SharedCases::read('vehicle-labels') as $case) {
			$cases[$case['case']] = [$case['vehicle'], $case['name']];
		}

		return $cases;
	}

	/** @param array<string, string> $row */
	#[DataProvider('vehicles')]
	public function testAVehicleIsNamedAsTheScreenNamesIt(array $row, string $expected): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->assertSame($expected, VehicleWords::name($l, Vehicle::fromRow($row)));
	}
}
