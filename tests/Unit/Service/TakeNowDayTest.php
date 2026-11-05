<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\Names;
use OCA\NextFleet\Service\UserZone;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Take it now asks nobody while the car is back by midnight (BookingService::insert()). The day
 * ends at the next midnight after the server's now, where the driver lives: their Nextcloud zone,
 * else the offset the sheet sent.
 */
class TakeNowDayTest extends TestCase {
	private const DRIVER = 'dora';
	private const HOUR = 3600;
	/** 2026-11-05 15:00 in Berlin, 14:00 UTC. */
	private const AFTERNOON = 1793887200;
	/** 2026-11-05 23:57 in Berlin. */
	private const LATE = 1793919420;
	private const BERLIN = 60;

	private ?Booking $written = null;

	/** A client that claims to be twelve hours behind cannot stretch the day past the driver's midnight. */
	public function testAMadeUpOffsetDoesNotStretchTheDay(): void {
		// 09:00 in Berlin tomorrow, before midnight at UTC-12.
		$this->takeNow(self::AFTERNOON, 'Europe/Berlin', self::AFTERNOON, -720, self::AFTERNOON + 18 * self::HOUR);

		$this->assertSame(Booking::REQUESTED, $this->written?->getApproval());
	}

	/** A start a few minutes ahead, past midnight, does not start a new day. */
	public function testAStartPastMidnightCountsFromNow(): void {
		$this->takeNow(self::LATE, '', self::LATE + 4 * 60, self::BERLIN, self::LATE + 20 * self::HOUR);

		$this->assertSame(Booking::REQUESTED, $this->written?->getApproval());
	}

	/** Back before the driver's midnight, it asks nobody, whatever offset the client sent. */
	public function testBackByTheDriversMidnightAsksNobody(): void {
		$this->takeNow(self::AFTERNOON, 'Europe/Berlin', self::AFTERNOON, -720, self::AFTERNOON + 8 * self::HOUR);

		$this->assertNull($this->written?->getApproval());
	}

	/** Without a zone of their own, the sent offset says where midnight is. */
	public function testWithoutAZoneTheSentOffsetCounts(): void {
		$this->takeNow(self::LATE, '', self::LATE, -5 * 60, self::LATE + 5 * self::HOUR);

		$this->assertNull($this->written?->getApproval(), '23:57 in Berlin is 17:57 at UTC-5');
	}

	private function takeNow(int $now, string $zone, int $start, int $off, int $end): void {
		$vehicle = new Vehicle();
		$vehicle->setId(7);
		$vehicle->setUuid('0195e2f1-0000-4000-8000-000000000001');
		$vehicle->setUserId('olga');
		$vehicle->setLifecycle(Vehicle::ACTIVE);
		$vehicle->setApprovalRule(Vehicle::APPROVAL_ALWAYS);
		$vehicle->setMay([VehicleAccess::VIEW, VehicleAccess::LOG, VehicleAccess::TAKE_NOW]);
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn($vehicle);
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findAnyById')->willReturn($vehicle);
		$bookings = $this->createMock(BookingMapper::class);
		$bookings->method('findLiveOverlapping')->willReturn([]);
		$bookings->method('insert')->willReturnCallback(function (Booking $booking): Booking {
			return $this->written = $booking;
		});
		$access = $this->createMock(VehicleAccess::class);
		$access->method('mayTakeNow')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with(self::DRIVER, 'core', 'timezone', '')->willReturn($zone);

		(new BookingService(
			$bookings,
			$this->createMock(TripMapper::class),
			$fleet,
			$vehicles,
			$access,
			$this->createMock(IUserManager::class),
			$this->createMock(Names::class),
			$this->createMock(BookingNotices::class),
			$time,
			new UserZone($config),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		))->book(self::DRIVER, $vehicle->getUuid(), [
			'starts_at' => $start,
			'starts_at_off' => $off,
			'ends_at' => $end,
			'ends_at_off' => $off,
		]);
	}
}
