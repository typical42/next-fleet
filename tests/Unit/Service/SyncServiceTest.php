<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
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
use Psr\Log\LogLevel;

/**
 * What sync tells the log. What a page holds is tests/Integration/SyncTest's.
 */
class SyncServiceTest extends TestCase {
	public function testEachPageIsOneInfoLineNamingWhoAndHowManyRows(): void {
		$logger = new SpyLogger();
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('list')->willReturn([Vehicle::fromRow(['id' => 7, 'uuid' => '0195e2f1-0000-4000-8000-000000000001', 'user_id' => 'alice', 'plate' => 'B-XY 123'])]);
		$expenses = $this->createMock(ExpenseMapper::class);
		$expenses->method('findChanged')->willReturn([
			Expense::fromRow(['id' => 1, 'uuid' => 'e-1', 'vehicle_id' => 7, 'updated_at' => 100, 'notes' => 'Parking at the Theater']),
			Expense::fromRow(['id' => 2, 'uuid' => 'e-2', 'vehicle_id' => 7, 'updated_at' => 100, 'deleted_at' => 90]),
		]);

		$service = new SyncService(
			$fleet,
			$this->createMock(OdoReadingMapper::class),
			$this->createMock(TripMapper::class),
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$expenses,
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
			$logger,
		);
		$service->sync('alice', '', 500);

		// A tombstone is a row handed out, too.
		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'Sync',
			'context' => ['app' => 'nextfleet', 'user' => 'alice', 'vehicle' => 'all reachable', 'rows' => 2],
		]], $logger->lines);
	}
}
