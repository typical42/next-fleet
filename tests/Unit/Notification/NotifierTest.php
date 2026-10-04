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
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Notification\Notifier;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\LookalikeNotices;
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
	/** What the notifier read, one word per read. @var list<string> */
	private array $asked = [];
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

		$this->notifier(reminder: self::reminder($dueDate, $dueOdo))->prepare($notification, 'en');

		$this->assertSame($expected, $parsed);
	}

	/** Taken off the list - by hand, or pruned with a revoke - while the notice stood. */
	public function testAReminderForAReaderOffTheListIsProcessed(): void {
		$this->expectException(AlreadyProcessedException::class);

		$this->notifier(listed: false)->prepare($this->notification('due_date', '2031-05-31', null), 'en');
	}

	/** @return array<string, array{\Closure(Reminder): void}> */
	public static function overReminders(): array {
		return [
			'deleted' => [static fn (Reminder $r) => $r->setDeletedAt(1)],
			'done' => [static fn (Reminder $r) => $r->setState(Reminder::DONE)],
			'dismissed' => [static fn (Reminder $r) => $r->setState(Reminder::DISMISSED)],
			'snoozed' => [static fn (Reminder $r) => $r->setState(Reminder::SNOOZED)],
			'on to its next occurrence' => [static fn (Reminder $r) => $r->setOccurrence(2)],
			// An edit racing the sweep: the notice would tell the old date until the next round.
			'given another due date' => [static fn (Reminder $r) => $r->setDueDate(new \DateTime('2031-06-30'))],
			'given a due km' => [static fn (Reminder $r) => $r->setDueOdo(150000)],
		];
	}

	/** Sent after the sweep's commit, it may arrive after the change that should have taken it back. */
	#[DataProvider('overReminders')]
	public function testANoticeItsReminderHasLeftIsProcessed(\Closure $leave): void {
		$reminder = self::reminder();
		$leave($reminder);

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier(reminder: $reminder)->prepare($this->notification('due_date', '2031-05-31', null), 'en');
	}

	public function testANoticeWhoseReminderIsGoneIsProcessed(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->notifier(gone: true)->prepare($this->notification('due_date', '2031-05-31', null), 'en');
	}

	/** Stored before 0.3.1, it names no occurrence; the reminder's state still decides. */
	public function testANoticeWithoutAnOccurrenceStillReads(): void {
		$reminder = self::reminder();
		$reminder->setOccurrence(3);
		$parsed = null;
		$notification = $this->notification('due_date', '2031-05-31', null, null);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier(reminder: $reminder)->prepare($notification, 'en');

		$this->assertSame('B-XY 123: Oil change is due today', $parsed);
	}

	/**
	 * A notification list holds many notices of one vehicle, and Nextcloud prepares each on its
	 * own: the vehicle, who may see it, its reminders and its list are read once for all of them.
	 */
	public function testManyNoticesOfOneVehicleReadItOnce(): void {
		$notifier = $this->notifier();

		foreach (['month_before', 'due_date', 'overdue'] as $point) {
			$notifier->prepare($this->notification($point, '2031-05-31', null), 'en');
		}

		$reads = array_count_values($this->asked);
		ksort($reads);
		$this->assertSame(['access' => 1, 'list' => 1, 'reminders' => 1, 'vehicle' => 1], $reads);
	}

	/** A group's grant reaches each member as a notice of their own, all prepared by one process. */
	public function testOneGrantToManyIsReadOnce(): void {
		$grant = new Access();
		$grant->setRole('viewer');
		$notifier = $this->notifier($grant);

		for ($i = 0; $i < 3; $i++) {
			$notification = $this->notification(GrantNotices::OBJECT, null, null);
			$notification->method('getObjectType')->willReturn(GrantNotices::OBJECT);
			$notification->method('getObjectId')->willReturn('g-1');
			$notification->method('setParsedSubject')->willReturnSelf();
			$notifier->prepare($notification, 'en');
		}

		$this->assertSame(1, array_count_values($this->asked)['grant']);
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

	/** A grantee who no longer sees the vehicle - a former member, an erased account - is told nothing. */
	public function testAGrantTheReaderNoLongerSeesIsProcessed(): void {
		$grant = new Access();
		$grant->setRole('viewer');
		$notification = $this->notification(GrantNotices::OBJECT, null, null);
		$notification->method('getObjectType')->willReturn(GrantNotices::OBJECT);
		$notification->method('getObjectId')->willReturn('g-1');

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier($grant, sees: false)->prepare($notification, 'en');
	}

	/** @return array<string, array{?string}> */
	public static function cancellers(): array {
		return [
			'as sent now' => [null],
			// Stored before 0.3.0: the uid stays in the store, the words no longer read it.
			'naming who cancelled' => ['dave'],
			'naming a gone account' => ['gone'],
		];
	}

	/**
	 * Which booking, by the start as the booker planned it, at its own offset rather than the
	 * server's - and never who cancelled.
	 */
	#[DataProvider('cancellers')]
	public function testACancelSaysWhichBookingAndNamesNobody(?string $by): void {
		$booking = new Booking();
		$booking->setState(Booking::CANCELLED);
		$booking->setStartsAt(2093238000);
		$booking->setStartsAtOff(120);
		$parsed = null;
		$notification = $this->cancelNotice($by);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier(null, $booking)->prepare($notification, 'en');

		$this->assertSame('Your booking of B-XY 123 on 1 May 2036 at 09:00 was cancelled', $parsed);
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

	/** The upgrade kept an account named like an erased driver; the admin is told which to look at. */
	public function testALookalikeNoticeNamesTheAccount(): void {
		$parsed = null;
		$notification = $this->lookalikeNotice('erased-l1v3xl1v3xl1v3xl1v3x');
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier()->prepare($notification, 'en');

		$this->assertSame('The account erased-l1v3xl1v3xl1v3xl1v3x is named like an erased driver. If it was made to take their rows, delete it, which erases them; otherwise nothing is wrong.', $parsed);
	}

	/** Deleted since: there is nothing left to look at. */
	public function testALookalikeNoticeForAGoneAccountIsProcessed(): void {
		$this->expectException(AlreadyProcessedException::class);

		$this->notifier()->prepare($this->lookalikeNotice('erased-g0n3xg0n3xg0n3xg0n3x'), 'en');
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function lookalikeNotice(string $account): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('admin');
		$notification->method('getObjectType')->willReturn(LookalikeNotices::OBJECT);
		$notification->method('getObjectId')->willReturn($account);
		$notification->method('getSubjectParameters')->willReturn(['account' => $account]);
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function cancelNotice(?string $by = null): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('erin');
		$notification->method('getObjectType')->willReturn(BookingNotices::OBJECT);
		$notification->method('getObjectId')->willReturn('b-1');
		$notification->method('getSubjectParameters')->willReturn(['vehicle' => 'v-1'] + ($by === null ? [] : ['by' => $by]));
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}

	/** The reminder a notice of the first occurrence was sent for, still open and where it rang. */
	private static function reminder(?string $dueDate = '2031-05-31', ?int $dueOdo = null): Reminder {
		$reminder = new Reminder();
		$reminder->setId(7);
		$reminder->setState(Reminder::DUE);
		$reminder->setOccurrence(1);
		$reminder->setDueDate($dueDate === null ? null : new \DateTime($dueDate));
		$reminder->setDueOdo($dueOdo);

		return $reminder;
	}

	private function notifier(?Access $grant = null, ?Booking $booking = null, bool $sees = true, bool $listed = true, ?Reminder $reminder = null, bool $gone = false): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('l')->willReturnCallback(static fn (string $type, \DateTime $day): string => $day->format($type === 'time' ? 'H:i' : 'j F Y'));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$vehicle = new Vehicle();
		$vehicle->setPlate('B-XY 123');
		$vehicle->setUserId('anna');
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findByUuid')->willReturnCallback(function () use ($vehicle): Vehicle {
			$this->asked[] = 'vehicle';

			return $vehicle;
		});
		$grants = $this->createMock(AccessMapper::class);
		$grants->method('findOnVehicle')->willReturnCallback(function () use ($grant): Access {
			$this->asked[] = 'grant';

			return $grant ?? throw new DoesNotExistException('gone');
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['anna', 'Anna'], ['dave', 'Dave']]);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'erased-g0n3xg0n3xg0n3xg0n3x');
		$bookings = $this->createMock(BookingMapper::class);
		if ($booking === null) {
			$bookings->method('findOnVehicle')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$bookings->method('findOnVehicle')->willReturn($booking);
		}

		$access = $this->createMock(VehicleAccess::class);
		$access->method('may')->willReturnCallback(function () use ($sees): bool {
			$this->asked[] = 'access';

			return $sees;
		});
		$recipient = new ReminderRecipient();
		$recipient->setUserId($listed ? 'alice' : 'anna');
		$recipients = $this->createMock(ReminderRecipientMapper::class);
		$recipients->method('findByVehicle')->willReturnCallback(function () use ($recipient): array {
			$this->asked[] = 'list';

			return [$recipient];
		});

		$reminder ??= self::reminder();
		$reminders = $this->createMock(ReminderMapper::class);
		$reminders->method('findByVehicle')->willReturnCallback(function () use ($gone, $reminder): array {
			$this->asked[] = 'reminders';

			return $gone ? [] : [$reminder];
		});

		return new Notifier($factory, $this->createMock(IConfig::class), $this->createMock(IURLGenerator::class), $vehicles, $access, $grants, $users, $bookings, $recipients, $reminders);
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function notification(string $point, ?string $dueDate, ?int $dueOdo, ?int $occurrence = 1): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('alice');
		$notification->method('getObjectId')->willReturn('7');
		$notification->method('getSubject')->willReturn($point);
		$notification->method('getSubjectParameters')->willReturn([
			'vehicle' => 'v-1', 'plate' => 'B-XY 123', 'template_key' => 'oil_change', 'title' => null,
			'due_date' => $dueDate, 'due_odo' => $dueOdo,
		] + ($occurrence === null ? [] : ['occurrence' => $occurrence]));
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}
}
