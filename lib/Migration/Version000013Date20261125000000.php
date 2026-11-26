<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
// Docblock types only, for the reason Version000001Date20260101000000 gives.
use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\PartnerBackfill;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A partner is a record in its owner's list, and a trip links to it while it keeps its text
 * ([ADR 0013](docs/adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)). Every text there
 * is gets its record and its link (PartnerBackfill).
 *
 * The owner keeps a list of cost centres too; trips, fill-ups, maintenance records and expenses
 * name one, and a vehicle names the one a new entry takes. The owner plans a budget per vehicle
 * and calendar year.
 */
class Version000013Date20261125000000 extends SimpleMigrationStep {
	/**
	 * The name's length in characters, the trip text's. Frozen here, as Version000009Date20261011000000
	 * freezes its list; Db\TypedText holds it for the code.
	 */
	private const NAME = 255;

	/** A cost centre's code and name, in characters, frozen for the same reason. */
	private const CODE = 32;
	private const COST_CENTRE = 255;

	/** The entry tables that name a cost centre, each with its index prefix. */
	private const ENTRIES = ['fleet_trips' => 'fleet_trip', 'fleet_energy' => 'fleet_nrg', 'fleet_maintenance' => 'fleet_mnt', 'fleet_expenses' => 'fleet_exp'];

	/** What UTF-8 (AL32UTF8) spends on one character at most, where Oracle counts bytes. */
	private const BYTES_PER_CHARACTER = 4;

	public function __construct(
		private IDBConnection $db,
		private PartnerBackfill $partners,
	) {
	}

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftovers. An id, as `vehicle_id` is.
		$trips = $schema->getTable('fleet_trips');
		if (!$trips->hasColumn('partner_id')) {
			$trips->addColumn('partner_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		// A record's trips, for the spelling that finds it (PartnerMapper::findLinkedByText()).
		if (!$trips->hasIndex('fleet_trip_partner_idx')) {
			$trips->addIndex(['partner_id'], 'fleet_trip_partner_idx');
		}
		if (!$schema->hasTable('fleet_partners')) {
			$this->partners($schema->createTable('fleet_partners'));
		}
		// What changed in the lists a sync hands over (SyncService::partners()).
		$partners = $schema->getTable('fleet_partners');
		if (!$partners->hasIndex('fleet_ptn_user_upd_idx')) {
			$partners->addIndex(['user_id', 'updated_at'], 'fleet_ptn_user_upd_idx');
		}
		// An erasure asks every table whether it names the account (BaseMapper::names()).
		if (!$partners->hasIndex('fleet_ptn_creator_idx')) {
			$partners->addIndex(['created_by'], 'fleet_ptn_creator_idx');
		}

		if (!$schema->hasTable('fleet_cost_centres')) {
			$this->costCentres($schema->createTable('fleet_cost_centres'));
		}
		foreach (self::ENTRIES as $name => $prefix) {
			$table = $schema->getTable($name);
			if (!$table->hasColumn('cost_centre_id')) {
				$table->addColumn('cost_centre_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			}
			// Whether anything names a cost centre, which a delete asks first.
			if (!$table->hasIndex($prefix . '_cc_idx')) {
				$table->addIndex(['cost_centre_id'], $prefix . '_cc_idx');
			}
		}
		$vehicles = $schema->getTable('fleet_vehicles');
		if (!$vehicles->hasColumn('cost_centre_id')) {
			$vehicles->addColumn('cost_centre_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}

		if (!$schema->hasTable('fleet_budgets')) {
			$this->budgets($schema->createTable('fleet_budgets'));
		}

		return $schema;
	}

	/**
	 * Every run links what is left, so a run that died after its schema change is finished by the
	 * next.
	 *
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->partners->run();
	}

	/**
	 * The name is typed text, so 4n on Oracle. The address and the note are free
	 * text of any length.
	 *
	 * @param Table $table
	 */
	private function partners($table): void {
		$bytes = $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE ? self::BYTES_PER_CHARACTER : 1;
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => $bytes * self::NAME]);
		$table->addColumn('address', Types::TEXT, ['notnull' => false]);
		$table->addColumn('note', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'fleet_ptn_uuid_uniq');
		$table->addIndex(['user_id', 'name'], 'fleet_ptn_user_name_idx');
	}

	/**
	 * Code and name are typed text, so 4n on Oracle. `archived` is nullable for the reason a
	 * Reading's `flagged` is.
	 *
	 * @param Table $table
	 */
	private function costCentres($table): void {
		$bytes = $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE ? self::BYTES_PER_CHARACTER : 1;
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('code', Types::STRING, ['notnull' => false, 'length' => $bytes * self::CODE]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => $bytes * self::COST_CENTRE]);
		$table->addColumn('archived', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'fleet_cc_uuid_uniq');
		// What changed in the lists a sync hands over, and an erasure's probe (BaseMapper::names()).
		$table->addIndex(['user_id', 'updated_at'], 'fleet_cc_user_upd_idx');
		$table->addIndex(['created_by'], 'fleet_cc_creator_idx');
	}

	/**
	 * `net` is nullable for the reason a Reading's `flagged` is. `warned` is the highest share
	 * the warning has told (BudgetWarnings), 0 for none yet.
	 *
	 * @param Table $table
	 */
	private function budgets($table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('amount', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('net', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('warned', Types::SMALLINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'fleet_bgt_uuid_uniq');
		// What a sync hands over, and an erasure's probe (BaseMapper::names()).
		$table->addIndex(['vehicle_id', 'updated_at', 'id'], 'fleet_bgt_veh_upd_idx');
		$table->addIndex(['created_by'], 'fleet_bgt_creator_idx');
	}
}
