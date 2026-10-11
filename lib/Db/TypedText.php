<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

/**
 * The columns that hold typed text, four times as long on Oracle (docs/architecture.md#data-model).
 *
 * The schema tests expect 4n on Oracle for exactly these, and tests/Unit/Db/TypedTextTest.php
 * holds the set to the services' text fields. A migration that adds a typed-text column creates
 * it at 4n on Oracle itself and adds it here; the migration that widened the first ones keeps its
 * own list, since a shipped step must not change.
 */
final class TypedText {
	/** The most UTF-8 (AL32UTF8) spends on one character. */
	public const BYTES_PER_CHARACTER = 4;

	/** Table => column => its length in characters, the bound its field is read under. */
	public const COLUMNS = [
		'fleet_vehicles' => [
			'plate' => 32, 'display_name' => 64, 'manufacturer' => 64, 'model' => 64,
			'vin' => 32, 'color' => 32, 'jurisdiction' => 8,
		],
		'fleet_trips' => ['from_label' => 255, 'to_label' => 255, 'purpose' => 255, 'partner' => 255],
		'fleet_energy' => ['station' => 255],
		'fleet_maintenance' => ['title' => 255, 'vendor' => 255],
		'fleet_reminders' => ['title' => 255],
	];
}
