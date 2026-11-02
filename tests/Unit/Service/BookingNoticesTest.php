<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\BookingRecipient;
use OCA\NextFleet\Db\BookingRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Who of a vehicle's booking notice list hears of one event (docs/architecture.md#booking-notices). */
class BookingNoticesTest extends TestCase {
	/** @var list<array{user: string, subject: string, parameters: array<string, mixed>, object: string}> */
	private array $sent = [];
	/** @var list<BookingRecipient>|null null for a list that cannot be read */
	private ?array $listed = [];
	/** @var list<string> who sees the vehicle */
	private array $seeing = ['owner', 'ann', 'bea', 'cem'];
	/** @var array<string, list<string>> members by group */
	private array $members = ['crew' => ['bea', 'cem', 'stranger']];
	private string $failFor = '';

	public function testAnEmptyListMeansTheOwner(): void {
		$this->notices()->tell($this->vehicle(), $this->booking('ann'), Vehicle::NOTICE_BOOKED, 'ann');

		$this->assertSame(['owner'], array_column($this->sent, 'user'));
		$this->assertSame(BookingNotices::EVENT, $this->sent[0]['object']);
		$this->assertSame(Vehicle::NOTICE_BOOKED, $this->sent[0]['subject']);
		$this->assertSame(
			['vehicle' => 'v-1', 'starts_at' => 1750003600, 'starts_at_off' => 120, 'ends_at' => 1750014400, 'ends_at_off' => 120],
			$this->sent[0]['parameters'],
		);
	}

	/** A group is its members, once each, and only those who see the vehicle hear of it. */
	public function testTheListIsItsUsersAndTheMembersOfItsGroups(): void {
		$this->listed = [$this->user('ann'), $this->group('crew'), $this->user('cem')];

		$this->notices()->tell($this->vehicle(), $this->booking('owner'), Vehicle::NOTICE_BOOKED, 'owner');

		$this->assertSame(['ann', 'bea', 'cem'], array_column($this->sent, 'user'));
	}

	public function testNobodyIsToldOfTheirOwnAction(): void {
		$this->listed = [$this->user('ann'), $this->user('bea')];

		$this->notices()->tell($this->vehicle(), $this->booking('ann'), Vehicle::NOTICE_TAKEN, 'ann');

		$this->assertSame(['bea'], array_column($this->sent, 'user'));
	}

	/** An event the owner did not tick reaches nobody. */
	public function testOnlyTickedEventsAreTold(): void {
		$this->listed = [$this->user('bea')];
		$vehicle = $this->vehicle(['returned']);

		$this->notices()->tell($vehicle, $this->booking('ann'), Vehicle::NOTICE_BOOKED, 'ann');
		$this->notices()->tell($vehicle, $this->returned('ann'), Vehicle::NOTICE_RETURNED, 'ann');

		$this->assertSame([Vehicle::NOTICE_RETURNED], array_column($this->sent, 'subject'));
		$this->assertSame(['vehicle' => 'v-1', 'odo' => 12345, 'level' => 40], $this->sent[0]['parameters']);
	}

	/** The booker hears of a cancel by the notice of their own (tellCancelled()), never twice. */
	public function testTheBookerOnTheListIsNotToldOfTheCancelTwice(): void {
		$this->listed = [$this->user('ann'), $this->user('bea')];

		$this->notices()->tell($this->vehicle(['cancelled']), $this->booking('ann'), Vehicle::NOTICE_CANCELLED, 'owner');

		$this->assertSame(['bea'], array_column($this->sent, 'user'));
	}

	/** Sent after the booking stands: one listener nobody can tell leaves the rest told. */
	public function testAListenerThatCannotBeToldLeavesTheOthersTold(): void {
		$this->listed = [$this->user('ann'), $this->user('bea'), $this->user('cem')];
		$this->failFor = 'bea';

		$this->notices(errors: 1)->tell($this->vehicle(), $this->booking('owner'), Vehicle::NOTICE_BOOKED, 'owner');

		$this->assertSame(['ann', 'bea', 'cem'], array_column($this->sent, 'user'));
	}

	/** The booking is written already: a list that cannot be read is logged, not answered as a failure. */
	public function testAListThatCannotBeReadIsLogged(): void {
		$this->listed = null;

		$this->notices(errors: 1)->tell($this->vehicle(), $this->booking('ann'), Vehicle::NOTICE_BOOKED, 'ann');

		$this->assertSame([], $this->sent);
	}

	private function notices(int $errors = 0): BookingNotices {
		$recipients = $this->createMock(BookingRecipientMapper::class);
		$recipients->method('findByVehicle')->willReturnCallback(fn (): array => $this->listed ?? throw new \RuntimeException('the database went away'));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid): ?IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(array_map($this->account(...), $this->members[$gid] ?? []));

			return $group;
		});
		$access = $this->createMock(VehicleAccess::class);
		$access->method('may')->willReturnCallback(fn (string $uid, string $operation): bool => $operation === VehicleAccess::VIEW && in_array($uid, $this->seeing, true));
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('notify')->willReturnCallback(function (): void {
			if (end($this->sent)['user'] === $this->failFor) {
				throw new \RuntimeException('the push server broke');
			}
		});
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('@1750000000'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($errors))->method('error');

		return new BookingNotices($this->createMock(BookingMapper::class), $this->createMock(AccessMapper::class), $recipients, $groups, $access, $notifications, $clock, $logger);
	}

	/** A notification that records itself in `sent` once it names its user. */
	private function notification(): INotification {
		$draft = ['user' => '', 'subject' => '', 'parameters' => [], 'object' => ''];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$draft): INotification {
			$draft['user'] = $uid;

			return $notification;
		});
		$notification->method('setObject')->willReturnCallback(function (string $type) use ($notification, &$draft): INotification {
			$draft['object'] = $type;

			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$draft): INotification {
			$draft['subject'] = $subject;
			$draft['parameters'] = $parameters;
			$this->sent[] = $draft;

			return $notification;
		});

		return $notification;
	}

	/** @param list<string>|null $events */
	private function vehicle(?array $events = null): Vehicle {
		return Vehicle::fromRow(['id' => '4', 'uuid' => 'v-1', 'user_id' => 'owner', 'booking_notices' => $events === null ? null : json_encode($events)]);
	}

	private function booking(string $booker): Booking {
		return Booking::fromRow([
			'id' => '9', 'uuid' => 'b-1', 'vehicle_id' => '4', 'user_id' => $booker, 'state' => Booking::BOOKED,
			'starts_at' => '1750003600', 'starts_at_off' => '120', 'ends_at' => '1750014400', 'ends_at_off' => '120',
		]);
	}

	private function returned(string $booker): Booking {
		$booking = $this->booking($booker);
		$booking->setState(Booking::RETURNED);
		$booking->setInOdo(12345);
		$booking->setInLevel(40);

		return $booking;
	}

	private function user(string $uid): BookingRecipient {
		return BookingRecipient::fromRow(['vehicle_id' => '4', 'user_id' => $uid, 'group_id' => null]);
	}

	private function group(string $gid): BookingRecipient {
		return BookingRecipient::fromRow(['vehicle_id' => '4', 'user_id' => null, 'group_id' => $gid]);
	}

	private function account(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}
