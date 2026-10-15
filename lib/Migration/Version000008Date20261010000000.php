<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A vehicle's display name and the two EU codes of its registration certificate
 * (docs/architecture.md#data-model). Nullable, so the vehicles already there need no backfill.
 */
class Version000008Date20261010000000 extends SimpleMigrationStep {
	/** Column => length. */
	private const COLUMNS = [
		'display_name' => 64,
		// The full category code, an off-road `G` included: `M1`, `N1G`, `L3e-A2E`, `T4.1b`.
		'eu_category' => 8,
		// The body code with its two-digit add-on: `AC`, `BA03`.
		'body_type' => 4,
	];

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$vehicles = $schema->getTable('fleet_vehicles');

		foreach (self::COLUMNS as $name => $length) {
			// Guarded for the reason the first migration is guarded: a re-install meets its own
			// leftover columns.
			if (!$vehicles->hasColumn($name)) {
				$vehicles->addColumn($name, Types::STRING, ['notnull' => false, 'length' => $length]);
			}
		}

		return $schema;
	}
}
