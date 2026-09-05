<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Migration\Fixture;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;

/**
 * What `$schemaClosure()` hands a migration, minus the server: a real Doctrine schema, with
 * the `oc_` prefixing the server does, so the names a migration chooses are measured at the
 * length the database will see.
 */
class SchemaWrapper implements ISchemaWrapper {
	public const PREFIX = 'oc_';

	private Schema $schema;

	public function __construct() {
		$this->schema = new Schema();
	}

	public function getTable($tableName): Table {
		return $this->schema->getTable(self::PREFIX . $tableName);
	}

	public function hasTable($tableName): bool {
		return $this->schema->hasTable(self::PREFIX . $tableName);
	}

	public function createTable($tableName): Table {
		return $this->schema->createTable(self::PREFIX . $tableName);
	}

	public function dropTable($tableName): Schema {
		return $this->schema->dropTable(self::PREFIX . $tableName);
	}

	/** @return Table[] */
	public function getTables(): array {
		return $this->schema->getTables();
	}

	/** @return string[] */
	public function getTableNames(): array {
		return array_map(static fn (Table $table): string => $table->getName(), $this->schema->getTables());
	}

	/** @return string[] */
	public function getTableNamesWithoutPrefix(): array {
		return array_map(
			static fn (string $name): string => substr($name, strlen(self::PREFIX)),
			$this->getTableNames(),
		);
	}

	public function getDatabasePlatform(): AbstractPlatform {
		return new MariaDBPlatform();
	}
}
