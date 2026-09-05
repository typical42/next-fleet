<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Tests\Unit\Db\Fixture\Thing;
use OCA\NextFleet\Tests\Unit\Db\Fixture\ThingMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The mapper is exercised against a recording query builder rather than a database: what it
 * writes, what it demands of the row it writes to, and what it does when the row refuses.
 */
class BaseMapperTest extends TestCase {
	private const NOW = 1750000000;
	/** RFC 4122, version 4. */
	private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/** Column => the placeholder the mapper bound to it. */
	private array $written = [];
	/** Placeholder => the value behind it. */
	private array $bound = [];
	/** The WHERE predicates, in the order they were added. */
	private array $predicates = [];
	/** What the database reports back from the statement. */
	private int $affectedRows = 1;
	/** What a query finds. */
	private array $rows = [];

	/**
	 * Every column the mapper wrote, with the values it bound - the row as the database
	 * would have seen it.
	 */
	private function row(): array {
		return array_map(fn (string $placeholder) => $this->bound[$placeholder], $this->written);
	}

	/**
	 * The WHERE predicates with their bound values substituted, as the row the statement
	 * demands to find.
	 */
	private function conditions(): array {
		$values = array_map(static fn ($value) => (string)$value, $this->bound);

		return array_map(static fn (string $predicate) => strtr($predicate, $values), $this->predicates);
	}

	/** An entity as a find() would hand it over: clean, with the row's own updated_at. */
	private function stored(int $updatedAt): Thing {
		return Thing::fromRow([
			'id' => 7,
			'uuid' => '0195e2f1-0000-4000-8000-000000000001',
			'label' => 'a trailer',
			'created_at' => $updatedAt,
			'updated_at' => $updatedAt,
		]);
	}

	private function mapper(int $now = self::NOW, string $hex = 'ffffffffffffffffffffffffffffffff'): ThingMapper {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn($hex);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->queryBuilder());

		return new ThingMapper($db, $time, $random);
	}

	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($x, $y) => "$x = $y");
		$expr->method('isNull')->willReturnCallback(fn ($x) => "$x IS NULL");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['insert', 'update', 'select', 'from'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		// Rows are never removed - a soft delete that reached DELETE would still look right
		// in every other assertion here.
		$qb->expects($this->never())->method('delete');
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$placeholder = ':p' . count($this->bound);
			$this->bound[$placeholder] = $value;
			return $placeholder;
		});
		$qb->method('setValue')->willReturnCallback(function (string $column, $placeholder) use ($qb) {
			$this->written[$column] = $placeholder;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, $placeholder) use ($qb) {
			$this->written[$column] = $placeholder;
			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$predicates) use ($qb) {
				array_push($this->predicates, ...$predicates);
				return $qb;
			});
		}
		$qb->method('executeStatement')->willReturnCallback(fn () => $this->affectedRows);

		$rows = $this->rows;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	/**
	 * A row gets an identity and the server's own dating. A created_at that arrived with the
	 * entity does not survive: the timestamp is the server's, never the client's.
	 */
	public function testInsertStampsIdentityAndTheServersClock(): void {
		$thing = new Thing();
		$thing->setLabel('a trailer');
		$thing->setCreatedBy('alice');
		$thing->setCreatedAt(1);

		$this->mapper()->insert($thing);

		$this->assertMatchesRegularExpression(self::UUID_V4, $thing->getUuid());
		$this->assertSame(self::NOW, $thing->getCreatedAt());
		$this->assertSame(self::NOW, $thing->getUpdatedAt());
		$this->assertSame([
			'label' => 'a trailer',
			'created_by' => 'alice',
			'created_at' => self::NOW,
			'uuid' => $thing->getUuid(),
			'updated_at' => self::NOW,
		], $this->row());
	}

	/**
	 * An identity the row already carries is kept - an import brings its own, and offline sync
	 * will. The mapper only fills the gap; whether a request may carry one is the controller's
	 * business.
	 */
	public function testInsertKeepsAnIdentityThatCameWithTheRow(): void {
		$thing = new Thing();
		$thing->setCreatedBy('alice');
		$thing->setUuid('0195e2f1-0000-4000-8000-000000000001');

		$this->mapper()->insert($thing);

		$this->assertSame('0195e2f1-0000-4000-8000-000000000001', $thing->getUuid());
	}

	/**
	 * created_by is the one common column the mapper cannot invent - it has no session, and a
	 * row written without it loses the provenance an audit and an erasure both go looking for.
	 */
	public function testInsertDemandsProvenance(): void {
		$thing = new Thing();
		$thing->setLabel('a trailer');

		$this->expectException(\InvalidArgumentException::class);

		$this->mapper()->insert($thing);
	}

	/**
	 * The update is the concurrency check: one statement that writes the changed columns and
	 * a fresh token, and only to the row the client read.
	 */
	public function testUpdateWritesToTheRowTheClientRead(): void {
		$thing = $this->stored(self::NOW - 60);
		$thing->setLabel('a tractor');

		$this->mapper()->updateChecked($thing, self::NOW - 60);

		$this->assertSame(['label' => 'a tractor', 'updated_at' => self::NOW], $this->row());
		$this->assertSame([
			'id = 7',
			'updated_at = ' . (self::NOW - 60),
			'deleted_at IS NULL',
		], $this->conditions());
		$this->assertSame(self::NOW, $thing->getUpdatedAt());
	}

	/**
	 * Nothing matched, so the row has moved on since the client read it - the other tab wins
	 * and this write is refused rather than silently overwriting it.
	 */
	public function testUpdateRefusesAWriteTheRowNoLongerMatches(): void {
		$this->affectedRows = 0;
		$thing = $this->stored(self::NOW - 60);
		$thing->setLabel('a tractor');

		$this->expectException(StaleUpdateException::class);

		$this->mapper()->updateChecked($thing, self::NOW - 3600);
	}

	/**
	 * Two writes inside one second must not leave the same token behind, or the second one
	 * passes the check of a tab that read between them.
	 */
	public function testUpdateAlwaysMovesTheToken(): void {
		$thing = $this->stored(self::NOW);
		$thing->setLabel('a tractor');

		$this->mapper()->updateChecked($thing, self::NOW);

		$this->assertSame(self::NOW + 1, $thing->getUpdatedAt());
	}

	/**
	 * The statement carries the token even when the caller changed nothing and the bump lands
	 * on the value the entity already holds. An UPDATE with an empty SET is a 500 where the
	 * client was owed a 412.
	 */
	public function testUpdateAlwaysWritesTheToken(): void {
		$thing = $this->stored(self::NOW);

		$this->mapper()->updateChecked($thing, self::NOW - 1);

		$this->assertSame(['updated_at' => self::NOW], $this->row());
	}

	/**
	 * The uuid is the identity and created_at/created_by are the server's record of where the
	 * row came from. An update moves none of them, however the entity reached the mapper.
	 */
	public function testUpdateLeavesIdentityAndProvenanceAlone(): void {
		$thing = $this->stored(self::NOW - 60);
		$thing->setLabel('a tractor');
		$thing->setUuid('0195e2f1-0000-4000-8000-00000000dead');
		$thing->setCreatedAt(1);
		$thing->setCreatedBy('mallory');

		$this->mapper()->updateChecked($thing, self::NOW - 60);

		$this->assertSame(['label' => 'a tractor', 'updated_at' => self::NOW], $this->row());
	}

	/**
	 * Deleting stamps the row instead of removing it, so a trash and a GDPR erasure have
	 * something to work on - and it takes the same token as any other write.
	 */
	public function testSoftDeleteStampsTheRowAndTakesTheToken(): void {
		$thing = $this->stored(self::NOW - 60);

		$this->mapper()->softDelete($thing, self::NOW - 60);

		$this->assertSame(['deleted_at' => self::NOW, 'updated_at' => self::NOW], $this->row());
		$this->assertSame([
			'id = 7',
			'updated_at = ' . (self::NOW - 60),
			'deleted_at IS NULL',
		], $this->conditions());
	}

	/**
	 * QBMapper's own update writes on `id` alone and never moves the token, and its delete
	 * removes the row - either one silently undoes what this class is for, so both are shut.
	 *
	 * @dataProvider unguardedWrites
	 */
	public function testTheUnguardedInheritedWritesAreRefused(string $method): void {
		$this->expectException(\BadMethodCallException::class);

		$this->mapper()->$method($this->stored(self::NOW - 60));
	}

	public static function unguardedWrites(): array {
		return [['update'], ['delete'], ['insertOrUpdate']];
	}

	/**
	 * The uuid is the identity, and a soft-deleted row is gone as far as a read is concerned.
	 */
	public function testFindByUuidPassesOverDeletedRows(): void {
		$this->rows = [[
			'id' => 7,
			'uuid' => '0195e2f1-0000-4000-8000-000000000001',
			'label' => 'a trailer',
		]];

		$thing = $this->mapper()->findByUuid('0195e2f1-0000-4000-8000-000000000001');

		$this->assertSame('a trailer', $thing->getLabel());
		$this->assertSame([
			'uuid = 0195e2f1-0000-4000-8000-000000000001',
			'deleted_at IS NULL',
		], $this->conditions());
	}
}
