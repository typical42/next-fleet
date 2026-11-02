<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\BookingRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The notices of bookings (docs/architecture.md#booking-notices): the one a booker gets when
 * somebody else cancels their booking, those the vehicle's list hears of the events the owner
 * ticked, and a request's to its approvers and their answer's to the booker. Apart from
 * BookingService for the reason GrantNotices gives: deleting a vehicle takes its notices back.
 */
class BookingNotices {
	/** The booker's cancel notice's object: the booking, so whatever undoes the cancel finds what it sent. */
	public const OBJECT = 'booking';
	/** A list notice's object: the booking too, under its own type so the two never mix. */
	public const EVENT = 'booking_event';
	/**
	 * A request to the approvers, or an answer to the booker: the booking again, the subject
	 * where its approval stood when it was sent (Booking::REQUESTED and the answers).
	 */
	public const APPROVAL = 'booking_approval';

	public function __construct(
		private BookingMapper $bookings,
		private AccessMapper $grants,
		private BookingRecipientMapper $recipients,
		private Names $names,
		private VehicleAccess $access,
		private IManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Tells the booker which booking was cancelled, not by whom: the store is out of an erasure's
	 * reach. Parameters only: the notifier words it when the list is read
	 * (lib/Notification/Notifier.php).
	 */
	public function tellCancelled(Vehicle $vehicle, Booking $booking): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($booking->getUserId())
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, $booking->getUuid())
			->setSubject(self::OBJECT, ['vehicle' => $vehicle->getUuid()]);
		$this->notifications->notify($notification);
	}

	/**
	 * Tells the vehicle's list of one event, if the owner ticked it: whoever is on it, a group by
	 * its members, and an empty list is the owner. Never the one who acted, nor, of a cancel, the
	 * booker, whom tellCancelled() tells. Only whoever sees the vehicle: being on the list grants
	 * nothing. For after the commit, as NotificationService::sweep() sends; a send that fails is
	 * logged, and the rest still go.
	 *
	 * The booking's span, or its handover back, rides along as it stands now, so a later change
	 * does not rewrite the notice; the booker is named when it is read, for the reason
	 * tellCancelled() gives.
	 *
	 * @param value-of<Vehicle::NOTICES> $event
	 * @throws \OCP\DB\Exception
	 */
	public function tell(Vehicle $vehicle, Booking $booking, string $event, string $actor): void {
		if (!in_array($event, $vehicle->bookingNotices(), true)) {
			return;
		}
		// The booking stands already: a failed lookup must not answer its write with an error.
		try {
			$listeners = $this->listeners($vehicle);
		} catch (\Throwable $e) {
			$this->logger->error('The booking notices of vehicle ' . $vehicle->getUuid() . ' were not sent', ['exception' => $e]);

			return;
		}
		$parameters = ['vehicle' => $vehicle->getUuid()] + ($event === Vehicle::NOTICE_RETURNED
			? ['odo' => $booking->getInOdo(), 'level' => $booking->getInLevel()]
			: self::span($booking));
		$skipped = $event === Vehicle::NOTICE_CANCELLED ? [$actor, $booking->getUserId()] : [$actor];
		foreach ($listeners as $uid) {
			if (in_array($uid, $skipped, true)) {
				continue;
			}
			try {
				if (!$this->access->may($uid, VehicleAccess::VIEW, $vehicle)) {
					continue;
				}
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime(\DateTime::createFromImmutable($this->time->now()))
					->setObject(self::EVENT, $booking->getUuid())
					->setSubject($event, $parameters);
				$this->notifications->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->error('A booking notice of vehicle ' . $vehicle->getUuid() . ' was not sent', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells the list where a booking stands once nothing waits for an answer (Booking::heard()):
	 * booked, or changed if the list heard of it before; cancelled, if it heard of it at all. A
	 * request is no news to the list, nor is its end.
	 *
	 * @param bool $heard whether the list had heard of it before this write
	 * @throws \OCP\DB\Exception
	 */
	public function tellSettled(Vehicle $vehicle, Booking $booking, bool $heard, string $actor): void {
		if ($booking->getState() === Booking::BOOKED && !$booking->waits()) {
			$this->tell($vehicle, $booking, $heard ? Vehicle::NOTICE_CHANGED : Vehicle::NOTICE_BOOKED, $actor);
		} elseif ($booking->getState() === Booking::CANCELLED && $heard) {
			$this->tell($vehicle, $booking, Vehicle::NOTICE_CANCELLED, $actor);
		}
	}

	/**
	 * Asks whoever answers bookings of the vehicle - the owner and the managers, whatever the
	 * list says - to answer one. Never the booker. What was sent of the booking's approval
	 * before goes: it asked about a span, or answered one, that is no longer the booking's. For
	 * after the commit, logged on failure, as tell() is.
	 */
	public function tellRequested(Vehicle $vehicle, Booking $booking): void {
		try {
			$this->notifications->markProcessed($this->notifications->createNotification()
				->setApp(Application::APP_ID)
				->setObject(self::APPROVAL, $booking->getUuid()));
			foreach ($this->approvers($vehicle) as $uid) {
				if ($uid !== $booking->getUserId()) {
					$this->send($uid, $vehicle, $booking, Booking::REQUESTED);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('A booking request of vehicle ' . $vehicle->getUuid() . ' was not sent', ['exception' => $e]);
		}
	}

	/**
	 * Asks accounts that just came to answer the vehicle's bookings - a new owner, a new manager -
	 * the requests that wait already: tellRequested() asked only who answered then. A question
	 * waits for its booker, not for them. Only those who answer now are asked, for after the
	 * commit, logged on failure, as tell() is.
	 *
	 * @param list<string> $uids
	 */
	public function tellWaiting(Vehicle $vehicle, array $uids): void {
		if ($uids === []) {
			return;
		}
		try {
			$requests = array_filter(
				$this->bookings->findWaiting((int)$vehicle->getId(), $this->time->getTime()),
				static fn (Booking $booking): bool => $booking->getApproval() === Booking::REQUESTED,
			);
			if ($requests === []) {
				return;
			}
			$approvers = array_values(array_filter($uids, fn (string $uid): bool => $this->access->may($uid, VehicleAccess::EDIT, $vehicle)));
			foreach ($requests as $booking) {
				foreach ($approvers as $uid) {
					if ($uid === $booking->getUserId()) {
						continue;
					}
					// A manager made a driver and a manager again may hold the request still.
					$this->notifications->markProcessed($this->notifications->createNotification()
						->setApp(Application::APP_ID)
						->setUser($uid)
						->setObject(self::APPROVAL, $booking->getUuid()));
					$this->send($uid, $vehicle, $booking, Booking::REQUESTED);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('The waiting booking requests of vehicle ' . $vehicle->getUuid() . ' were not sent', ['exception' => $e]);
		}
	}

	/**
	 * Tells the booker the answer the booking just got, unless they gave it, in place of any
	 * earlier answer: a second question is the one to answer. Who answered is read off the
	 * booking when the notice is read, for the reason tellCancelled() gives.
	 */
	public function tellAnswered(Vehicle $vehicle, Booking $booking, string $actor): void {
		if ($booking->getUserId() === $actor) {
			return;
		}
		try {
			$this->notifications->markProcessed($this->notifications->createNotification()
				->setApp(Application::APP_ID)
				->setUser($booking->getUserId())
				->setObject(self::APPROVAL, $booking->getUuid()));
			$this->send($booking->getUserId(), $vehicle, $booking, (string)$booking->getApproval());
		} catch (\Throwable $e) {
			$this->logger->error('A booking answer of vehicle ' . $vehicle->getUuid() . ' was not sent', ['exception' => $e]);
		}
	}

	/**
	 * Asks again what withdrawAll() took back and still waits for an answer: each request of the
	 * approvers, each question of its booker. Nothing else would: a request has no next round, only
	 * its end. For a vehicle brought back, after the commit, logged on failure, as tell() is.
	 */
	public function tellRestored(Vehicle $vehicle): void {
		try {
			$waiting = $this->bookings->findWaiting((int)$vehicle->getId(), $this->time->getTime());
		} catch (\Throwable $e) {
			$this->logger->error('The waiting bookings of vehicle ' . $vehicle->getUuid() . ' were not asked again', ['exception' => $e]);

			return;
		}
		foreach ($waiting as $booking) {
			if ($booking->getApproval() === Booking::REQUESTED) {
				$this->tellRequested($vehicle, $booking);
				continue;
			}
			try {
				$this->send($booking->getUserId(), $vehicle, $booking, Booking::ASKED);
			} catch (\Throwable $e) {
				$this->logger->error('A booking question of vehicle ' . $vehicle->getUuid() . ' was not sent', ['exception' => $e]);
			}
		}
	}

	private function send(string $uid, Vehicle $vehicle, Booking $booking, string $approval): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::APPROVAL, $booking->getUuid())
			->setSubject($approval, ['vehicle' => $vehicle->getUuid()] + self::span($booking));
		$this->notifications->notify($notification);
	}

	/**
	 * The owner and everyone a live grant makes a manager, a group by its members, each once
	 * and only while they hold `edit`, which VehicleAccess::mayApprove() asks for.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	private function approvers(Vehicle $vehicle): array {
		$uids = [$vehicle->getUserId()];
		$roles = VehicleAccess::rolesCovering(VehicleAccess::EDIT);
		foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $grant) {
			if (!in_array($grant->getRole(), $roles, true)) {
				continue;
			}
			if ($grant->getGranteeType() === Access::USER) {
				$uids[] = $grant->getGrantee();
				continue;
			}
			array_push($uids, ...$this->names->members($grant->getGrantee()));
		}

		return array_values(array_filter(
			array_unique($uids),
			fn (string $uid): bool => $this->access->may($uid, VehicleAccess::EDIT, $vehicle),
		));
	}

	/**
	 * The booking's span as it stands, for a notice to keep.
	 *
	 * @return array{starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int}
	 */
	private static function span(Booking $booking): array {
		return [
			'starts_at' => $booking->getStartsAt(),
			'starts_at_off' => $booking->getStartsAtOff(),
			'ends_at' => $booking->getEndsAt(),
			'ends_at_off' => $booking->getEndsAtOff(),
		];
	}

	/**
	 * Every notice of the vehicle's bookings, read or not.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function withdrawAll(Vehicle $vehicle): void {
		foreach ($this->bookings->findSpanning((int)$vehicle->getId(), 0, null) as $booking) {
			$types = $booking->getState() === Booking::CANCELLED ? [self::OBJECT, self::EVENT, self::APPROVAL] : [self::EVENT, self::APPROVAL];
			foreach ($types as $type) {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)->setObject($type, $booking->getUuid());
				$this->notifications->markProcessed($notification);
			}
		}
	}

	/**
	 * The accounts on the list, each once, a group's members in its place; the owner for an
	 * empty one. A group deleted since names nobody.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	private function listeners(Vehicle $vehicle): array {
		$rows = $this->recipients->findByVehicle((int)$vehicle->getId());
		if ($rows === []) {
			return [$vehicle->getUserId()];
		}
		$uids = [];
		foreach ($rows as $row) {
			$groupId = $row->getGroupId();
			if ($groupId === null) {
				$uids[] = (string)$row->getUserId();
				continue;
			}
			array_push($uids, ...$this->names->members($groupId));
		}

		return array_values(array_unique($uids));
	}
}
