<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\CostsShown;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which papers follow the money of the row they hang on (CostsShown::showsPaper()). */
class CostsShownTest extends TestCase {
	private const READER = 'carol';
	private const OTHER = 'alice';

	/** @return iterable<string, array{BaseEntity|null, bool}> */
	public static function papers(): iterable {
		yield 'somebody else\'s fill-up' => [Energy::fromRow(['created_by' => self::OTHER]), false];
		yield 'somebody else\'s record' => [Maintenance::fromRow(['created_by' => self::OTHER]), false];
		yield 'somebody else\'s expense' => [Expense::fromRow(['created_by' => self::OTHER]), false];
		yield 'the reader\'s own fill-up' => [Energy::fromRow(['created_by' => self::READER]), true];
		yield 'the reader\'s own expense' => [Expense::fromRow(['created_by' => self::READER]), true];
		yield 'somebody else\'s booking' => [Booking::fromRow(['user_id' => self::OTHER, 'created_by' => self::OTHER]), true];
		yield 'the vehicle itself' => [null, true];
	}

	#[DataProvider('papers')]
	public function testAPaperIsKeptWhereTheAmountOfItsRowIs(?BaseEntity $row, bool $shown): void {
		$this->assertSame($shown, $this->costs()->showsPaper(self::READER, $this->vehicle(['view', 'log']), $row));
	}

	#[DataProvider('papers')]
	public function testAReaderWhoSeesTheCostsSeesEveryPaper(?BaseEntity $row): void {
		$this->assertTrue($this->costs()->showsPaper(self::READER, $this->vehicle(['view', 'log', 'costs']), $row));
	}

	private function costs(): CostsShown {
		return new CostsShown(new VehicleAccess($this->createMock(AccessMapper::class), $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class)));
	}

	/** @param list<string> $may what the gate left on the vehicle */
	private function vehicle(array $may): Vehicle {
		$vehicle = Vehicle::fromRow(['id' => 7, 'user_id' => self::OTHER]);
		$vehicle->setMay($may);

		return $vehicle;
	}
}
