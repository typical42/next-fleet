<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use OCP\DB\Types;

/**
 * The data model of docs/architecture.md#data-model, stated once and asserted twice: against
 * the schema the first migration builds, and against the one MariaDB materialises from it.
 * A column the database quietly widens or drops is the kind of defect that only shows up in
 * production.
 */
trait SchemaExpectations {
	/**
	 * The app's tables in the schema under test, without the server's prefix.
	 *
	 * @return list<string>
	 */
	abstract protected function fleetTableNames(): array;

	/** @param string $name without the server's prefix */
	abstract protected function table(string $name): Table;

	/**
	 * A column as the data model states it. Only a string's length is a fact about the column;
	 * for the rest MariaDB reports 0 where Doctrine declared nothing.
	 */
	private function describe(Column $column): string {
		$type = Type::lookupName($column->getType());
		$default = $column->getDefault();

		// SQLite has one integer type, so it hands the key back as `integer` where every other
		// platform says `bigint`. That is the platform talking, not the schema.
		if ($column->getAutoincrement() && $type === Types::INTEGER) {
			$type = Types::BIGINT;
		}

		return implode(', ', array_filter([
			$type === Types::STRING ? $type . '(' . $column->getLength() . ')' : $type,
			$column->getNotnull() ? 'not null' : 'null',
			$column->getAutoincrement() ? 'autoincrement' : '',
			$default === null ? '' : 'default ' . var_export($type === Types::BOOLEAN ? (bool)$default : $default, true),
		]));
	}

	/** @return array<string, string> column name => its description */
	private function columns(string $table): array {
		$columns = [];
		foreach ($this->table($table)->getColumns() as $column) {
			$columns[$column->getName()] = $this->describe($column);
		}
		ksort($columns);

		return $columns;
	}

	/**
	 * The table's indexes, the primary key aside.
	 *
	 * @return array<string, string> index name => what it covers
	 */
	private function indexes(string $table): array {
		$indexes = [];
		foreach ($this->table($table)->getIndexes() as $index) {
			if ($index->isPrimary()) {
				continue;
			}

			$indexes[$index->getName()] = ($index->isUnique() ? 'unique(' : 'index(')
				. implode(', ', $index->getColumns()) . ')';
		}
		ksort($indexes);

		return $indexes;
	}

	/**
	 * The five columns every table carries, plus its key. `created_by` is not nullable because
	 * BaseEntity types it `string` and Entity::fromRow skips its cast for null - a null author
	 * would be a TypeError, not an empty one.
	 *
	 * @return array<string, string>
	 */
	private function commonColumns(): array {
		return [
			'id' => 'bigint, not null, autoincrement',
			'uuid' => 'string(36), not null',
			'created_at' => 'bigint, not null',
			'updated_at' => 'bigint, not null',
			'deleted_at' => 'bigint, null',
			'created_by' => 'string(64), not null',
		];
	}

	/**
	 * The table holds the common columns plus these and nothing else, keyed on `id`.
	 *
	 * @param array<string, string> $own
	 */
	private function assertTable(string $table, array $own): void {
		$expected = array_merge($this->commonColumns(), $own);
		ksort($expected);

		$this->assertSame($expected, $this->columns($table));

		$primary = $this->table($table)->getPrimaryKey();
		$this->assertNotNull($primary, $table . ' has no primary key');
		$this->assertSame(['id'], $primary->getColumns());
	}

	public function testHoldsTheTablesTheMilestonesSoFarNeedAndNoOthers(): void {
		$tables = $this->fleetTableNames();
		sort($tables);

		$this->assertSame(
			[
				'fleet_access', 'fleet_audit', 'fleet_documents', 'fleet_energy', 'fleet_expenses',
				'fleet_maintenance', 'fleet_odo_readings', 'fleet_reminder_receipts', 'fleet_reminder_recipients',
				'fleet_reminders', 'fleet_trips', 'fleet_vehicles',
			],
			$tables,
		);
	}

	public function testVehiclesHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_vehicles', [
			'user_id' => 'string(64), not null',
			'plate' => 'string(32), null',
			'manufacturer' => 'string(64), null',
			'model' => 'string(64), null',
			'vehicle_type' => 'string(32), not null',
			'engine' => 'string(16), null',
			// The set of energies the vehicle accepts, so the column is a set too.
			'energy_types' => 'json, null',
			'tank_ml' => 'integer, null',
			'battery_wh' => 'integer, null',
			// Plain calendar dates: one fact each, so neither takes a `_off` companion.
			'first_reg' => 'date, null',
			'disposed_at' => 'date, null',
			'vin' => 'string(32), null',
			// Null until the first reading; a cache, recomputed, never incremented.
			'odo_value' => 'bigint, null',
			'odo_unit' => 'string(8), not null',
			'purchase_price' => 'bigint, null',
			'residual_est' => 'bigint, null',
			'currency' => 'string(3), null',
			'jurisdiction' => 'string(8), not null',
			'logbook_mode' => 'boolean, null, default false',
			'lifecycle' => 'string(16), not null',
			'folder_file_id' => 'bigint, null',
			'retention_months' => 'integer, null',
			'color' => 'string(32), null',
			'notes' => 'text, null',
			// Null or `h`: engine hours counted beside the kilometres, on a chain of their own.
			'second_unit' => 'string(8), null',
			// A cache of that chain's newest Reading, as `odo_value` is of the first.
			'second_value' => 'bigint, null',
			// off, daily, weekly or monthly. Defaulted, so the vehicles already there need no
			// backfill.
			'reminder_mail' => "string(8), not null, default 'weekly'",
		]);
	}

	public function testOdoReadingsHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_odo_readings', [
			'vehicle_id' => 'bigint, not null',
			// The user's own instant, so it carries the offset it was read at.
			'read_at' => 'bigint, not null',
			'read_at_off' => 'integer, not null',
			// Kilometres or engine hours, per the vehicle's odo_unit. Never called km.
			'value' => 'bigint, not null',
			'kind' => 'string(16), not null',
			'origin' => 'string(16), not null',
			'flagged' => 'boolean, null, default false',
			'source_type' => 'string(16), not null',
			'source_id' => 'bigint, null',
			// `main` or `second`. Null reads as `main`: every Reading before M3 is one.
			'counter' => 'string(8), null',
		]);
	}

	public function testTripsHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_trips', [
			'vehicle_id' => 'bigint, not null',
			// Both ends are the user's own instants, so each carries the offset it was entered
			// at: a trip ending 00:30 in Berlin belongs to the previous day in UTC.
			'started_at' => 'bigint, not null',
			'started_at_off' => 'integer, not null',
			'ended_at' => 'bigint, not null',
			'ended_at_off' => 'integer, not null',
			// A claim, not a Reading - comparing it with the preceding Reading is what produces
			// gap detection.
			'start_odo' => 'bigint, null',
			// One of the two is what the driver entered; the other the service derives.
			'end_odo' => 'bigint, null',
			'distance' => 'bigint, null',
			'from_label' => 'string(255), null',
			'to_label' => 'string(255), null',
			'purpose' => 'string(255), null',
			'partner' => 'string(255), null',
			'category' => 'string(16), not null',
			// A Reconciliation Trip, created to close a Gap.
			'reconciled' => 'boolean, null, default false',
		]);
	}

	public function testEnergyHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_energy', [
			'vehicle_id' => 'bigint, not null',
			'filled_at' => 'bigint, not null',
			'filled_at_off' => 'integer, not null',
			// Optional and never prefilled: without one the fill-up writes no Reading.
			'odo' => 'bigint, null',
			'second_odo' => 'bigint, null',
			'energy' => 'string(16), not null',
			// Millilitres or watt-hours, per `energy`.
			'amount' => 'bigint, not null',
			// Tenths of a cent per litre or kWh: pumps price to the tenth.
			'unit_price' => 'bigint, null',
			// Gross cents. Null is "no price", saved and flagged.
			'total' => 'bigint, null',
			// Basis points. Null is "not stated", never zero.
			'vat_rate' => 'integer, null',
			'full_tank' => 'boolean, null, default false',
			'missed_previous' => 'boolean, null, default false',
			'station' => 'string(255), null',
			'is_dc' => 'boolean, null, default false',
			'location_kind' => 'string(16), null',
		]);
	}

	public function testMaintenanceHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_maintenance', [
			'vehicle_id' => 'bigint, not null',
			'type' => 'string(16), null',
			'done_at' => 'bigint, not null',
			'done_at_off' => 'integer, not null',
			'odo' => 'bigint, null',
			'second_odo' => 'bigint, null',
			'title' => 'string(255), not null',
			'vendor' => 'string(255), null',
			'cost' => 'bigint, null',
			'vat_rate' => 'integer, null',
			'notes' => 'text, null',
			// The reminder this record closed, if it closed one.
			'reminder_id' => 'bigint, null',
		]);
	}

	public function testExpensesHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_expenses', [
			'vehicle_id' => 'bigint, not null',
			'spent_at' => 'bigint, not null',
			'spent_at_off' => 'integer, not null',
			'category' => 'string(16), null',
			'amount' => 'bigint, not null',
			'vat_rate' => 'integer, null',
			'notes' => 'text, null',
		]);
	}

	public function testRemindersHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_reminders', [
			'vehicle_id' => 'bigint, not null',
			// A template's title translates and is not stored; a user's title is stored as typed.
			'template_key' => 'string(32), null',
			'title' => 'string(255), null',
			'mode' => 'string(8), not null',
			// A plain calendar date: due is a day, not an instant.
			'due_date' => 'date, null',
			'due_odo' => 'bigint, null',
			'lead_odo' => 'bigint, null',
			// The warning points of a date reminder. Each sends once.
			'warn_month_before' => 'boolean, null, default true',
			'warn_month_start' => 'boolean, null, default false',
			'warn_due_date' => 'boolean, null, default true',
			'recur_months' => 'integer, null',
			'recur_odo' => 'bigint, null',
			'state' => 'string(16), not null',
			'snoozed_until' => 'date, null',
			// Counts the recurrences, so a receipt belongs to one of them and the next one sends
			// afresh.
			'occurrence' => 'integer, not null',
		]);
	}

	public function testReminderReceiptsHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_reminder_receipts', [
			'reminder_id' => 'bigint, not null',
			'occurrence' => 'integer, not null',
			// Which warning point, or overdue.
			'point' => 'string(16), not null',
			'channel' => 'string(8), not null',
			'user_id' => 'string(64), not null',
			'sent_at' => 'bigint, not null',
		]);
	}

	public function testReminderRecipientsHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_reminder_recipients', [
			'vehicle_id' => 'bigint, not null',
			'user_id' => 'string(64), not null',
		]);
	}

	public function testDocumentsHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_documents', [
			'vehicle_id' => 'bigint, not null',
			// Nextcloud's own file id, which survives a move; no path is stored.
			'file_id' => 'bigint, not null',
			'kind' => 'string(16), not null',
			// The entry the paper belongs to, if any: energy, maintenance or expense.
			'linked_type' => 'string(16), null',
			'linked_id' => 'bigint, null',
		]);
	}

	public function testAuditHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_audit', [
			// Which table the row is about, and which row in it. No foreign key: the audit
			// outlives what it describes.
			'entity' => 'string(32), not null',
			'entity_id' => 'bigint, not null',
			// What changed, and any fact the change carried - late, derived. Who and when are
			// `created_by` and `created_at`, which every row has.
			'diff_json' => 'json, not null',
		]);
	}

	public function testAccessHoldsTheDataModelsColumnsAndNoOthers(): void {
		$this->assertTable('fleet_access', [
			'vehicle_id' => 'bigint, not null',
			// A user id or a group id, per grantee_type.
			'grantee' => 'string(64), not null',
			'grantee_type' => 'string(8), not null',
			'role' => 'string(16), not null',
		]);
	}

	/**
	 * Indexes are part of the schema, not an optimisation: the unique one on `uuid` is the
	 * database stating the identity, and the rest are the reads M1 makes.
	 */
	public function testIndexesAreTheOnesTheDataModelNames(): void {
		$this->assertSame([
			'fleet_veh_user_idx' => 'index(user_id)',
			'fleet_veh_uuid_uniq' => 'unique(uuid)',
		], $this->indexes('fleet_vehicles'));

		$this->assertSame([
			'fleet_odo_uuid_uniq' => 'unique(uuid)',
			'fleet_odo_veh_read_idx' => 'index(vehicle_id, read_at)',
		], $this->indexes('fleet_odo_readings'));

		$this->assertSame([
			'fleet_acc_grantee_idx' => 'index(grantee)',
			'fleet_acc_uuid_uniq' => 'unique(uuid)',
		], $this->indexes('fleet_access'));

		$this->assertSame([
			'fleet_trip_uuid_uniq' => 'unique(uuid)',
			'fleet_trip_veh_start_idx' => 'index(vehicle_id, started_at)',
		], $this->indexes('fleet_trips'));

		$this->assertSame([
			'fleet_aud_entity_idx' => 'index(entity, entity_id)',
			'fleet_aud_uuid_uniq' => 'unique(uuid)',
		], $this->indexes('fleet_audit'));

		$this->assertSame([
			'fleet_nrg_uuid_uniq' => 'unique(uuid)',
			'fleet_nrg_veh_fill_idx' => 'index(vehicle_id, filled_at)',
		], $this->indexes('fleet_energy'));

		$this->assertSame([
			'fleet_mnt_reminder_idx' => 'index(reminder_id)',
			'fleet_mnt_uuid_uniq' => 'unique(uuid)',
			'fleet_mnt_veh_done_idx' => 'index(vehicle_id, done_at)',
		], $this->indexes('fleet_maintenance'));

		$this->assertSame([
			'fleet_exp_uuid_uniq' => 'unique(uuid)',
			'fleet_exp_veh_spent_idx' => 'index(vehicle_id, spent_at)',
		], $this->indexes('fleet_expenses'));

		$this->assertSame([
			'fleet_rem_uuid_uniq' => 'unique(uuid)',
			'fleet_rem_veh_idx' => 'index(vehicle_id)',
		], $this->indexes('fleet_reminders'));

		// Unique, so a point sends once per occurrence, channel and recipient even when two runs
		// of the job overlap.
		$this->assertSame([
			'fleet_rrc_once_uniq' => 'unique(reminder_id, occurrence, point, channel, user_id)',
			'fleet_rrc_user_sent_idx' => 'index(user_id, channel, sent_at)',
			'fleet_rrc_uuid_uniq' => 'unique(uuid)',
		], $this->indexes('fleet_reminder_receipts'));

		$this->assertSame([
			'fleet_rcp_uuid_uniq' => 'unique(uuid)',
			'fleet_rcp_veh_user_uniq' => 'unique(vehicle_id, user_id)',
		], $this->indexes('fleet_reminder_recipients'));

		// A vehicle's papers, and the paperclip on an entry's timeline row.
		$this->assertSame([
			'fleet_doc_linked_idx' => 'index(linked_type, linked_id)',
			'fleet_doc_uuid_uniq' => 'unique(uuid)',
			'fleet_doc_veh_idx' => 'index(vehicle_id)',
		], $this->indexes('fleet_documents'));
	}

	/**
	 * A boolean is an integer of length 1 on the databases Nextcloud supports, and cannot be
	 * NOT NULL there: `ensureOracleConstraints` refuses the app over it - NC 31 does, while
	 * NC 34 lets it through, so the floor is the one that decides. The default carries the
	 * meaning instead.
	 */
	public function testNoBooleanColumnIsNotNull(): void {
		foreach ($this->fleetTableNames() as $name) {
			foreach ($this->table($name)->getColumns() as $column) {
				if (Type::lookupName($column->getType()) !== Types::BOOLEAN) {
					continue;
				}

				$this->assertFalse(
					$column->getNotnull(),
					$name . '.' . $column->getName() . ' is a NOT NULL boolean, which NC 31 rejects',
				);
				// A nullable boolean has to say what its absence means, or an entity that never
				// touched it writes null and reading the row back is a TypeError.
				$this->assertNotNull(
					$column->getDefault(),
					$name . '.' . $column->getName() . ' is a nullable boolean without a default',
				);
			}
		}
	}

	/**
	 * Nextcloud measures every identifier against Oracle's 30 characters when the app is
	 * enabled, the server's prefix included, and refuses the whole app over one that is too
	 * long.
	 */
	public function testEveryNameFitsTheThirtyCharactersNextcloudAllows(): void {
		foreach ($this->fleetTableNames() as $name) {
			$table = $this->table($name);

			$names = [$table->getName()];
			foreach ($table->getColumns() as $column) {
				$names[] = $column->getName();
			}
			foreach ($table->getIndexes() as $index) {
				$names[] = $index->getName();
			}

			foreach ($names as $identifier) {
				$this->assertLessThanOrEqual(30, strlen($identifier), $identifier . ' is too long for Oracle');
			}
			// The table's own name leaves three characters for the prefix, `oc_`.
			$this->assertLessThanOrEqual(27, strlen($name), $name . ' is too long for Nextcloud');
		}
	}
}
