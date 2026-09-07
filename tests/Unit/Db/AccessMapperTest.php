<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\AccessMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The grant lookup against a recording query builder rather than a database: which statement it
 * builds, and when it builds none at all.
 */
class AccessMapperTest extends TestCase {
	/** The WHERE predicates, in the order they were added. */
	private array $predicates = [];
	/** What a query finds. */
	private array $rows = [];
	/** How often a statement reached the database. */
	private int $queries = 0;

	private function mapper(): AccessMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->queryBuilder());

		return new AccessMapper($db, $this->createMock(ITimeFactory::class), $this->createMock(ISecureRandom::class));
	}

	/**
	 * What andX()/orX() hand back. Doctrine's own composite renders itself as SQL, and the
	 * assertions read that way too - only the interface forbids saying so in a type.
	 *
	 * @param list<string|ICompositeExpression> $parts
	 */
	private function composite(string $type, array $parts): ICompositeExpression {
		return new class($type, $parts) implements ICompositeExpression {
			public function __construct(
				private string $type,
				private array $parts,
			) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				$this->parts = array_merge($this->parts, $parts);
				return $this;
			}

			public function add($part): ICompositeExpression {
				$this->parts[] = $part;
				return $this;
			}

			public function count(): int {
				return count($this->parts);
			}

			public function getType(): string {
				return $this->type;
			}

			public function __toString(): string {
				return '(' . implode(" $this->type ", array_map(strval(...), $this->parts)) . ')';
			}
		};
	}

	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y) => "$x = $y");
		$expr->method('in')->willReturnCallback(static fn ($x, $y) => "$x IN $y");
		$expr->method('isNull')->willReturnCallback(static fn ($x) => "$x IS NULL");
		$expr->method('andX')->willReturnCallback(fn (...$parts) => $this->composite('AND', $parts));
		$expr->method('orX')->willReturnCallback(fn (...$parts) => $this->composite('OR', $parts));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['selectDistinct', 'from'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		// The placeholder is the value itself, so a predicate reads as the row it demands.
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value) => is_array($value) ? '(' . implode(', ', $value) . ')' : (string)$value,
		);
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$predicates) use ($qb) {
				array_push($this->predicates, ...$predicates);
				return $qb;
			});
		}

		$rows = $this->rows;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($result) {
			$this->queries++;
			return $result;
		});

		return $qb;
	}

	/**
	 * No role covers the operation, so no grant can. Asking anyway would build `role IN ()`, which
	 * none of the three databases parses - the same reason grantedTo() drops an empty group list.
	 */
	public function testAnEmptyRoleListReachesNoVehicleAndNoDatabase(): void {
		$this->rows = [['vehicle_id' => 7]];

		$ids = $this->mapper()->findVehicleIds('alice', ['staff'], []);

		$this->assertSame([], $ids);
		$this->assertSame(0, $this->queries);
	}

	/**
	 * The ordinary case still asks: the user by name or one of their groups, in one of the roles,
	 * and not a grant that was withdrawn.
	 */
	public function testGrantsAreFoundForTheUserTheirGroupsAndTheGivenRoles(): void {
		$this->rows = [['vehicle_id' => '7'], ['vehicle_id' => '9']];

		$ids = $this->mapper()->findVehicleIds('alice', ['staff'], ['manager', 'viewer']);

		$this->assertSame([7, 9], $ids);
		$this->assertSame([
			'((grantee_type = user AND grantee = alice) OR (grantee_type = group AND grantee IN (staff)))',
			'role IN (manager, viewer)',
			'deleted_at IS NULL',
		], array_map(strval(...), $this->predicates));
	}
}
