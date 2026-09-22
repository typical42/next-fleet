<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\ReminderRecipientMapper;
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
			$schema = new SchemaWrapper();
			$steps = Steps::inOrder($this->createMock(IDBConnection::class), $this->createMock(ReminderRecipientMapper::class));
			foreach ($steps as $step) {
				$returned = $step->changeSchema(
					$this->createMock(IOutput::class),
					static fn (): ISchemaWrapper => $schema,
					[],
				);

				// A migration that keeps its schema to itself changes nothing: MigrationService
				// applies what comes back, and null means "no schema change".
				$this->assertSame($schema, $returned);
			}

			$this->migrated = $schema;
		}

		return $this->migrated;
	}

	protected function fleetTableNames(): array {
		return array_values($this->migrate()->getTableNamesWithoutPrefix());
	}

	protected function table(string $name): Table {
		return $this->migrate()->getTable($name);
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
