<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Migration\Version000009Date20261011000000;
use OCA\NextFleet\Tests\Fixture\SchemaWrapper;
use OCA\NextFleet\Tests\MigrationSteps as Steps;
use OCA\NextFleet\Tests\SchemaExpectations;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The migrations run against a real Doctrine schema instead of a server: what they declare.
 * tests/Integration/SchemaTest.php runs the same expectations against what MariaDB then makes
 * of them.
 */
class SchemaTest extends TestCase {
	use SchemaExpectations;

	private ?SchemaWrapper $migrated = null;

	private function migrate(): SchemaWrapper {
		if ($this->migrated === null) {
			$this->migrated = new SchemaWrapper(platform: $this->platform());
			$this->runSteps($this->migrated);
		}

		return $this->migrated;
	}

	/** The platform the steps see, through the schema and through the connection. */
	protected function platform(): AbstractPlatform {
		return new MariaDBPlatform();
	}

	protected function database(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);

		return $db;
	}

	private function runSteps(SchemaWrapper $schema): void {
		$steps = Steps::inOrder($this->database(), $this->createMock(ReminderRecipientMapper::class));
		foreach ($steps as $step) {
			$asked = false;
			$returned = $step->changeSchema(
				$this->createMock(IOutput::class),
				static function () use ($schema, &$asked): ISchemaWrapper {
					$asked = true;
					return $schema;
				},
				[],
			);

			// A migration that keeps its schema to itself changes nothing: MigrationService
			// applies what comes back, and null means "no schema change". Only a step that never
			// asked for the schema can have nothing to hand back.
			$this->assertSame($asked ? $schema : null, $returned);
		}
	}

	/**
	 * Asking for the schema reads the whole database, and handing it back diffs it twice more,
	 * so the step that widens columns for Oracle does neither anywhere else.
	 */
	public function testTheWideningReadsTheSchemaOnlyWhereColumnsCountBytes(): void {
		$asked = false;
		(new Version000009Date20261011000000($this->database()))->changeSchema(
			$this->createMock(IOutput::class),
			function () use (&$asked): ISchemaWrapper {
				$asked = true;
				return $this->migrate();
			},
			[],
		);

		$this->assertSame($this->countsBytes(), $asked);
	}

	/** @return array<string, array{array<string, string>, array<string, string>}> table => its columns and indexes */
	private function everything(): array {
		$all = [];
		foreach ($this->fleetTableNames() as $name) {
			$all[$name] = [$this->columns($name), $this->indexes($name)];
		}

		return $all;
	}

	protected function fleetTableNames(): array {
		return array_values($this->migrate()->getTableNamesWithoutPrefix());
	}

	protected function table(string $name): Table {
		return $this->migrate()->getTable($name);
	}

	/**
	 * A re-install meets its own leftover columns and indexes, and Doctrine refuses to add one
	 * twice. Every step guards for that, so running them all again over what they built changes
	 * nothing.
	 */
	public function testEveryStepRunsAgainOverWhatItBuilt(): void {
		$before = $this->everything();

		$this->runSteps($this->migrate());

		$this->assertSame($before, $this->everything());
	}

	/**
	 * A primary key left unnamed takes its name from the table on PostgreSQL and Oracle, so
	 * Nextcloud refuses the app over a table name of 23 characters or more. Only the declared
	 * schema knows the name: MariaDB calls every key PRIMARY.
	 */
	public function testALongTableNamesItsPrimaryKey(): void {
		foreach ($this->fleetTableNames() as $name) {
			$primary = $this->table($name)->getPrimaryKey();
			$this->assertNotNull($primary);

			if (strlen($name) >= 23) {
				$this->assertNotSame('primary', strtolower($primary->getName()), $name . ' needs a named primary key');
				$this->assertLessThanOrEqual(30, strlen($primary->getName()));
			}
		}
	}
}
