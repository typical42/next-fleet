<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every column a request may set carries a bound (docs/security.md): a text its length, a number
 * its maximum. Read off each service's WRITABLE, so a column added without one fails here before a
 * request finds it.
 */
class BoundsTest extends TestCase {
	/** The kinds whose third entry is a bound rather than a vocabulary. */
	private const BOUNDED = ['text', 'count', 'number'];

	/** @return array<string, array{string, string, mixed}> service and column => the column's kind and limit */
	public static function columns(): array {
		$columns = [];
		foreach (glob(__DIR__ . '/../../../lib/Service/*.php') ?: [] as $file) {
			$class = new \ReflectionClass('OCA\\NextFleet\\Service\\' . basename($file, '.php'));
			$writable = $class->getConstants()['WRITABLE'] ?? null;
			if (!is_array($writable)) {
				continue;
			}
			foreach ($writable as $column => $spec) {
				if (is_array($spec) && in_array($spec[1], self::BOUNDED, true)) {
					$columns[$class->getShortName() . ' ' . $column] = [$class->getShortName(), $spec[1], $spec[2]];
				}
			}
		}

		return $columns;
	}

	/** The services this check reaches: a rename that hid them would leave it testing nothing. */
	public function testItReadsEveryEntryService(): void {
		$services = array_unique(array_column(self::columns(), 0));
		sort($services);
		$this->assertSame(['EnergyService', 'ExpenseService', 'MaintenanceService', 'TripService', 'VehicleService'], $services);
	}

	#[DataProvider('columns')]
	public function testEachTextAndNumberHasABound(string $service, string $kind, mixed $limit): void {
		$this->assertIsInt($limit, $kind . ' without a bound');
		$this->assertGreaterThan(0, $limit);
	}

	/** Notes are the one free text with no column width to stop them; 10 000 is what docs/security.md states. */
	public function testNotesStopAtTenThousandCharacters(): void {
		$notes = array_filter(self::columns(), static fn (string $key): bool => str_ends_with($key, ' notes'), ARRAY_FILTER_USE_KEY);
		$this->assertCount(3, $notes);
		foreach ($notes as $key => [, , $limit]) {
			$this->assertSame(10_000, $limit, $key);
		}
	}
}
