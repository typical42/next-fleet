<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\SyncService;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * What sync tells the log, and what a page costs to build. What a page holds is
 * tests/Integration/SyncTest's.
 */
class SyncServiceTest extends TestCase {
	/** How many rows the fake tables handed out, across every findChanged() call. */
	private int $built = 0;

	public function testEachPageIsOneInfoLineNamingWhoAndHowManyRows(): void {
		$logger = new SpyLogger();
		$expenses = $this->createMock(ExpenseMapper::class);
		$expenses->method('findChanged')->willReturn([
			Expense::fromRow(['id' => 1, 'uuid' => 'e-1', 'vehicle_id' => 7, 'updated_at' => 100, 'notes' => 'Parking at the Theater']),
			Expense::fromRow(['id' => 2, 'uuid' => 'e-2', 'vehicle_id' => 7, 'updated_at' => 100, 'deleted_at' => 90]),
		]);

		$this->service(['expenses' => $expenses], $logger)->sync('alice', '', 500);

		// A tombstone is a row handed out, too.
		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'Sync',
			'context' => ['app' => 'nextfleet', 'user' => 'alice', 'vehicle' => 'all reachable', 'rows' => 2],
		]], $logger->lines);
	}

	/**
	 * Nine tables are read a few rows at a time and merged, not `limit` rows each: a page builds
	 * no more than twice its rows, whether one table holds every change or all share them.
	 */
	public function testAPageBuildsAtMostTwiceItsRows(): void {
		$crowded = $this->service(['expenses' => $this->table(Expense::class, ExpenseMapper::class, range(1, 1500))]);
		$page = $crowded->sync('alice', '', 500);
		$this->assertTrue($page['more']);
		$this->assertSame(range(1, 500), array_column($page['changes']['expenses'], 'updated_at'));
		$this->assertLessThanOrEqual(1000, $this->built);

		$this->built = 0;
		$shared = $this->service([
			'readings' => $this->table(OdoReading::class, OdoReadingMapper::class, range(1, 1500, 3)),
			'trips' => $this->table(Trip::class, TripMapper::class, range(2, 1500, 3)),
			'expenses' => $this->table(Expense::class, ExpenseMapper::class, range(3, 1500, 3)),
		]);
		$page = $shared->sync('alice', '', 500);
		$this->assertTrue($page['more']);
		$this->assertSame(range(1, 500), self::sorted($page['changes']));
		$this->assertLessThanOrEqual(1000, $this->built);
	}

	/**
	 * The service holds the bound itself, whichever door asks (docs/api.md#sync): 1 to 2 000 rows,
	 * the edges included.
	 */
	public function testAPageIsOneToTwoThousandRows(): void {
		$service = $this->service(['expenses' => $this->table(Expense::class, ExpenseMapper::class, range(1, 3))]);

		$this->assertCount(1, $service->sync('alice', '', 1)['changes']['expenses']);
		$this->assertCount(3, $service->sync('alice', '', 2000)['changes']['expenses']);
		foreach ([0, 2001] as $limit) {
			try {
				$service->sync('alice', '', $limit);
				$this->fail('a page of ' . $limit . ' rows was answered');
			} catch (\InvalidArgumentException $e) {
				$this->assertSame('limit is a whole number from 1 to 2000', $e->getMessage());
			}
		}
	}

	/**
	 * @param array<string, list<array{updated_at: int, ...}>> $changes
	 * @return list<int>
	 */
	private static function sorted(array $changes): array {
		$at = array_column(array_merge(...array_values($changes)), 'updated_at');
		sort($at);

		return $at;
	}

	/**
	 * A table whose rows changed at the given instants, each row's id its instant, answering
	 * findChanged() as the real one does: past the cursor, oldest first, `limit` at most.
	 *
	 * @template M of object
	 * @param class-string<BaseEntity> $entity
	 * @param class-string<M> $mapper
	 * @param list<int> $instants
	 * @return M&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function table(string $entity, string $mapper, array $instants): object {
		$rows = array_map(static fn (int $at): array => ['id' => $at, 'uuid' => 'r-' . $at, 'vehicle_id' => 7, 'updated_at' => $at], $instants);
		$mock = $this->createMock($mapper);
		$mock->method('findChanged')->willReturnCallback(function (array $whole, array $held, int $since, int $afterAt, ?int $afterId, int $limit) use ($entity, $rows): array {
			$past = array_filter($rows, static fn (array $row): bool => $row['updated_at'] > $afterAt
				|| ($afterId !== null && $row['updated_at'] === $afterAt && $row['id'] > $afterId));
			$page = array_map(static fn (array $row): BaseEntity => $entity::fromRow($row), array_slice(array_values($past), 0, $limit));
			$this->built += count($page);

			return $page;
		});

		return $mock;
	}

	/** @param array<string, object> $tables mocks by SyncCursor::TABLES; the rest are empty */
	private function service(array $tables, ?LoggerInterface $logger = null): SyncService {
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('list')->willReturn([Vehicle::fromRow(['id' => 7, 'uuid' => '0195e2f1-0000-4000-8000-000000000001', 'user_id' => 'alice', 'plate' => 'B-XY 123'])]);

		return new SyncService(
			$fleet,
			$tables['readings'] ?? $this->createMock(OdoReadingMapper::class),
			$tables['trips'] ?? $this->createMock(TripMapper::class),
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$tables['expenses'] ?? $this->createMock(ExpenseMapper::class),
			$this->createMock(ReminderMapper::class),
			$this->createMock(DocumentMapper::class),
			$this->createMock(BookingMapper::class),
			$this->createMock(AccessMapper::class),
			$this->createMock(ReminderService::class),
			$this->createMock(DocumentService::class),
			$this->createMock(BookingService::class),
			$this->createMock(GrantService::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ITimeFactory::class),
			$logger ?? new SpyLogger(),
		);
	}
}
