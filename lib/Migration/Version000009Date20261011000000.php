<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Makes the columns of typed text four times as long on Oracle (docs/architecture.md#data-model).
 *
 * The app bounds a field in characters, and MariaDB and PostgreSQL count a column in characters
 * too. Oracle counts bytes, so there a name of n characters that are not all ASCII does not fit a
 * column of n. Four bytes is the most UTF-8 (AL32UTF8) spends on one character, so 4n holds any n
 * characters; a single-byte character set needs less. `VARCHAR2(n CHAR)` would say it directly, but Doctrine
 * does not model it, and the next migration to touch the column would turn it back into bytes.
 *
 * Only Oracle is altered: elsewhere the column already holds what the field allows, and an
 * upgrade rebuilds no table.
 */
class Version000009Date20261011000000 extends SimpleMigrationStep {
	private const BYTES_PER_CHARACTER = 4;

	/**
	 * Table => column => its length in characters, the bound its field is read under. Frozen: a
	 * column a later step adds does not exist yet when a fresh install runs this one, and an
	 * install past this step never runs it again. That later step creates its column at 4n on
	 * Oracle and adds it to Db\TypedText.
	 */
	private const COLUMNS = [
		'fleet_vehicles' => [
			'plate' => 32, 'display_name' => 64, 'manufacturer' => 64, 'model' => 64,
			'vin' => 32, 'color' => 32, 'jurisdiction' => 8,
		],
		'fleet_trips' => ['from_label' => 255, 'to_label' => 255, 'purpose' => 255, 'partner' => 255],
		'fleet_energy' => ['station' => 255],
		'fleet_maintenance' => ['title' => 255, 'vendor' => 255],
		'fleet_reminders' => ['title' => 255],
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Before the schema: reading it introspects the whole database, and returning it diffs
		// that twice more, all for nothing off Oracle.
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_ORACLE) {
			return null;
		}

		$schema = $schemaClosure();
		foreach (self::COLUMNS as $table => $columns) {
			foreach ($columns as $name => $length) {
				// From the list, never from the column, so a second run over a widened column
				// changes nothing.
				$schema->getTable($table)->getColumn($name)->setLength(self::BYTES_PER_CHARACTER * $length);
			}
		}

		return $schema;
	}
}
