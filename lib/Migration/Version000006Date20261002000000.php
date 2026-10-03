<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What M7 ships: bookings and their handover (docs/architecture.md#data-model), and the
 * `vehicle_id` index on grants M6 left for the next migration. The milestone's only migration,
 * for the reason M2's is.
 */
class Version000006Date20261002000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover tables and indexes.
		if (!$schema->hasTable('fleet_bookings')) {
			$this->bookings($this->common($schema->createTable('fleet_bookings')));
		}

		$access = $schema->getTable('fleet_access');
		if (!$access->hasIndex('fleet_acc_veh_idx')) {
			$access->addIndex(['vehicle_id'], 'fleet_acc_veh_idx');
		}

		return $schema;
	}

	/**
	 * One person's plan for a vehicle, and its handover: check-out and check-in are this row's
	 * own two moments, not rows of their own, so the handover's columns are null until it
	 * happens.
	 */
	private function bookings(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('starts_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('starts_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('ends_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('ends_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('purpose', Types::TEXT, ['notnull' => false]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('out_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('out_at_off', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('out_odo', Types::BIGINT, ['notnull' => false]);
		// A percentage of the main tank or battery: a plug-in hybrid names the other in the note.
		$table->addColumn('out_level', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('out_notes', Types::TEXT, ['notnull' => false]);
		$table->addColumn('in_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('in_at_off', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('in_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('in_level', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('in_notes', Types::TEXT, ['notnull' => false]);
		$table->addColumn('trip_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_bkg_uuid_uniq');
		// A vehicle's bookings by time, and the overlap check before each new one.
		$table->addIndex(['vehicle_id', 'starts_at'], 'fleet_bkg_veh_start_idx');
	}

	/**
	 * The row every table has. Copied, not shared, for the reason M2's copy gives.
	 */
	private function common(Table $table): Table {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->setPrimaryKey(['id']);

		return $table;
	}
}
