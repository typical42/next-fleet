<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What M12 ships: indexes only, for reads that scanned a vehicle's whole history
 * (docs/architecture.md#data-model). The milestone's only migration, for the reason M2's is.
 */
class Version000007Date20261003000000 extends SimpleMigrationStep {
	/** Index name => [table, columns]. Names spelled out, so a grep finds where each comes from. */
	private const INDEXES = [
		// A sync page walks a table in (updated_at, id) order per vehicle.
		'fleet_odo_veh_upd_idx' => ['fleet_odo_readings', ['vehicle_id', 'updated_at', 'id']],
		'fleet_trip_veh_upd_idx' => ['fleet_trips', ['vehicle_id', 'updated_at', 'id']],
		'fleet_nrg_veh_upd_idx' => ['fleet_energy', ['vehicle_id', 'updated_at', 'id']],
		'fleet_mnt_veh_upd_idx' => ['fleet_maintenance', ['vehicle_id', 'updated_at', 'id']],
		'fleet_exp_veh_upd_idx' => ['fleet_expenses', ['vehicle_id', 'updated_at', 'id']],
		'fleet_rem_veh_upd_idx' => ['fleet_reminders', ['vehicle_id', 'updated_at', 'id']],
		'fleet_doc_veh_upd_idx' => ['fleet_documents', ['vehicle_id', 'updated_at', 'id']],
		'fleet_bkg_veh_upd_idx' => ['fleet_bookings', ['vehicle_id', 'updated_at', 'id']],
		'fleet_acc_veh_upd_idx' => ['fleet_access', ['vehicle_id', 'updated_at', 'id']],
		// An erasure and a personal export look for an author in every table that has one.
		'fleet_odo_creator_idx' => ['fleet_odo_readings', ['created_by']],
		'fleet_trip_creator_idx' => ['fleet_trips', ['created_by']],
		'fleet_nrg_creator_idx' => ['fleet_energy', ['created_by']],
		'fleet_mnt_creator_idx' => ['fleet_maintenance', ['created_by']],
		'fleet_exp_creator_idx' => ['fleet_expenses', ['created_by']],
		'fleet_doc_creator_idx' => ['fleet_documents', ['created_by']],
		'fleet_aud_creator_idx' => ['fleet_audit', ['created_by']],
		'fleet_bkg_creator_idx' => ['fleet_bookings', ['created_by']],
		// The Reading an entry wrote, and one source's Readings by time.
		'fleet_odo_veh_src_idx' => ['fleet_odo_readings', ['vehicle_id', 'source_type', 'source_id']],
		'fleet_odo_veh_src_read_idx' => ['fleet_odo_readings', ['vehicle_id', 'source_type', 'read_at']],
		// Who has the car: a vehicle's bookings out or booked.
		'fleet_bkg_veh_state_idx' => ['fleet_bookings', ['vehicle_id', 'state']],
	];

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		foreach (self::INDEXES as $name => [$table, $columns]) {
			// Guarded for the reason the first migration is guarded: a re-install meets its own
			// leftover indexes.
			if (!$schema->getTable($table)->hasIndex($name)) {
				$schema->getTable($table)->addIndex($columns, $name);
			}
		}

		return $schema;
	}
}
