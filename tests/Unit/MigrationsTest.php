<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use OCP\Migration\IMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud reads lib/Migration/ by convention, and punishes both ways of breaking it out
 * of sight of the rest of the suite: a class that is not a migration step aborts
 * `occ app:enable` with "Not a valid migration", and a file whose name does not fit the
 * pattern is skipped in silence, so its tables are simply never created.
 */
class MigrationsTest extends TestCase {
	private const DIRECTORY = __DIR__ . '/../../lib/Migration';

	/** @return iterable<string, array{string}> */
	public static function migrations(): iterable {
		foreach (glob(self::DIRECTORY . '/*.php') ?: [] as $file) {
			$name = basename($file, '.php');
			yield $name => [$name];
		}
	}

	/**
	 * `MigrationService` matches this pattern and ignores everything else in the directory.
	 *
	 * @dataProvider migrations
	 */
	public function testEveryMigrationIsNamedSoNextcloudFindsIt(string $name): void {
		$this->assertMatchesRegularExpression('/^Version\d+Date\d+$/', $name);
	}

	/**
	 * @dataProvider migrations
	 */
	public function testEveryMigrationIsOneNextcloudCanRun(string $name): void {
		$class = 'OCA\\NextFleet\\Migration\\' . $name;

		$this->assertTrue(class_exists($class), "$class does not autoload");
		$this->assertTrue(
			is_subclass_of($class, IMigrationStep::class),
			"$class is no " . IMigrationStep::class . ', so enabling the app fails with "Not a valid migration"',
		);
	}
}
