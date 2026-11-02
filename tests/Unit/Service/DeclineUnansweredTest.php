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
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The hourly sweep that declines the requests whose end came unanswered (BookingService::declineUnanswered()). */
class DeclineUnansweredTest extends TestCase {
	private const NOW = 1790000000;

	/** One booking that cannot be read holds none of the others up, and the admin's log says so. */
	public function testABookingThatFailsLeavesTheRestToBeDeclined(): void {
		[$broken, $ended] = [self::request('b-1'), self::request('b-2')];
		$bookings = $this->createMock(BookingMapper::class);
		$bookings->method('findWaitingEnded')->willReturn([$broken, $ended]);
		$failure = new \RuntimeException('deadlock');
		$bookings->method('findOnVehicle')->willReturnCallback(static fn (int $vehicleId, string $uuid): Booking => $uuid === 'b-1' ? throw $failure : $ended);
		$bookings->method('updateChecked')->willReturnArgument(0);
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findAnyById')->willReturn(new Vehicle());
		$notices = $this->createMock(BookingNotices::class);
		$notices->expects($this->once())->method('tellAnswered')->with($this->anything(), $ended, '');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('A booking request was not declined at its end', ['exception' => $failure]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		(new BookingService(
			$bookings,
			$this->createMock(TripMapper::class),
			$this->createMock(VehicleService::class),
			$vehicles,
			$this->createMock(VehicleAccess::class),
			$this->createMock(IUserManager::class),
			$notices,
			$time,
			$this->createMock(IDBConnection::class),
			$logger,
		))->declineUnanswered();

		$this->assertSame([Booking::CANCELLED, Booking::DECLINED], [$ended->getState(), $ended->getApproval()]);
	}

	private static function request(string $uuid): Booking {
		$booking = new Booking();
		$booking->setUuid($uuid);
		$booking->setVehicleId(7);
		$booking->setState(Booking::BOOKED);
		$booking->setApproval(Booking::REQUESTED);
		$booking->setEndsAt(self::NOW - 60);

		return $booking;
	}
}
