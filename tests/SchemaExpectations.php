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

	public function testHoldsTheThreeTablesMilestoneOneNeedsAndNoOthers(): void {
		$tables = $this->fleetTableNames();
		sort($tables);

		$this->assertSame(['fleet_access', 'fleet_odo_readings', 'fleet_vehicles'], $tables);
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
		}
	}
}
