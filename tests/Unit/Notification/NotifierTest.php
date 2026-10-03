<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Notification;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Notification\Notifier;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every point the engine receipts, and every role a grant gives, reads as a sentence; the
 * integration suites (tests/Integration/ReminderJobTest.php, GrantNotificationTest.php) show them
 * arriving in a real language.
 */
class NotifierTest extends TestCase {
	/** @return array<string, array{string, ?string, ?int, string}> */
	public static function points(): array {
		return [
			'a month before' => ['month_before', '2031-05-31', null, 'B-XY 123: Oil change is due on 31 May 2031'],
			'the month it is due' => ['month_start', '2031-05-31', null, 'B-XY 123: Oil change is due on 31 May 2031'],
			'on the day' => ['due_date', '2031-05-31', null, 'B-XY 123: Oil change is due today'],
			'the lead by km' => ['odo', null, 150000, 'B-XY 123: Oil change is due at 150000 km'],
			'the km reached' => ['odo_due', null, 150000, 'B-XY 123: Oil change is due'],
			'the day after' => ['overdue', '2031-05-31', null, 'B-XY 123: Oil change is overdue (due 31 May 2031)'],
		];
	}

	#[DataProvider('points')]
	public function testEachPointReadsAsASentence(string $point, ?string $dueDate, ?int $dueOdo, string $expected): void {
		$parsed = null;
		$notification = $this->notification($point, $dueDate, $dueOdo);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier()->prepare($notification, 'en');

		$this->assertSame($expected, $parsed);
	}

	public function testAPointThisAppDoesNotSendIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier()->prepare($this->notification('someday', null, null), 'en');
	}

	/** @return array<string, array{string, string}> */
	public static function roles(): array {
		return [
			'manager' => ['manager', 'Anna gave you access to B-XY 123 as a manager'],
			'driver' => ['driver', 'Anna gave you access to B-XY 123 as a driver'],
			'viewer' => ['viewer', 'Anna gave you access to B-XY 123 as a viewer'],
		];
	}

	#[DataProvider('roles')]
	public function testAGrantNamesTheOwnerTheVehicleAndTheRole(string $role, string $expected): void {
		$grant = new Access();
		$grant->setRole($role);
		$parsed = null;
		$notification = $this->notification(GrantNotices::OBJECT, null, null);
		$notification->method('getObjectType')->willReturn(GrantNotices::OBJECT);
		$notification->method('getObjectId')->willReturn('g-1');
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier($grant)->prepare($notification, 'en');

		$this->assertSame($expected, $parsed);
	}

	/** Revoked while the list was on its way: nothing is left to tell. */
	public function testAGrantThatIsGoneIsProcessed(): void {
		$notification = $this->notification(GrantNotices::OBJECT, null, null);
		$notification->method('getObjectType')->willReturn(GrantNotices::OBJECT);
		$notification->method('getObjectId')->willReturn('g-1');

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier()->prepare($notification, 'en');
	}

	/** By the start as the booker planned it, at its own offset rather than the server's. */
	public function testACancelNamesWhoCancelledAndWhen(): void {
		$booking = new Booking();
		$booking->setState(Booking::CANCELLED);
		$booking->setStartsAt(2093238000);
		$booking->setStartsAtOff(120);
		$parsed = null;
		$notification = $this->cancelNotice();
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier(null, $booking)->prepare($notification, 'en');

		$this->assertSame('Dave cancelled your booking of B-XY 123 on 1 May 2036 at 09:00', $parsed);
	}

	/**
	 * The notice keeps the uid it was sent with; once that account is gone, the screen says who
	 * in words rather than show the uid (docs/legal.md).
	 */
	public function testACancelByAnErasedAccountNamesAFormerUser(): void {
		$booking = new Booking();
		$booking->setState(Booking::CANCELLED);
		$booking->setStartsAt(2093238000);
		$booking->setStartsAtOff(120);
		$parsed = null;
		$notification = $this->cancelNotice('gone');
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier(null, $booking)->prepare($notification, 'en');

		$this->assertSame('A former user cancelled your booking of B-XY 123 on 1 May 2036 at 09:00', $parsed);
	}

	/** Brought back, or gone: the cancel is no longer news. */
	public function testABookingNoLongerCancelledIsProcessed(): void {
		$booking = new Booking();
		$booking->setState(Booking::BOOKED);

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier(null, $booking)->prepare($this->cancelNotice(), 'en');
	}

	public function testABookingThatIsGoneIsProcessed(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->notifier()->prepare($this->cancelNotice(), 'en');
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function cancelNotice(string $by = 'dave'): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('erin');
		$notification->method('getObjectType')->willReturn(BookingNotices::OBJECT);
		$notification->method('getObjectId')->willReturn('b-1');
		$notification->method('getSubjectParameters')->willReturn(['vehicle' => 'v-1', 'by' => $by]);
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}

	private function notifier(?Access $grant = null, ?Booking $booking = null): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('l')->willReturnCallback(static fn (string $type, \DateTime $day): string => $day->format($type === 'time' ? 'H:i' : 'j F Y'));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$vehicle = new Vehicle();
		$vehicle->setPlate('B-XY 123');
		$vehicle->setUserId('anna');
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findByUuid')->willReturn($vehicle);
		$grants = $this->createMock(AccessMapper::class);
		if ($grant === null) {
			$grants->method('findOnVehicle')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$grants->method('findOnVehicle')->willReturn($grant);
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['anna', 'Anna'], ['dave', 'Dave']]);
		$bookings = $this->createMock(BookingMapper::class);
		if ($booking === null) {
			$bookings->method('findOnVehicle')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$bookings->method('findOnVehicle')->willReturn($booking);
		}

		return new Notifier($factory, $this->createMock(IConfig::class), $this->createMock(IURLGenerator::class), $vehicles, $this->createMock(VehicleAccess::class), $grants, $users, $bookings);
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function notification(string $point, ?string $dueDate, ?int $dueOdo): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('alice');
		$notification->method('getSubject')->willReturn($point);
		$notification->method('getSubjectParameters')->willReturn([
			'vehicle' => 'v-1', 'plate' => 'B-XY 123', 'template_key' => 'oil_change', 'title' => null,
			'due_date' => $dueDate, 'due_odo' => $dueOdo,
		]);
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}
}
