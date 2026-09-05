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
 * The three tables M1 ships: a vehicle, the readings that give it an odometer, and who may see
 * it (docs/architecture.md#data-model). Every further table arrives with the milestone that
 * needs it.
 */
class Version000001Date20260101000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded because a removed app leaves its tables behind while its oc_migrations rows
		// go: the step then runs again over a table that already exists, and an unguarded
		// createTable() fails the whole re-install. It does mean an edited migration applies
		// nothing to an install that has these tables - see docs/development.md#testing.
		if (!$schema->hasTable('fleet_vehicles')) {
			$this->vehicles($this->common($schema->createTable('fleet_vehicles')));
		}
		if (!$schema->hasTable('fleet_odo_readings')) {
			$this->odoReadings($this->common($schema->createTable('fleet_odo_readings')));
		}
		if (!$schema->hasTable('fleet_access')) {
			$this->access($this->common($schema->createTable('fleet_access')));
		}

		return $schema;
	}

	/**
	 * Who may see a vehicle besides its owner, in our own table rather than a core share
	 * (docs/adr/0001-own-access-table.md). One row per grant.
	 */
	private function access(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('grantee', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('grantee_type', Types::STRING, ['notnull' => true, 'length' => 8]);
		$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);

		$table->addUniqueIndex(['uuid'], 'fleet_acc_uuid_uniq');
		// Every check asks what one grantee may reach.
		$table->addIndex(['grantee'], 'fleet_acc_grantee_idx');
	}

	/**
	 * The odometer itself: every Entry that knows a mileage writes one of these, and the
	 * vehicle only caches the newest (docs/architecture.md#odometer-rules). No foreign key on
	 * `source_id` - the tables it points into arrive with M2.
	 */
	private function odoReadings(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		// The user's own instant, so it carries the offset it was read at; ordering uses the
		// instant, a legal view the local date the pair yields.
		$table->addColumn('read_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('read_at_off', Types::INTEGER, ['notnull' => true]);
		// Kilometres or engine hours, per the vehicle's odo_unit.
		$table->addColumn('value', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16]);
		// A lower reading is a flag, not an error.
		$table->addColumn('flagged', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('source_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_odo_uuid_uniq');
		// Readings are ordered by (read_at, id), never by value, so the index is that order.
		$table->addIndex(['vehicle_id', 'read_at'], 'fleet_odo_veh_read_idx');
	}

	/**
	 * A vehicle is the only thing M1 lets a user create. Money is integer cents, volumes
	 * millilitres, energy watt-hours; `odo_value` is a cache of the newest reading and no column
	 * is named after a unit it might not hold.
	 */
	private function vehicles(Table $table): void {
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('plate', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('manufacturer', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('model', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('vehicle_type', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('engine', Types::STRING, ['notnull' => false, 'length' => 16]);
		// The energies the vehicle actually accepts, and authoritative over `engine`: a set,
		// so the column is one too.
		$table->addColumn('energy_types', Types::JSON, ['notnull' => false]);
		$table->addColumn('tank_ml', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('battery_wh', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		// One fact each - the day, not the moment - so neither takes a `_off` companion.
		$table->addColumn('first_reg', Types::DATE, ['notnull' => false]);
		$table->addColumn('disposed_at', Types::DATE, ['notnull' => false]);
		$table->addColumn('vin', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('odo_value', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('odo_unit', Types::STRING, ['notnull' => true, 'length' => 8]);
		$table->addColumn('purchase_price', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('residual_est', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('currency', Types::STRING, ['notnull' => false, 'length' => 3]);
		// No database default: the service picks the user's jurisdiction, and a floor here
		// would be a second place that decision lives.
		$table->addColumn('jurisdiction', Types::STRING, ['notnull' => true, 'length' => 8]);
		// Nullable because Nextcloud refuses a NOT NULL boolean, and defaulted because a
		// boolean an entity leaves untouched is never dirty, so QBMapper omits it from the
		// INSERT. Same for `flagged` on a reading.
		$table->addColumn('logbook_mode', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('lifecycle', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('folder_file_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('retention_months', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('notes', Types::TEXT, ['notnull' => false]);

		$table->addUniqueIndex(['uuid'], 'fleet_veh_uuid_uniq');
		$table->addIndex(['user_id'], 'fleet_veh_user_idx');
	}

	/**
	 * The row every table has: a key, an identity, its dating and its author
	 * (docs/architecture.md#data-model). Instants are unix seconds, which is what the base
	 * mapper stamps; `created_by` is not nullable, because the entity types it `string`.
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
