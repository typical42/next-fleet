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
use OCA\NextFleet\Service\Names;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Who of a vehicle's booking notice list hears of one event (docs/architecture.md#booking-notices). */
class BookingNoticesTest extends TestCase {
	/** @var list<array{user: string, subject: string, parameters: array<string, mixed>, object: string, id: string}> */
	private array $sent = [];
	/** @var list<array<string, string>> what each markProcessed() named */
	private array $processed = [];
	/** @var array<int, \Closure(): array<string, string>> each notification's fields so far, by spl_object_id() */
	private array $drafts = [];
	/** @var list<string> who may answer the vehicle's bookings */
	private array $approving = [];
	/** How often `edit` was asked of VehicleAccess. */
	private int $approvalsAsked = 0;
	/** @var list<Booking> what waits for an answer */
	private array $waiting = [];
	/** How often what waits was read. */
	private int $waitingRead = 0;
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

	/** A second question replaces the first: the booker's earlier answers to it go before it is sent. */
	public function testAnAnswerTakesTheBookersEarlierAnswersBack(): void {
		$booking = $this->booking('ann');
		$booking->setApproval(Booking::ASKED);

		$this->notices()->tellAnswered($this->vehicle(), $booking, 'owner');

		$this->assertSame([['user' => 'ann', 'object' => BookingNotices::APPROVAL, 'id' => 'b-1']], $this->processed);
		$this->assertSame([['ann', Booking::ASKED]], array_map(static fn (array $one): array => [$one['user'], $one['subject']], $this->sent));
	}

	/**
	 * Whoever just came to answer is asked what waits for an answer from them, each request once.
	 * Not of their own booking, not of a question, and not if they answer nothing after all.
	 */
	public function testANewApproverIsAskedWhatWaits(): void {
		$this->approving = ['dora', 'cem'];
		$this->waiting = [$this->waiting('b-1', 'ann', Booking::REQUESTED), $this->waiting('b-2', 'bea', Booking::ASKED), $this->waiting('b-3', 'cem', Booking::REQUESTED)];

		$this->notices()->tellWaiting($this->vehicle(), ['dora', 'cem', 'eve']);

		$this->assertSame(
			[['dora', 'b-1'], ['cem', 'b-1'], ['dora', 'b-3']],
			array_map(static fn (array $one): array => [$one['user'], $one['id']], $this->sent),
		);
		$this->assertSame([Booking::REQUESTED], array_values(array_unique(array_column($this->sent, 'subject'))));
		// A manager made a driver and a manager again may still hold the first request.
		$this->assertSame(
			[['user' => 'dora', 'object' => BookingNotices::APPROVAL, 'id' => 'b-1'], ['user' => 'cem', 'object' => BookingNotices::APPROVAL, 'id' => 'b-1'], ['user' => 'dora', 'object' => BookingNotices::APPROVAL, 'id' => 'b-3']],
			$this->processed,
		);
	}

	/**
	 * A vehicle brought back asks again what waits for an answer: a request of its approvers, a
	 * question of its booker.
	 */
	public function testARestoredVehicleAsksAgainWhatWaits(): void {
		$this->approving = ['owner'];
		$this->waiting = [$this->waiting('b-1', 'ann', Booking::REQUESTED), $this->waiting('b-2', 'bea', Booking::ASKED)];

		$this->notices()->tellRestored($this->vehicle());

		$this->assertSame(
			[['owner', 'b-1', Booking::REQUESTED], ['bea', 'b-2', Booking::ASKED]],
			array_map(static fn (array $one): array => [$one['user'], $one['id'], $one['subject']], $this->sent),
		);
	}

	/** With nothing to ask, nobody's access is asked either. */
	public function testNothingWaitingAsksNothing(): void {
		$this->waiting = [$this->waiting('b-2', 'bea', Booking::ASKED)];

		$this->notices()->tellWaiting($this->vehicle(), ['dora', 'cem']);

		$this->assertSame([], $this->sent);
		$this->assertSame(0, $this->approvalsAsked);
	}

	/** A grant that makes nobody an approver, the most of them, reads nothing. */
	public function testNobodyToAskReadsNothing(): void {
		$this->waiting = [$this->waiting('b-1', 'ann', Booking::REQUESTED)];

		$this->notices()->tellWaiting($this->vehicle(), []);

		$this->assertSame([], $this->sent);
		$this->assertSame(0, $this->waitingRead);
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
		$access->method('may')->willReturnCallback(function (string $uid, string $operation): bool {
			if ($operation === VehicleAccess::EDIT) {
				$this->approvalsAsked++;

				return in_array($uid, $this->approving, true);
			}

			return in_array($uid, $this->seeing, true);
		});
		$bookings = $this->createMock(BookingMapper::class);
		$bookings->method('findWaiting')->willReturnCallback(function (): array {
			$this->waitingRead++;

			return $this->waiting;
		});
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('markProcessed')->willReturnCallback(function (INotification $notification): void {
			$this->processed[] = $this->drafts[spl_object_id($notification)]();
		});
		$notifications->method('notify')->willReturnCallback(function (): void {
			if (end($this->sent)['user'] === $this->failFor) {
				throw new \RuntimeException('the push server broke');
			}
		});
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('@1750000000'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($errors))->method('error');

		return new BookingNotices($bookings, $this->createMock(AccessMapper::class), $recipients, new Names($this->createMock(IUserManager::class), $groups), $access, $notifications, $clock, $logger);
	}

	/** A notification that records itself in `sent` once it names its subject, and in `drafts` for markProcessed(). */
	private function notification(): INotification {
		$draft = ['user' => '', 'subject' => '', 'parameters' => [], 'object' => '', 'id' => ''];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$draft): INotification {
			$draft['user'] = $uid;

			return $notification;
		});
		$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use ($notification, &$draft): INotification {
			$draft['object'] = $type;
			$draft['id'] = $id;

			return $notification;
		});
		$this->drafts[spl_object_id($notification)] = static function () use (&$draft): array {
			return array_filter(['user' => $draft['user'], 'object' => $draft['object'], 'id' => $draft['id']], static fn (string $set): bool => $set !== '');
		};
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

	private function waiting(string $uuid, string $booker, string $approval): Booking {
		$booking = $this->booking($booker);
		$booking->setUuid($uuid);
		$booking->setApproval($approval);

		return $booking;
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
