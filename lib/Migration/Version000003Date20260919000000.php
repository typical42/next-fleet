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
 * What M3 ships: what a vehicle burns and what it costs, and a second counter for engine hours
 * beside the kilometres (docs/architecture.md#data-model). The milestone's only migration, for
 * the reason M2's is.
 */
class Version000003Date20260919000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover tables and columns.
		if (!$schema->hasTable('fleet_energy')) {
			$this->energy($this->common($schema->createTable('fleet_energy')));
		}
		if (!$schema->hasTable('fleet_maintenance')) {
			$this->maintenance($this->common($schema->createTable('fleet_maintenance')));
		}
		if (!$schema->hasTable('fleet_expenses')) {
			$this->expenses($this->common($schema->createTable('fleet_expenses')));
		}

		// Nullable, so the Readings already written need no backfill: null reads as `main`.
		$readings = $schema->getTable('fleet_odo_readings');
		if (!$readings->hasColumn('counter')) {
			$readings->addColumn('counter', Types::STRING, ['notnull' => false, 'length' => 8]);
		}

		$vehicles = $schema->getTable('fleet_vehicles');
		if (!$vehicles->hasColumn('second_unit')) {
			$vehicles->addColumn('second_unit', Types::STRING, ['notnull' => false, 'length' => 8]);
		}
		if (!$vehicles->hasColumn('second_value')) {
			$vehicles->addColumn('second_value', Types::BIGINT, ['notnull' => false]);
		}

		return $schema;
	}

	/**
	 * One fill-up or charging session. Either counter is optional and never prefilled; without
	 * one the row writes no Reading and closes no consumption segment.
	 */
	private function energy(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('filled_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('filled_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('second_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('energy', Types::STRING, ['notnull' => true, 'length' => 16]);
		// Millilitres or watt-hours, per `energy`: a plug-in hybrid has both kinds of row.
		$table->addColumn('amount', Types::BIGINT, ['notnull' => true]);
		// Tenths of a cent per litre or kWh, because pumps price to the tenth.
		$table->addColumn('unit_price', Types::BIGINT, ['notnull' => false]);
		// Gross. A missing total is "no price", saved and flagged rather than refused.
		$table->addColumn('total', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('vat_rate', Types::INTEGER, ['notnull' => false]);
		// Defaulted for the reason every boolean here is; the service states each one.
		$table->addColumn('full_tank', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('missed_previous', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('station', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('is_dc', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('location_kind', Types::STRING, ['notnull' => false, 'length' => 16]);

		$table->addUniqueIndex(['uuid'], 'fleet_nrg_uuid_uniq');
		$table->addIndex(['vehicle_id', 'filled_at'], 'fleet_nrg_veh_fill_idx');
	}

	/**
	 * One service, repair, inspection, tyre change or upgrade. No `reminder_id`: reminders
	 * arrive with M4, and the column with them.
	 */
	private function maintenance(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('done_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('done_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('second_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('vendor', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('cost', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('vat_rate', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('notes', Types::TEXT, ['notnull' => false]);

		$table->addUniqueIndex(['uuid'], 'fleet_mnt_uuid_uniq');
		$table->addIndex(['vehicle_id', 'done_at'], 'fleet_mnt_veh_done_idx');
	}

	/**
	 * Any other cost - insurance, tax, toll, parking, a fine, a lease. It knows no counter.
	 */
	private function expenses(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('spent_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('spent_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('amount', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('vat_rate', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('notes', Types::TEXT, ['notnull' => false]);

		$table->addUniqueIndex(['uuid'], 'fleet_exp_uuid_uniq');
		$table->addIndex(['vehicle_id', 'spent_at'], 'fleet_exp_veh_spent_idx');
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
