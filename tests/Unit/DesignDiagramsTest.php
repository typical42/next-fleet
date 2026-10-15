<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Type;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Fixture\SchemaWrapper;
use OCA\NextFleet\Tests\MigrationSteps;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The design diagrams name the code (docs/design/README.md). A class renamed or removed leaves a
 * diagram drawing something that no longer exists, and nothing renders that as an error.
 */
class DesignDiagramsTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** A Mermaid class relation: `A "1" --> "*" B : label`, any arrow, multiplicities optional. */
	private const RELATION = '/^\s*(\w+)\s+(?:"[^"]*"\s+)?(?:<\||[<*o])?(?:--|\.\.)(?:\|>|[>*o])?\s+(?:"[^"]*"\s+)?(\w+)/';

	/** The columns every table carries (BaseEntity), which data-model.md states once in prose. */
	private const COMMON = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by'];

	/**
	 * Per class whose constants lifecycles.md names: the list in the code that holds every state,
	 * and the class's other string constants, so a new one is a state until it is named here.
	 */
	private const STATE_LISTS = [
		Booking::class => [Booking::class, 'STATES', []],
		Reminder::class => [Reminder::class, 'STATES', ['DATE', 'ODO', 'EITHER']],
		Vehicle::class => [VehicleService::class, 'LIFECYCLES', ['MAIL_OFF', 'MAIL_DAILY', 'MAIL_WEEKLY', 'MAIL_MONTHLY']],
	];

	public function testEveryClassOfTheDomainIsAClassUnderLib(): void {
		$drawn = [];
		foreach ($this->blocks('docs/design/domain.md', 'classDiagram') as $block) {
			foreach (explode("\n", $block) as $line) {
				if (preg_match('/^\s*class\s+(\w+)/', $line, $match) === 1) {
					$drawn[] = $match[1];
				} elseif (preg_match(self::RELATION, $line, $match) === 1) {
					$drawn[] = $match[1];
					$drawn[] = $match[2];
				}
			}
		}
		$drawn = array_values(array_unique($drawn));
		$this->assertNotEmpty($drawn);

		$this->assertSame([], array_values(array_diff($drawn, $this->declaredUnderLib())));
	}

	/**
	 * Against the schema the migrations declare, which tests/SchemaExpectations.php holds to the
	 * data model: a column a migration adds fails here until the diagram draws it.
	 */
	public function testTheDataModelDrawsEveryTableAndColumnOfTheSchema(): void {
		$drawn = [];
		foreach ($this->blocks('docs/design/data-model.md', 'erDiagram') as $block) {
			$table = null;
			foreach (explode("\n", $block) as $line) {
				if (preg_match('/^\s*(\w+)\s*\{\s*$/', $line, $match) === 1) {
					$table = $match[1];
					$drawn[$table] ??= [];
				} elseif (preg_match('/^\s*\}\s*$/', $line) === 1) {
					$table = null;
				} elseif ($table !== null && preg_match('/^\s*([\w()]+)\s+(\w+)/', $line, $match) === 1) {
					$drawn[$table][$match[2]] = $match[1];
				} elseif (preg_match('/^\s*(\w+)\s+\S+\s+(\w+)\s*:/', $line, $match) === 1) {
					$drawn[$match[1]] ??= [];
					$drawn[$match[2]] ??= [];
				}
			}
		}
		$drawn = self::sorted($drawn);

		$schema = $this->migrated();
		$declared = [];
		foreach ($schema->getTableNamesWithoutPrefix() as $name) {
			foreach ($schema->getTable($name)->getColumns() as $column) {
				if (in_array($column->getName(), self::COMMON, true)) {
					continue;
				}
				$type = Type::lookupName($column->getType());
				$declared[$name][$column->getName()] = $type === Types::STRING ? $type . '(' . (int)$column->getLength() . ')' : $type;
			}
		}

		$this->assertSame(self::sorted($declared), $drawn);
	}

	/**
	 * A state held in a constant is drawn as `state "value<br/>Class::NAME" as value`. A diagram
	 * that names one names every state so, and across the file a class's states are exactly its
	 * list's values. A diagram naming none draws states no constant holds, and is checked by review.
	 */
	public function testEachLifecycleDrawsExactlyTheStatesItsConstantsHold(): void {
		$byShortName = [];
		foreach (array_keys(self::STATE_LISTS) as $class) {
			$byShortName[(new \ReflectionClass($class))->getShortName()] = $class;
		}

		$wrong = [];
		$drawn = [];
		foreach ($this->blocks('docs/design/lifecycles.md', 'stateDiagram-v2') as $block) {
			$labels = $this->statesOf($block);
			if (preg_grep('/<br\/>\w+::\w+\b/', array_map('strval', $labels)) === []) {
				continue;
			}
			foreach ($labels as $state => $label) {
				$lines = explode('<br/>', (string)$label);
				[$short, $name] = explode('::', (string)current(preg_grep('/^\w+::\w+$/', $lines))) + [1 => ''];
				$class = $byShortName[$short] ?? null;
				if ($lines[0] !== $state || $class === null || !defined($class . '::' . $name) || constant($class . '::' . $name) !== $state) {
					$wrong[] = $state . ' as "' . (string)$label . '"';
					continue;
				}
				$drawn[$class][] = $state;
			}
		}
		$this->assertSame([], $wrong, 'states drawn without the constant that holds them');

		$held = [];
		$declared = [];
		foreach (self::STATE_LISTS as $class => [$owner, $list, $others]) {
			$states = (new \ReflectionClassConstant($owner, $list))->getValue();
			$this->assertIsArray($states);
			$held[$class] = array_values($states);
			sort($held[$class]);
			$declared[$class] = array_values(array_filter(
				array_diff_key((new \ReflectionClass($class))->getConstants(), array_flip($others)),
				'is_string',
			));
			sort($declared[$class]);
			$drawn[$class] = array_values(array_unique($drawn[$class] ?? []));
			sort($drawn[$class]);
		}
		ksort($drawn);
		ksort($held);
		ksort($declared);
		$this->assertSame($declared, $held, 'a string constant that is no state and not named as another');
		$this->assertSame($held, $drawn);
	}

	/** The schema every step declares, on MariaDB. */
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
	 * @param array<string, array<string, string>> $tables
	 * @return array<string, array<string, string>>
	 */
	private static function sorted(array $tables): array {
		ksort($tables);
		foreach ($tables as &$columns) {
			ksort($columns);
		}

		return $tables;
	}

	/** @return list<string> the bodies of the file's Mermaid blocks that open with $kind */
	private function blocks(string $file, string $kind): array {
		$text = (string)file_get_contents(self::ROOT . '/' . $file);
		preg_match_all('/^```mermaid\n(' . $kind . '\b.*?)^```/ms', $text, $matches);
		return $matches[1];
	}

	/**
	 * The states one state diagram draws, each with its quoted label, or null. A composite or a
	 * choice groups states and is none; text inside a note is skipped.
	 *
	 * @return array<string, ?string>
	 */
	private function statesOf(string $block): array {
		$states = [];
		$groups = [];
		$inNote = false;
		foreach (explode("\n", $block) as $line) {
			if ($inNote) {
				$inNote = preg_match('/^\s*end note/', $line) !== 1;
			} elseif (preg_match('/^\s*note\s.*\bof\s+\w+\s*$/', $line) === 1) {
				$inNote = true;
			} elseif (preg_match('/^\s*state\s+(?:"[^"]*"\s+as\s+)?(\w+)\s*(\{|<<\w+>>)/', $line, $match) === 1) {
				$groups[] = $match[1];
			} elseif (preg_match('/^\s*state\s+"([^"]*)"\s+as\s+(\w+)\s*$/', $line, $match) === 1) {
				$states[$match[2]] = $match[1];
			} elseif (preg_match('/^\s*(\[\*\]|\w+)\s*-->\s*(\[\*\]|\w+)/', $line, $match) === 1) {
				$states[$match[1]] ??= null;
				$states[$match[2]] ??= null;
			} elseif (preg_match('/^\s*(\w+)\s*:/', $line, $match) === 1) {
				$states[$match[1]] ??= null;
			}
		}
		unset($states['[*]']);

		return array_diff_key($states, array_flip($groups));
	}

	/** @return list<string> the short names of every class, interface and enum in lib/ */
	private function declaredUnderLib(): array {
		$names = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/lib', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|enum)\s+(\w+)/m', (string)file_get_contents((string)$file), $matches);
			array_push($names, ...$matches[1]);
		}
		return $names;
	}
}
