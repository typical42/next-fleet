<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\VehicleTables;
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
			match ($db->getDatabaseProvider()) {
				IDBConnection::PLATFORM_ORACLE => new OraclePlatform(),
				IDBConnection::PLATFORM_POSTGRES => new PostgreSQLPlatform(),
				IDBConnection::PLATFORM_SQLITE => new SqlitePlatform(),
				default => new MySQLPlatform(),
			},
		);

		foreach (Steps::inOrder($db, Server::get(ReminderRecipientMapper::class)) as $step) {
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

	/**
	 * PostgreSQL drops the name a migration gives the primary key and calls it `<table>_pkey`,
	 * within its own 63 characters. Oracle keeps the app's name, which this suite checks there.
	 */
	protected function namesThePrimaryKeyItself(): bool {
		return Server::get(IDBConnection::class)->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
	}

	/** `occ nextfleet:check` finds orphans only in the tables VehicleTables lists. */
	public function testVehicleTablesListsEveryTableWithAVehicleId(): void {
		$withColumn = array_values(array_filter($this->fleetTableNames(), fn (string $name): bool => $this->table($name)->hasColumn('vehicle_id')));
		$listed = array_map(static fn (BaseMapper $mapper): string => $mapper->getTableName(), Server::get(VehicleTables::class)->all());
		sort($withColumn);
		sort($listed);

		$this->assertSame($withColumn, $listed);
	}

	/**
	 * On Oracle, the key is numbered by the sequence Doctrine made for the table and the trigger
	 * that reads it into the column, both named after the table cut to fit 30 characters
	 * (BaseMapper::takeOracleId()). createSchema() leaves both out, so the catalogue says.
	 */
	protected function autoincrements(string $table, Column $column): bool {
		$db = Server::get(IDBConnection::class);
		if ($db->getDatabaseProvider() !== IDBConnection::PLATFORM_ORACLE) {
			return $column->getAutoincrement();
		}
		$prefixed = $this->prefixed($table);
		$sequence = substr($prefixed, 0, 26) . '_SEQ';
		// trigger_body is a LONG, which no SQL function takes.
		$result = $db->executeQuery(
			'SELECT trigger_body FROM user_triggers WHERE table_name = ? AND trigger_name = ?'
			. ' AND EXISTS (SELECT 1 FROM user_sequences WHERE sequence_name = ?)',
			[$prefixed, substr($prefixed, 0, 24) . '_AI_PK', $sequence],
		);
		$body = $result->fetchOne();
		$result->closeCursor();

		return is_string($body) && str_contains($body, '"' . $sequence . '".NEXTVAL INTO :NEW."' . $column->getName() . '"');
	}
}
