<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Migration\Version000001Date20260101000000;
use OCA\NextFleet\Tests\Fixture\SchemaWrapper;
use OCA\NextFleet\Tests\SchemaExpectations;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The first migration run against a real Doctrine schema instead of a server: what it declares.
 * tests/Integration/SchemaTest.php runs the same expectations against what MariaDB then makes
 * of it.
 */
class SchemaTest extends TestCase {
	use SchemaExpectations;

	private ?SchemaWrapper $migrated = null;

	private function migrate(): SchemaWrapper {
		if ($this->migrated === null) {
			$schema = new SchemaWrapper();
			$returned = (new Version000001Date20260101000000())->changeSchema(
				$this->createMock(IOutput::class),
				static fn (): ISchemaWrapper => $schema,
				[],
			);

			// A migration that keeps its schema to itself changes nothing: MigrationService
			// applies what comes back, and null means "no schema change".
			$this->assertSame($schema, $returned);

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
}
