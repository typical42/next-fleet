<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Fixture;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;

/**
 * What `$schemaClosure()` hands a migration, minus the server: a real Doctrine schema, with the
 * prefixing the server does, so the names a migration chooses are measured at the length the
 * database will see. Empty by default; an integration test wraps the live schema instead, which
 * is how Nextcloud's own MigrationService applies a step.
 */
class SchemaWrapper implements ISchemaWrapper {
	public const PREFIX = 'oc_';

	public function __construct(
		private Schema $schema = new Schema(),
		private string $prefix = self::PREFIX,
		private AbstractPlatform $platform = new MariaDBPlatform(),
	) {
	}

	public function getWrappedSchema(): Schema {
		return $this->schema;
	}

	public function getTable($tableName): Table {
		return $this->schema->getTable($this->prefix . $tableName);
	}

	public function hasTable($tableName): bool {
		return $this->schema->hasTable($this->prefix . $tableName);
	}

	public function createTable($tableName): Table {
		return $this->schema->createTable($this->prefix . $tableName);
	}

	public function dropTable($tableName): Schema {
		return $this->schema->dropTable($this->prefix . $tableName);
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
			fn (string $name): string => substr($name, strlen($this->prefix)),
			$this->getTableNames(),
		);
	}

	public function getDatabasePlatform(): AbstractPlatform {
		return $this->platform;
	}

	/**
	 * Not in the nextcloud/ocp stubs, which are pinned to the oldest supported major: the
	 * interface grew this in NC 33, and a class that implements the stub version of it is
	 * abstract - and fatal - on a newer server.
	 */
	public function dropAutoincrementColumn(string $table, string $column): void {
		$this->getTable($table)->getColumn($column)->setAutoincrement(false);
	}
}
