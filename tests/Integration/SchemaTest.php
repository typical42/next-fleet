<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Tests\Fixture\SchemaWrapper;
use OCA\NextFleet\Tests\MigrationSteps as Steps;
use OCA\NextFleet\Tests\SchemaExpectations;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The migrations on a real database: the tables are dropped and rebuilt from the steps
 * themselves, along the path MigrationService takes, and what the database made of them is
 * then measured against the same expectations the unit test uses.
 *
 * It empties the app's tables, so it belongs in a dev container and nowhere near data anyone
 * wants (docs/development.md#testing).
 */
class SchemaTest extends TestCase {
	use SchemaExpectations;

	/** Read back once: the migration is what is under test, not its repetition. */
	private static ?Schema $live = null;

	protected function setUp(): void {
		if (self::$live !== null) {
			return;
		}

		$db = Server::get(IDBConnection::class);
		foreach (Steps::tables() as $table) {
			if ($db->tableExists($table)) {
				$db->dropTable($table);
			}
		}

		// Each step is handed the database as it now is, and the diff between that and what it
		// leaves behind is the migration.
		$schema = $db->createSchema();
		$wrapper = new SchemaWrapper(
			$schema,
			Server::get(IConfig::class)->getSystemValueString('dbtableprefix', SchemaWrapper::PREFIX),
			$db->getDatabasePlatform(),
		);

		foreach (Steps::inOrder() as $step) {
			$step->changeSchema(
				$this->createMock(IOutput::class),
				static fn (): ISchemaWrapper => $wrapper,
				[],
			);
		}
		$db->migrateToSchema($schema);

		self::$live = $db->createSchema();
	}

	public static function tearDownAfterClass(): void {
		self::$live = null;
	}

	private function prefixed(string $name): string {
		return Server::get(IConfig::class)->getSystemValueString('dbtableprefix', SchemaWrapper::PREFIX) . $name;
	}

	protected function fleetTableNames(): array {
		$prefix = $this->prefixed('');

		$names = [];
		foreach (self::$live?->getTables() ?? [] as $table) {
			$name = $table->getName();
			if (str_starts_with($name, $prefix . 'fleet_')) {
				$names[] = substr($name, strlen($prefix));
			}
		}

		return $names;
	}

	protected function table(string $name): Table {
		$this->assertNotNull(self::$live);

		return self::$live->getTable($this->prefixed($name));
	}
}
