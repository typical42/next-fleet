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
 * The two tables M2 ships: the trips a vehicle's logbook is made of, and the audit trail that
 * makes them evidence (docs/architecture.md#data-model). It is the milestone's only migration -
 * a second one would leave installs on either end of the supported range with different schemas.
 */
class Version000002Date20260909000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover tables.
		if (!$schema->hasTable('fleet_trips')) {
			$this->trips($this->common($schema->createTable('fleet_trips')));
		}
		if (!$schema->hasTable('fleet_audit')) {
			$this->audit($this->common($schema->createTable('fleet_audit')));
		}

		return $schema;
	}

	/**
	 * One journey. Either end odometer or distance is what the driver entered and the other is
	 * derived, so both are nullable; `start_odo` is nullable for a third reason - it is a claim
	 * about the counter, never a Reading (docs/architecture.md#odometer-rules).
	 */
	private function trips(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		// Both ends are the user's own instants, so each carries the offset it was entered at:
		// a Fahrtenbuch is judged on local calendar dates.
		$table->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('started_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('ended_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('ended_at_off', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('start_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('end_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('distance', Types::BIGINT, ['notnull' => false]);
		// A German logbook wants the destination in full, so the labels get room for an address.
		$table->addColumn('from_label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('to_label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('purpose', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('partner', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 16]);
		// A Reconciliation Trip, created to close a Gap. Nullable and defaulted for the reason
		// every boolean here is.
		$table->addColumn('reconciled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

		$table->addUniqueIndex(['uuid'], 'fleet_trip_uuid_uniq');
		// Trips are read in the order they happened, like Readings, and `id` breaks the tie.
		$table->addIndex(['vehicle_id', 'started_at'], 'fleet_trip_veh_start_idx');
	}

	/**
	 * What changed, on what, by whom and when - written only under Logbook Mode
	 * (docs/features.md#logbook-mode). Who and when are `created_by` and `created_at`: an audit
	 * row is written in the same transaction as the change it records, so a second author and a
	 * second clock would only be two places for the same fact to disagree.
	 *
	 * No foreign key on `entity_id`: the trail has to outlive an erasure of what it describes,
	 * and it points into more than one table.
	 */
	private function audit(Table $table): void {
		$table->addColumn('entity', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('entity_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		// The changed fields, plus any fact the change itself carried - that an edit was late,
		// that a trip was derived rather than observed.
		$table->addColumn('diff_json', Types::JSON, ['notnull' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_aud_uuid_uniq');
		// Every read asks for the trail of one row.
		$table->addIndex(['entity', 'entity_id'], 'fleet_aud_entity_idx');
	}

	/**
	 * The row every table has (docs/architecture.md#data-model). Copied from the first migration
	 * rather than shared with it: a migration is a record of what one version did, and a helper
	 * both steps call is a helper that can rewrite history the next time it is edited.
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
