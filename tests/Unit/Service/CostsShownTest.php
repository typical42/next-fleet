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
use OCA\NextFleet\Db\Document;
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

/** Which papers follow the money: of the row they hang on, or of the vehicle for its receipts (CostsShown::showsPaper()). */
class CostsShownTest extends TestCase {
	private const READER = 'carol';
	private const OTHER = 'alice';

	/** @return iterable<string, array{BaseEntity|null, string, bool}> */
	public static function papers(): iterable {
		yield 'somebody else\'s fill-up' => [Energy::fromRow(['created_by' => self::OTHER]), Document::RECEIPT, false];
		yield 'somebody else\'s record' => [Maintenance::fromRow(['created_by' => self::OTHER]), Document::RECEIPT, false];
		yield 'somebody else\'s expense' => [Expense::fromRow(['created_by' => self::OTHER]), Document::RECEIPT, false];
		yield 'the reader\'s own fill-up' => [Energy::fromRow(['created_by' => self::READER]), Document::RECEIPT, true];
		yield 'the reader\'s own expense' => [Expense::fromRow(['created_by' => self::READER]), Document::RECEIPT, true];
		yield 'somebody else\'s booking' => [Booking::fromRow(['user_id' => self::OTHER, 'created_by' => self::OTHER]), Document::PHOTO, true];
		yield 'a receipt on somebody else\'s booking' => [Booking::fromRow(['user_id' => self::OTHER, 'created_by' => self::OTHER]), Document::RECEIPT, false];
		// The booker decides, whoever the row names as its author.
		yield 'a receipt on a booking whose booker is somebody else' => [Booking::fromRow(['user_id' => self::OTHER, 'created_by' => self::READER]), Document::RECEIPT, false];
		yield 'a receipt on the reader\'s own booking' => [Booking::fromRow(['user_id' => self::READER, 'created_by' => self::OTHER]), Document::RECEIPT, true];
		yield 'the vehicle\'s registration' => [null, Document::REGISTRATION, true];
		yield 'the vehicle\'s insurance' => [null, Document::INSURANCE, true];
		yield 'a receipt on the vehicle' => [null, Document::RECEIPT, false];
	}

	#[DataProvider('papers')]
	public function testAPaperIsKeptWhereTheAmountOfItsRowIs(?BaseEntity $row, string $kind, bool $shown): void {
		$this->assertSame($shown, $this->costs()->showsPaper(self::READER, $this->vehicle(['view', 'log']), $kind, $row));
	}

	#[DataProvider('papers')]
	public function testAReaderWhoSeesTheCostsSeesEveryPaper(?BaseEntity $row, string $kind): void {
		$this->assertTrue($this->costs()->showsPaper(self::READER, $this->vehicle(['view', 'log', 'costs']), $kind, $row));
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
