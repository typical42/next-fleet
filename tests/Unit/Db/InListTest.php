<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\InList;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceipt;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oracle refuses an `IN` of more than 1 000 items (ORA-01795). Every mapper method that takes a
 * list is called with 2 500 items against a query builder that records each list bound to it.
 */
class InListTest extends TestCase {
	private const MANY = 2500;

	/** @var list<list<int|string>> every list bound to any query, in order */
	private array $lists = [];
	/** What each query finds, one queue of rows per executeQuery(), in order. */
	private array $results = [];

	/**
	 * Each call answers the lists it must bind in full; a closure keeps 2 500 ids out of the
	 * data set's name.
	 *
	 * @return array<string, array{\Closure(self): list<list<int|string>>}>
	 */
	public static function calls(): array {
		$ids = range(1, self::MANY);
		$others = range(self::MANY + 1, 2 * self::MANY);
		$groups = array_map(static fn (int $i): string => "group-$i", $ids);

		return [
			'ReminderMapper::findByVehicles' => [static function (self $t) use ($ids): array {
				$t->mapper(ReminderMapper::class)->findByVehicles($ids);
				return [$ids];
			}],
			'VehicleMapper::findAllVisible' => [static function (self $t) use ($ids): array {
				$t->mapper(VehicleMapper::class)->findAllVisible('alice', $ids);
				return [$ids];
			}],
			'DocumentMapper::findAttachedFileIds' => [static function (self $t) use ($ids): array {
				$t->mapper(DocumentMapper::class)->findAttachedFileIds('alice', $ids);
				return [$ids];
			}],
			'BaseMapper::findChanged' => [static function (self $t) use ($ids, $others): array {
				$t->mapper(TripMapper::class)->findChanged($ids, $others, 0, 0, null, 100);
				return [$ids, $others];
			}],
			'BaseMapper::findAnyByIds' => [static function (self $t) use ($ids): array {
				$t->mapper(TripMapper::class)->findAnyByIds(1, $ids);
				return [$ids];
			}],
			'BaseMapper::countLive' => [static function (self $t) use ($groups): array {
				$t->mapper(TripMapper::class)->countLive(1, $groups);
				return [$groups];
			}],
			'OdoReadingMapper::findMainWithin' => [static function (self $t) use ($ids): array {
				$t->mapper(OdoReadingMapper::class)->findMainWithin($ids, 0, 1);
				return [$ids];
			}],
			'OdoReadingMapper::findForSources' => [static function (self $t) use ($ids): array {
				$t->mapper(OdoReadingMapper::class)->findForSources(1, OdoReading::TRIP, $ids);
				return [$ids];
			}],
			'OdoReadingMapper::nameSources' => [static function (self $t) use ($ids): array {
				$t->mapper(OdoReadingMapper::class)->nameSources(array_map(
					static fn (int $i): OdoReading => OdoReading::fromRow(['id' => $i, 'source_type' => OdoReading::TRIP, 'source_id' => $i]),
					$ids,
				));
				return [$ids];
			}],
			'BookingMapper::findCountersAtOut' => [static function (self $t) use ($ids, $others): array {
				// The first query finds each booking's instants; the keyed reads then name them.
				$t->results = [array_map(static fn (int $i): array => ['id' => $i, 'vehicle_id' => $others[$i - 1], 'read_at' => $i, 'in_at' => $i], $ids)];
				$t->mapper(BookingMapper::class)->findCountersAtOut(array_map(
					static fn (int $i): Booking => Booking::fromRow(['id' => $i, 'out_at' => 1000]),
					$ids,
				));
				return [$ids, $others];
			}],
			'BookingMapper::findPooled' => [static function (self $t) use ($ids): array {
				$t->mapper(BookingMapper::class)->findPooled($ids, 'alice', 0, 1);
				return [$ids];
			}],
			'AuditMapper::findForEntities' => [static function (self $t) use ($ids): array {
				$t->mapper(AuditMapper::class)->findForEntities('trip', $ids);
				return [$ids];
			}],
			'AccessMapper::findReachable' => [static function (self $t) use ($groups): array {
				$t->mapper(AccessMapper::class)->findReachable('alice', $groups, ['viewer']);
				return [$groups];
			}],
			'ReminderReceiptMapper::forget' => [static function (self $t) use ($ids): array {
				$t->mapper(ReminderReceiptMapper::class)->forget(array_map(static fn (int $i): ReminderReceipt => ReminderReceipt::fromRow(['id' => $i]), $ids));
				return [$ids];
			}],
			// No mapper takes a list for NOT IN yet.
			'InList::notIn' => [static function (self $t) use ($ids): array {
				InList::notIn($t->queryBuilder(), 'id', $ids, IQueryBuilder::PARAM_INT_ARRAY);
				return [$ids];
			}],
		];
	}

	/**
	 * The calls above are the list-taking methods of today; this keeps a new one from building its
	 * own IN.
	 */
	public function testNoCodeBuildsAnInListButTheHelper(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$offenders = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = $file->getPathname();
			if (str_ends_with($path, '.php') && $path !== "$lib/Db/InList.php"
				&& preg_match('/->(in|notIn)\(/', file_get_contents($path)) === 1) {
				$offenders[] = substr($path, strlen($lib) + 1);
			}
		}

		$this->assertSame([], $offenders);
	}

	/**
	 * No list longer than 1 000 reaches a query, and none of the items is dropped on the way.
	 *
	 * @param \Closure(self): list<list<int|string>> $call
	 */
	#[DataProvider('calls')]
	public function testNoListBoundToAQueryIsLongerThanOracleTakes(\Closure $call): void {
		$wanted = $call($this);

		$this->assertNotSame([], $this->lists, 'the call bound no list at all');
		foreach ($this->lists as $list) {
			$this->assertLessThanOrEqual(1000, count($list));
		}
		$bound = array_merge(...$this->lists);
		foreach ($wanted as $list) {
			$this->assertSame([], array_values(array_diff($list, $bound)), 'items never reached the query');
		}
	}

	/**
	 * @template T
	 * @param class-string<T> $class
	 * @return T
	 */
	private function mapper(string $class): object {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());

		return new $class($db, $this->createMock(ITimeFactory::class), $this->createMock(ISecureRandom::class));
	}

	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$fluent = ['select', 'selectAlias', 'selectDistinct', 'addSelect', 'delete', 'update', 'insert', 'from',
			'join', 'innerJoin', 'leftJoin', 'rightJoin', 'set', 'setValue', 'where', 'andWhere', 'orWhere',
			'groupBy', 'addGroupBy', 'having', 'andHaving', 'orHaving', 'orderBy', 'addOrderBy',
			'setMaxResults', 'setFirstResult'];
		foreach ($fluent as $method) {
			$qb->method($method)->willReturnSelf();
		}
		// No native return types here, so an unconfigured stub would answer null.
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('getSQL')->willReturn('');
		$record = function ($value): string {
			if (is_array($value)) {
				$this->lists[] = $value;
			}
			return ':p' . count($this->lists);
		};
		$qb->method('createNamedParameter')->willReturnCallback($record);
		$qb->method('createPositionalParameter')->willReturnCallback($record);
		$qb->method('setParameter')->willReturnCallback(function ($key, $value) use ($qb, $record) {
			$record($value);
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () {
			$rows = array_shift($this->results) ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			return $result;
		});

		return $qb;
	}
}
