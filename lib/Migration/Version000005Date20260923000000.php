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
 * What M5 ships: a vehicle's papers, kept in Nextcloud Files and referenced from here
 * (docs/architecture.md#data-model). The milestone's only migration, for the reason M2's is.
 */
class Version000005Date20260923000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover tables.
		if (!$schema->hasTable('fleet_documents')) {
			$this->documents($this->common($schema->createTable('fleet_documents')));
		}

		return $schema;
	}

	/**
	 * One file on a vehicle, by the id Nextcloud gives it: that survives a move, where a path
	 * would not. Signed, as `filecache.fileid` is. A document may belong to one entry of the
	 * same vehicle - a fill-up, a maintenance record or an expense.
	 */
	private function documents(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('linked_type', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('linked_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_doc_uuid_uniq');
		$table->addIndex(['vehicle_id'], 'fleet_doc_veh_idx');
		// The paperclip on a timeline row asks which documents an entry has.
		$table->addIndex(['linked_type', 'linked_id'], 'fleet_doc_linked_idx');
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
