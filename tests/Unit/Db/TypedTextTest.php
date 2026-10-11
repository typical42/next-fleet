<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Type;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\TypedText;
use OCA\NextFleet\Service\Field;
use OCA\NextFleet\Tests\Fixture\SchemaWrapper;
use OCA\NextFleet\Tests\MigrationSteps;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Every text a service bounds in characters and stores in a string column is in TypedText, so
 * that column is 4n on Oracle (docs/architecture.md#data-model). Read off each service's WRITABLE
 * and its `Field::read(..., 'text', ...)` calls, so a field added without joining the set fails
 * here before an Oracle install truncates it.
 */
class TypedTextTest extends TestCase {
	/** The table each service stores its fields in. */
	private const TABLES = [
		'BookingService' => 'fleet_bookings',
		'EnergyService' => 'fleet_energy',
		'ExpenseService' => 'fleet_expenses',
		'MaintenanceService' => 'fleet_maintenance',
		'ReminderService' => 'fleet_reminders',
		'TripService' => 'fleet_trips',
		'VehicleService' => 'fleet_vehicles',
	];

	/** Text columns n bytes hold on Oracle too, each with why only ASCII reaches it. */
	private const ASCII = [
		'fleet_reminders' => [
			'template_key' => 'one of the app\'s own template keys; ReminderService refuses any other',
		],
	];

	/**
	 * Service => field => its bound in characters, for every field read as text.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function textFields(): array {
		$fields = [];
		foreach (glob(__DIR__ . '/../../../lib/Service/*.php') ?: [] as $file) {
			$service = basename($file, '.php');
			$writable = (new \ReflectionClass('OCA\\NextFleet\\Service\\' . $service))->getConstants()['WRITABLE'] ?? [];
			foreach (is_array($writable) ? $writable : [] as $field => $spec) {
				if (is_array($spec) && $spec[1] === 'text' && is_int($spec[2])) {
					$fields[$service][(string)$field] = $spec[2];
				}
			}

			preg_match_all("/Field::read\\('(\\w+)', 'text', ([\\w:]+),/", (string)file_get_contents($file), $reads, PREG_SET_ORDER);
			foreach ($reads as [, $field, $bound]) {
				$fields[$service][$field] = is_numeric($bound) ? (int)$bound : (int)constant(Field::class . '::' . substr($bound, strlen('Field::')));
			}
		}

		return $fields;
	}

	/**
	 * Table => column => the bound of the text stored there, for each text whose column is a
	 * string. A text with no column of its name is not stored under it: a uuid that looks up a
	 * row, or a handover's notes, which land in a TEXT column.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function textColumns(): array {
		$schema = $this->migrated();
		$columns = [];
		foreach (self::textFields() as $service => $fields) {
			$this->assertArrayHasKey($service, self::TABLES, $service . ' reads text but its table is not named here');
			$table = $schema->getTable(self::TABLES[$service]);
			foreach ($fields as $field => $bound) {
				if ($table->hasColumn($field) && Type::lookupName($table->getColumn($field)->getType()) === Types::STRING) {
					$columns[self::TABLES[$service]][$field] = $bound;
				}
			}
		}

		return self::sorted($columns);
	}

	/** The schema every step declares, on a database that counts characters. */
	private function migrated(): SchemaWrapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);

		$schema = new SchemaWrapper(platform: new MariaDBPlatform());
		foreach (MigrationSteps::inOrder($db, $this->createMock(ReminderRecipientMapper::class)) as $step) {
			$step->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
		}

		return $schema;
	}

	/**
	 * @param array<string, array<string, mixed>> $columns
	 * @return array<string, array<string, mixed>>
	 */
	private static function sorted(array $columns): array {
		ksort($columns);
		foreach ($columns as &$inTable) {
			ksort($inTable);
		}

		return $columns;
	}

	/** The services this check reaches: a rename that hid them would leave it testing nothing. */
	public function testItReadsEveryServiceThatTakesText(): void {
		$services = array_keys(self::textFields());
		sort($services);
		$this->assertSame(array_keys(self::TABLES), $services);
	}

	public function testHoldsEveryTextColumnButTheAsciiOnes(): void {
		$expected = $this->textColumns();
		foreach (self::ASCII as $table => $columns) {
			$expected[$table] = array_diff_key($expected[$table] ?? [], $columns);
		}

		$this->assertSame(array_filter($expected), self::sorted(TypedText::COLUMNS));
	}

	/** An exception that is no longer a text column would excuse the next one of its name. */
	public function testEachAsciiExceptionIsATextColumn(): void {
		$columns = $this->textColumns();
		foreach (self::ASCII as $table => $exceptions) {
			foreach (array_keys($exceptions) as $column) {
				$this->assertArrayHasKey($column, $columns[$table] ?? [], $table . '.' . $column);
			}
		}
	}
}
