<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests;

use OCA\NextFleet\Migration\Version000001Date20260101000000;
use OCA\NextFleet\Migration\Version000002Date20260909000000;
use OCP\Migration\SimpleMigrationStep;

/**
 * Every migration the app ships, in the order MigrationService runs them. Named here rather
 * than discovered from the directory, so a step that is added and not wired into the schema
 * tests fails them instead of being silently skipped.
 */
final class MigrationSteps {
	/** @return list<SimpleMigrationStep> */
	public static function inOrder(): array {
		return [
			new Version000001Date20260101000000(),
			new Version000002Date20260909000000(),
		];
	}

	/**
	 * The tables those steps create. The schema tests drop them before running the steps, and a
	 * table missing from this list would be measured as the previous run left it.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return ['fleet_vehicles', 'fleet_odo_readings', 'fleet_access', 'fleet_trips', 'fleet_audit'];
	}
}
