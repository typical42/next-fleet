<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\BookingConflictException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Everything a Booking is written under (CONTEXT.md, Pool). A booking is a plan, not an Entry, so
 * none of the Entry rules apply: no Reading, no audit row, no timeline.
 *
 * @psalm-import-type NextFleetBooking from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetRecipient from \OCA\NextFleet\ResponseDefinitions
 */
class BookingService {
	use TTransactional;

	/** How far back the list reaches when the client names no start: the last week's. */
	private const LOOKBACK = 7 * 86400;
	/** The bounds of a new span, and how far behind now a start may lie (apply()). */
	private const LONGEST = 90 * 86400;
	private const AHEAD = 365 * 86400;
	private const START_SLACK = 5 * 60;
	/** A handover counter below the vehicle's when it was taken, or a check-in below the check-out. */
	public const ODO_BELOW = 'odo_below';
	/** Checked in after the booking's end. */
	public const LATE = 'late';
	/** What an approver may answer (answer()). */
	public const ANSWERS = [Booking::APPROVED, Booking::ASKED, Booking::DECLINED];

	public function __construct(
		private BookingMapper $bookings,
		private TripMapper $trips,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private VehicleAccess $access,
		private IUserManager $users,
		private Names $names,
		private BookingNotices $notices,
		private ITimeFactory $time,
		private UserZone $zones,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The vehicle's bookings that reach past `from` (a week ago unless named) and start before
	 * `to` (open unless named), cancelled ones included, by start.
	 *
	 * @param array<string, mixed> $params
	 * @return list<NextFleetBooking> each in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if `from` or `to` is not an instant
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid, array $params): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$from = Field::read('from', 'count', Field::MOMENT, $params['from'] ?? null);
		$to = Field::read('to', 'count', Field::MOMENT, $params['to'] ?? null);

		return $this->wired($userId, $vehicle, $this->bookings->findSpanning(
			(int)$vehicle->getId(),
			is_int($from) ? $from : $this->time->getTime() - self::LOOKBACK,
			is_int($to) ? $to : null,
		));
	}

	/**
	 * Books the vehicle for the caller, or, as its owner or a manager, for `user_id`: anybody who
	 * may book it (docs/architecture.md#booking-for-a-driver).
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetBooking the booking as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle, or names somebody else without answering its bookings
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds, or `user_id` names nobody who may book the vehicle
	 * @throws \OCP\DB\Exception
	 */
	public function book(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->bookable($userId, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->bookings,
			static fn (Booking $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			fn (Booking $row): array => $this->wired($userId, $vehicle, [$row])[0],
		);

		// Whom it is for is asked of a new booking only: a retry answers the row it wrote, whatever
		// became of the booker's access since.
		return $once->run(fn (): array => $this->insert($userId, $this->booker($userId, $vehicle, $fields['user_id'] ?? null), $vehicle, $fields, $once));
	}

	/**
	 * Whom an approver may name to book() for: booker()'s rule, asked of everybody who holds `log`,
	 * the caller included, by name.
	 *
	 * @return list<NextFleetRecipient>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user answers no bookings of this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function bookers(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		if (!$this->access->mayApprove($userId, $vehicle)) {
			throw new AccessDeniedException();
		}
		$named = [];
		foreach ($this->access->holders($vehicle, VehicleAccess::LOG) as $uid) {
			if ($this->users->userExists($uid)) {
				$named[] = ['user_id' => $uid, 'display_name' => $this->nameOf($uid)];
			}
		}
		usort($named, static fn (array $one, array $other): int => strnatcasecmp($one['display_name'], $other['display_name']));

		return $named;
	}

	/**
	 * Whom book() books for: the caller unless they name somebody else. Only an approver names
	 * another, since the booking stands at once; and only somebody who may book the vehicle
	 * themselves, so a booking never hands out access. The name is stored as the instance spells
	 * it (Names::spelled()), or the booking would be nobody's to confirm or check out.
	 *
	 * @throws AccessDeniedException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function booker(string $userId, Vehicle $vehicle, mixed $named): string {
		if ($named === null || $named === '') {
			return $userId;
		}
		if (!is_string($named)) {
			throw new \InvalidArgumentException('user_id is a uid');
		}
		$uid = $this->names->spelled(Access::USER, $named);
		if ($uid === $userId) {
			return $userId;
		}
		if (!$this->access->mayApprove($userId, $vehicle)) {
			throw new AccessDeniedException();
		}
		if ($uid === null || !$this->access->may($uid, VehicleAccess::LOG, $vehicle)) {
			throw new \InvalidArgumentException('user_id names nobody who may book this vehicle');
		}

		return $uid;
	}

	/**
	 * What book() writes once it knows the request is no retry. A booking for somebody else stands
	 * on its approver's word, so it waits for nobody.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Booking> $once
	 * @return NextFleetBooking
	 * @throws \InvalidArgumentException
	 * @throws BookingConflictException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, string $booker, Vehicle $vehicle, array $fields, Once $once): array {
		$booking = new Booking();
		$once->stamp($booking);
		$booking->setVehicleId((int)$vehicle->getId());
		$booking->setUserId($booker);
		$booking->setCreatedBy($userId);
		$booking->setState(Booking::BOOKED);
		$this->apply($booking, $fields);
		// Take it now books, then checks out, from the client: a start within the slack is the car
		// taken. A car needed now waits for nobody, but kept past the day it is a booking ahead,
		// and a driver the owner blocked asks for it like any booking.
		$takenNow = $booking->getStartsAt() <= $this->time->getTime() + self::START_SLACK;
		// Made for somebody else, it is approved by its maker even when taken now.
		$skips = $takenNow && $booking->getEndsAt() <= $this->dayEnd($userId, $booking) && $this->access->mayTakeNow($userId, $vehicle) && $booker === $userId;

		// Retried for the reason TripService::record() gives.
		$written = $this->atomicRetry(function () use ($userId, $vehicle, $booking, $once, $skips): Booking {
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();
			if (!$skips && $this->asksApproval($vehicle)) {
				$this->approvalFor($userId, $vehicle, $booking);
			}
			$this->claim($booking);

			return $this->bookings->insert($booking);
		}, $this->db);
		// After the booking stands, the reason NotificationService::sweep() gives. The list hears
		// of a request once it is approved (answer()).
		if ($written->waits()) {
			$this->notices->tellRequested($vehicle, $written);
		} else {
			// The booker of a booking made for them hears of it in a notice of their own. Nobody took
			// that car yet, whatever its start: the driver checks it out, and a check-out tells nobody.
			$told = $written->madeByAnother() ? $this->notices->tellBookedFor($vehicle, $written) : [];
			$taken = $takenNow && !$written->madeByAnother();
			$this->notices->tell($vehicle, $written, $taken ? Vehicle::NOTICE_TAKEN : Vehicle::NOTICE_BOOKED, $userId, $told);
		}

		return $this->wired($userId, $vehicle, [$written])[0];
	}

	/**
	 * Moves the span or rewrites the purpose, while the car is not yet taken. The booker's change to
	 * a booking asked back is their answer, a new purpose too: it asks its approvers again.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return NextFleetBooking the booking as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if it is not the user's to change
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the booking has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds, or the car is out
	 * @throws BookingConflictException if another live booking holds the new span
	 * @throws \OCP\DB\Exception
	 */
	public function change(string $userId, string $vehicleUuid, string $bookingUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->bookable($userId, $vehicleUuid);

		/** @var array{Booking, bool, bool, bool, bool} $result */
		$result = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid, $expectedUpdatedAt, $fields): array {
			$booking = $this->inState($userId, $vehicle, $bookingUuid, $expectedUpdatedAt, Booking::BOOKED);
			$was = [$booking->getStartsAt(), $booking->getEndsAt()];
			$wasPurpose = $booking->getPurpose();
			[$heard, $waited] = [$booking->heard(), $booking->waits()];
			$this->apply($booking, $fields);
			$moved = $was !== [$booking->getStartsAt(), $booking->getEndsAt()];
			// The booker has no other way to answer than this; a save that changes nothing says nothing.
			$replies = $booking->getApproval() === Booking::ASKED && $booking->getUserId() === $userId
				&& ($moved || $booking->getPurpose() !== $wasPurpose);
			// An answer was given to a span, so a new purpose keeps it, unless it is the booker's reply.
			if (($moved || $replies) && $this->asksApproval($vehicle)) {
				// A booking from before the switch was heard of by its list when it was made.
				$booking->setAnnounced($heard);
				$this->approvalFor($userId, $vehicle, $booking);
			}
			$this->claim($booking);

			return [$this->bookings->updateChecked($booking, $expectedUpdatedAt), $moved, $replies, $heard, $waited];
		}, $this->db);
		[$changed, $moved, $replies, $heard, $waited] = $result;
		// A new purpose alone is no news to anybody, unless it is the booker's reply: that asks every
		// approver again.
		if ($moved || $replies) {
			if ($changed->waits()) {
				$this->notices->tellRequested($vehicle, $changed);
			} else {
				$this->notices->tellSettled($vehicle, $changed, $heard, $userId);
			}
			// An approver moving a request answered it.
			if ($waited && !$changed->waits()) {
				$this->notices->tellAnswered($vehicle, $changed, $userId);
			}
		}

		return $this->wired($userId, $vehicle, [$changed])[0];
	}

	/**
	 * Cancels the booking. The row stays, `cancelled`, and holds the vehicle no longer.
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return NextFleetBooking the booking as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if it is not the user's to cancel
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the booking has changed since
	 * @throws \InvalidArgumentException if it is no longer `booked`
	 * @throws \OCP\DB\Exception
	 */
	public function cancel(string $userId, string $vehicleUuid, string $bookingUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		$cancelled = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid, $expectedUpdatedAt): Booking {
			$booking = $this->inState($userId, $vehicle, $bookingUuid, $expectedUpdatedAt, Booking::BOOKED);
			$booking->setState(Booking::CANCELLED);

			return $this->bookings->updateChecked($booking, $expectedUpdatedAt);
		}, $this->db);
		// After the cancel stands, the reason NotificationService::sweep() gives.
		$told = [];
		if ($cancelled->getUserId() !== $userId) {
			$this->notices->tellCancelled($vehicle, $cancelled);
		} elseif ($cancelled->madeByAnother()) {
			$told = $this->notices->tellCancelledByBooker($vehicle, $cancelled);
		}
		$this->notices->tellSettled($vehicle, $cancelled, $cancelled->heard(), $userId, $told);

		return $this->wired($userId, $vehicle, [$cancelled])[0];
	}

	/**
	 * Confirms, as its booker, a booking somebody else made for them. Nothing waits on it: the
	 * booking stood before. The first confirmation is the one kept.
	 *
	 * @return NextFleetBooking the booking, `seen_at` set, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the booking is not the user's
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the user made it themselves
	 * @throws \OCP\DB\Exception
	 */
	public function seen(string $userId, string $vehicleUuid, string $bookingUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);

		$seen = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid): Booking {
			$this->vehicles->hold((int)$vehicle->getId());
			$booking = $this->bookings->findOnVehicle((int)$vehicle->getId(), $bookingUuid);
			if ($booking->getUserId() !== $userId) {
				throw new AccessDeniedException();
			}
			if (!$booking->madeByAnother()) {
				throw new \InvalidArgumentException('only a booking somebody else made for you is confirmed');
			}
			if ($booking->getSeenAt() !== null) {
				return $booking;
			}
			$booking->setSeenAt($this->time->getTime());

			return $this->bookings->updateChecked($booking, $booking->getUpdatedAt());
		}, $this->db);

		return $this->wired($userId, $vehicle, [$seen])[0];
	}

	/**
	 * Answers a booking that waits for one, as the owner or a manager: approved, asked back with
	 * a question, or declined, which cancels it and frees its span. The comment is the
	 * booker's to read.
	 *
	 * @param value-of<self::ANSWERS> $answer
	 * @param array<string, mixed> $fields `comment`, if any
	 * @param int $expectedUpdatedAt the `updated_at` the client read: a booking moved since is
	 *                               not the one the approver saw
	 * @return NextFleetBooking the booking as answered, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not answer bookings of this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the booking has changed since
	 * @throws \InvalidArgumentException if it is no answer, or the booking waits for none
	 * @throws \OCP\DB\Exception
	 */
	public function answer(string $userId, string $vehicleUuid, string $bookingUuid, int $expectedUpdatedAt, string $answer, array $fields): array {
		if (!in_array($answer, self::ANSWERS, true)) {
			throw new \InvalidArgumentException('an answer approves, asks back or declines');
		}
		$comment = Field::read('comment', 'text', Field::TEXT, $fields['comment'] ?? null);
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		/** @var array{Booking, bool} $result */
		$result = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid, $expectedUpdatedAt, $answer, $comment): array {
			$booking = $this->inState($userId, $vehicle, $bookingUuid, $expectedUpdatedAt, Booking::BOOKED);
			if (!$booking->waits()) {
				throw new \InvalidArgumentException('only a booking waiting for an answer is answered');
			}
			// declineUnanswered() answers it within the hour.
			if ($this->time->getTime() >= $booking->getEndsAt()) {
				throw new \InvalidArgumentException('the booking is over, so it is answered no more');
			}
			$heard = $booking->heard();
			if ($answer === Booking::APPROVED) {
				$booking->approve($userId);
			} else {
				$booking->setApproval($answer);
				$booking->setApprovalBy($userId);
			}
			$booking->setApprovalComment(is_string($comment) ? $comment : null);
			if ($answer === Booking::DECLINED) {
				$booking->setState(Booking::CANCELLED);
			}

			return [$this->bookings->updateChecked($booking, $expectedUpdatedAt), $heard];
		}, $this->db);
		[$answered, $heard] = $result;
		// After the answer stands, the reason NotificationService::sweep() gives.
		$this->notices->tellAnswered($vehicle, $answered, $userId);
		$this->notices->tellSettled($vehicle, $answered, $heard, $userId);

		return $this->wired($userId, $vehicle, [$answered])[0];
	}

	/**
	 * Declines every booking still waiting for an answer at its end (Booking::waits()), by nobody
	 * and with no comment: the span it held is over. The booker is told, and the list only if it
	 * had heard of it. For the hourly PendingJob. A booking that fails is logged and left for the
	 * next hour; the rest still go.
	 *
	 * @throws \OCP\DB\Exception if the requests cannot be found
	 */
	public function declineUnanswered(): void {
		$now = $this->time->getTime();
		foreach ($this->bookings->findWaitingEnded($now) as $found) {
			try {
				$this->decline($found, $now);
			} catch (\Throwable $e) {
				$this->logger->error('A booking request was not declined at its end', ['exception' => $e]);
			}
		}
	}

	/**
	 * One of declineUnanswered()'s requests.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function decline(Booking $found, int $now): void {
		$vehicle = $this->vehicles->findAnyById($found->getVehicleId());
		/** @var array{?Booking, bool} $result */
		$result = $this->atomicRetry(function () use ($found, $now): array {
			// Read again under the hold: an approver may have answered since.
			$this->vehicles->hold($found->getVehicleId());
			$booking = $this->bookings->findOnVehicle($found->getVehicleId(), $found->getUuid());
			if ($booking->getState() !== Booking::BOOKED || !$booking->waits() || $booking->getEndsAt() > $now) {
				return [null, false];
			}
			$heard = $booking->heard();
			$booking->setState(Booking::CANCELLED);
			$booking->setApproval(Booking::DECLINED);
			$booking->setApprovalBy(null);
			$booking->setApprovalComment(null);

			return [$this->bookings->updateChecked($booking, $booking->getUpdatedAt()), $heard];
		}, $this->db);
		[$declined, $heard] = $result;
		// A deleted vehicle's notices are taken back with it.
		if ($declined !== null && $vehicle->getDeletedAt() === null) {
			$this->notices->tellAnswered($vehicle, $declined, '');
			$this->notices->tellSettled($vehicle, $declined, $heard, '');
		}
	}

	/**
	 * Hands the car over to the booker (CONTEXT.md, Pool): the moment is the server's, the counter,
	 * level and note the booker's. Writes no Reading - the trip the check-in prompts is the evidence.
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetBooking the booking, `out`, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if it is not the user's to take
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds, or the booking is
	 *                                   not `booked` or is over
	 * @throws BookingConflictException if another booking of the vehicle is `out`, or holds the
	 *                                  hours before an early start
	 * @throws \OCP\DB\Exception
	 */
	public function checkOut(string $userId, string $vehicleUuid, string $bookingUuid, array $fields): array {
		$vehicle = $this->bookable($userId, $vehicleUuid);
		[$odo, $level, $notes, $atOff] = self::handover($fields);
		$now = $this->time->getTime();

		$out = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid, $odo, $level, $notes, $atOff, $now): Booking {
			$booking = $this->inState($userId, $vehicle, $bookingUuid, null, Booking::BOOKED);
			if ($now >= $booking->getEndsAt()) {
				throw new \InvalidArgumentException('the booking is over, so the car is no longer taken under it');
			}
			if ($booking->waits()) {
				throw new \InvalidArgumentException('the booking waits for an answer, so the car is not taken under it yet');
			}
			$taken = $this->bookings->findOut((int)$vehicle->getId());
			if ($taken !== null) {
				throw $this->conflict('the vehicle is still out', $taken);
			}
			// Early is allowed, but it claims the hours before the start as well.
			$before = $now < $booking->getStartsAt()
				? $this->bookings->findLiveOverlapping((int)$vehicle->getId(), $now, $booking->getStartsAt(), $now, $booking->getId())[0] ?? null
				: null;
			if ($before !== null) {
				throw $this->conflict('the vehicle is booked until then', $before);
			}
			$booking->setState(Booking::OUT);
			$booking->setOutAt($now);
			$booking->setOutAtOff($atOff);
			$booking->setOutOdo($odo);
			$booking->setOutLevel($level);
			$booking->setOutNotes($notes);

			return $this->bookings->updateChecked($booking, $booking->getUpdatedAt());
		}, $this->db);

		return $this->wired($userId, $vehicle, [$out])[0];
	}

	/**
	 * Takes the car back, as check-out took it, and answers the trip the handover describes for the
	 * entry sheet to prefill. Logs no trip: a business trip needs a purpose and a partner only the
	 * driver knows. Any vehicle, since a car laid up while out still comes back.
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetBooking the booking, `returned`, in its wire form, its `trip_draft` set
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if it is not the user's to give back
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds, or the car is not out
	 * @throws \OCP\DB\Exception
	 */
	public function checkIn(string $userId, string $vehicleUuid, string $bookingUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		[$odo, $level, $notes, $atOff] = self::handover($fields);
		$now = $this->time->getTime();

		$in = $this->atomicRetry(function () use ($userId, $vehicle, $bookingUuid, $odo, $level, $notes, $atOff, $now): Booking {
			$booking = $this->inState($userId, $vehicle, $bookingUuid, null, Booking::OUT);
			$booking->setState(Booking::RETURNED);
			$booking->setInAt($now);
			$booking->setInAtOff($atOff);
			$booking->setInOdo($odo);
			$booking->setInLevel($level);
			$booking->setInNotes($notes);

			return $this->bookings->updateChecked($booking, $booking->getUpdatedAt());
		}, $this->db);
		$this->notices->tell($vehicle, $in, Vehicle::NOTICE_RETURNED, $userId);

		return $this->wired($userId, $vehicle, [$in])[0];
	}

	/**
	 * Ties a trip just written to the booking it was logged from. Inside the trip's transaction and
	 * under the vehicle's hold, so two trips logged from one booking cannot both be tied, and a
	 * refusal takes the trip back with it.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if it is not the user's booking to log
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle has no such booking
	 * @throws BookingConflictException if the car is not back yet, or the booking has its trip
	 * @throws \OCP\DB\Exception
	 */
	public function tie(string $userId, Vehicle $vehicle, string $bookingUuid, Trip $trip): void {
		$booking = $this->theirs($userId, $vehicle, $bookingUuid);
		if ($booking->getState() !== Booking::RETURNED) {
			throw $this->conflict('only a booking whose car is back is logged as a trip', $booking);
		}
		if ($booking->getTripId() !== null) {
			throw $this->conflict('the booking has its trip already', $booking);
		}
		$booking->setTripId((int)$trip->getId());
		$this->bookings->updateChecked($booking, $booking->getUpdatedAt());
	}

	/**
	 * The trip a returned booking describes: out to in, counter to counter. No category, for the
	 * reason checkIn() gives.
	 *
	 * @return array{started_at: ?int, started_at_off: ?int, ended_at: ?int, ended_at_off: ?int, start_odo: ?int, end_odo: ?int, purpose: ?string}
	 */
	private static function draft(Booking $booking): array {
		return [
			'started_at' => $booking->getOutAt(),
			'started_at_off' => $booking->getOutAtOff(),
			'ended_at' => $booking->getInAt(),
			'ended_at_off' => $booking->getInAtOff(),
			'start_odo' => $booking->getOutOdo(),
			'end_odo' => $booking->getInOdo(),
			'purpose' => $booking->getPurpose(),
		];
	}

	/**
	 * The vehicle, for a booking to be made, moved or taken on it: `log`, and in service - a laid-up or
	 * sold car is not in the pool.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function bookable(string $userId, string $vehicleUuid): Vehicle {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		if ($vehicle->getLifecycle() !== Vehicle::ACTIVE) {
			throw new \InvalidArgumentException('the vehicle is not in service, so it takes no booking');
		}

		return $vehicle;
	}

	/**
	 * One booking of the vehicle, under its hold, that is the user's to act on and in `state`.
	 * The token is checked here as well as by the write, so a stale client hears 412 rather than
	 * a refusal of a span or state it never saw. A handover sends none: what counts is the state
	 * the car is in when it is taken, not the one the screen showed.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws StaleUpdateException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function inState(string $userId, Vehicle $vehicle, string $bookingUuid, ?int $expectedUpdatedAt, string $state): Booking {
		$this->vehicles->hold((int)$vehicle->getId());
		$booking = $this->theirs($userId, $vehicle, $bookingUuid);
		if ($expectedUpdatedAt !== null && $booking->getUpdatedAt() !== $expectedUpdatedAt) {
			throw new StaleUpdateException('fleet_bookings row ' . (string)$booking->getId() . ' has changed since it was read');
		}
		if ($booking->getState() !== $state) {
			throw new \InvalidArgumentException(match ($state) {
				Booking::BOOKED => 'only a booking not yet taken changes, is cancelled or is checked out',
				default => 'only a booking whose car is out is checked in',
			});
		}

		return $booking;
	}

	/**
	 * One booking of the vehicle that is the user's to act on (VehicleAccess::mayBooking()).
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	private function theirs(string $userId, Vehicle $vehicle, string $bookingUuid): Booking {
		$booking = $this->bookings->findOnVehicle((int)$vehicle->getId(), $bookingUuid);
		if (!$this->access->mayBooking($userId, $vehicle, $booking->getUserId())) {
			throw new AccessDeniedException();
		}

		return $booking;
	}

	/**
	 * What a handover states, at check-out and check-in alike: the counter, required; the tank or
	 * battery as a percentage; a note; and the offset of the moment the server takes.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{int, ?int, ?string, int}
	 * @throws \InvalidArgumentException
	 */
	private static function handover(array $fields): array {
		$odo = self::required('odo', 'count', $fields, Field::COUNTER);
		$level = Field::read('level', 'count', 100, $fields['level'] ?? null);
		$notes = Field::read('notes', 'text', Field::TEXT, $fields['notes'] ?? null);

		return [$odo, is_int($level) ? $level : null, is_string($notes) ? $notes : null, self::required('at_off', 'offset', $fields)];
	}

	/**
	 * The next midnight after now where the user lives: their Nextcloud zone, else the offset the
	 * sheet sent. From now rather than the start, which may lie five minutes ahead and past
	 * midnight; and from their zone where they set one, since a client may send any offset.
	 */
	private function dayEnd(string $userId, Booking $booking): int {
		$now = $this->time->getTime();
		$zone = $this->zones->own($userId);
		$here = $zone === null
			? Jurisdictions::localTime($now, $booking->getStartsAtOff())
			: (new \DateTimeImmutable('@' . $now))->setTimezone($zone);

		return $here->modify('tomorrow')->getTimestamp();
	}

	/**
	 * Whether the vehicle's bookings wait for an answer, read again under its hold: the vehicle
	 * the caller reached was read before it, and turning approval off, the vehicle's or the
	 * owner's default, approves what waits under the same row (VehicleService::update(),
	 * VehicleService::approveByDefault()). Only that way round matters: a booking made while
	 * approval is turned on is one made before it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function asksApproval(Vehicle $vehicle): bool {
		if (!$vehicle->needsApproval()) {
			return false;
		}
		$held = $this->vehicles->findAnyById((int)$vehicle->getId());
		$this->vehicles->refreshOwnerApproval($held);

		return $held->needsApproval();
	}

	/**
	 * Where a booking the user makes or moves stands on a vehicle whose bookings wait for an
	 * answer: an approver's own hand answers it, anyone else's asks. What was said about the
	 * span before goes with it.
	 */
	private function approvalFor(string $userId, Vehicle $vehicle, Booking $booking): void {
		if ($this->access->mayApprove($userId, $vehicle)) {
			$booking->approve($userId);
		} else {
			$booking->setApproval(Booking::REQUESTED);
			$booking->setApprovalBy(null);
		}
		$booking->setApprovalComment(null);
	}

	/**
	 * Refuses the span when another live booking holds any second of it - an `out` one the hours it
	 * was taken early and those it is overdue as well. Under the vehicle's hold, or two bookings
	 * checked at once could both find it free.
	 *
	 * @throws BookingConflictException
	 * @throws \OCP\DB\Exception
	 */
	private function claim(Booking $booking): void {
		$held = $this->bookings->findLiveOverlapping($booking->getVehicleId(), $booking->getStartsAt(), $booking->getEndsAt(), $this->time->getTime(), $booking->getId())[0] ?? null;
		if ($held !== null) {
			throw $this->conflict($held->getState() === Booking::OUT ? 'the vehicle is still out then' : 'the vehicle is booked then', $held);
		}
	}

	/** The refusal that names the booking in the way, so the sheet can say whose it is and when. */
	private function conflict(string $message, Booking $held): BookingConflictException {
		return new BookingConflictException($message, [
			'uuid' => $held->getUuid(),
			'user_id' => $held->getUserId(),
			'user_name' => $this->nameOf($held->getUserId()),
			'starts_at' => $held->getStartsAt(),
			'starts_at_off' => $held->getStartsAtOff(),
			'ends_at' => $held->getEndsAt(),
			'ends_at_off' => $held->getEndsAtOff(),
			// `out` is "still with Anna", anything else "booked by Anna": the sheet words it by this.
			'state' => $held->getState(),
			// A request in the way reads "requested by Anna".
			'approval' => $held->getApproval(),
		]);
	}

	/**
	 * The span and the purpose, as the sheet sends them. A booking that is over already would be
	 * a trip, so it cannot be made.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private function apply(Booking $booking, array $fields): void {
		// What an edit started from: a span kept as it was is not measured against bounds it predates.
		[$wasStart, $wasEnd] = $booking->getId() === null ? [null, null] : [$booking->getStartsAt(), $booking->getEndsAt()];
		$booking->setStartsAt(self::required('starts_at', 'count', $fields, Field::MOMENT));
		$booking->setStartsAtOff(self::required('starts_at_off', 'offset', $fields));
		$booking->setEndsAt(self::required('ends_at', 'count', $fields, Field::MOMENT));
		$booking->setEndsAtOff(self::required('ends_at_off', 'offset', $fields));
		$purpose = Field::read('purpose', 'text', 255, $fields['purpose'] ?? null);
		$booking->setPurpose(is_string($purpose) ? $purpose : null);

		if ($booking->getEndsAt() <= $booking->getStartsAt()) {
			throw new \InvalidArgumentException('ends_at is after starts_at');
		}
		$now = $this->time->getTime();
		if ($booking->getEndsAt() <= $now) {
			throw new \InvalidArgumentException('ends_at is still to come');
		}
		$kept = $booking->getStartsAt() === $wasStart && $booking->getEndsAt() === $wasEnd;
		// A pool car held for a season is no booking but a reassignment, and one held years ahead
		// blocks a span nobody can plan around (docs/security.md).
		if (!$kept && $booking->getEndsAt() - $booking->getStartsAt() > self::LONGEST) {
			throw new \InvalidArgumentException('a booking spans 90 days at most');
		}
		if (!$kept && $booking->getEndsAt() > $now + self::AHEAD) {
			throw new \InvalidArgumentException('ends_at is a year ahead at most');
		}
		// Taking the car now sends the moment the sheet was saved, a little behind by the time it
		// arrives. Someone running late keeps the start they booked; a new one may not lie behind.
		if ($booking->getStartsAt() < $now - self::START_SLACK && $booking->getStartsAt() !== $wasStart) {
			throw new \InvalidArgumentException('starts_at is in the past');
		}
	}

	/**
	 * A number the request must carry: a span's field, since a PUT states the whole booking, or a
	 * handover's counter and offset.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private static function required(string $column, string $kind, array $fields, ?int $bound = null): int {
		$value = Field::read($column, $kind, $bound, $fields[$column] ?? null);
		if (!is_int($value)) {
			throw new \InvalidArgumentException($column . ' is required');
		}

		return $value;
	}

	/** An erased booker's pseudonym has no account, and reads as itself. */
	private function nameOf(string $uid): string {
		return $this->users->getDisplayName($uid) ?? $uid;
	}

	/**
	 * What looks wrong about a handover, worked out on every read and never stored, so a Reading
	 * entered later changes it; never a refusal, since the car has moved whatever the screen says.
	 * The counter at check-out is measured against the vehicle's when the car was taken, not now:
	 * the trip logged from the booking moves the counter past it.
	 *
	 * @param ?int $counterAtOut the vehicle's main counter at `out_at` (BookingMapper::findCountersAtOut())
	 * @return list<self::ODO_BELOW|self::LATE>
	 */
	private static function flags(Booking $booking, ?int $counterAtOut): array {
		$flags = [];
		$outOdo = $booking->getOutOdo();
		$inOdo = $booking->getInOdo();
		if ($outOdo !== null && (($counterAtOut !== null && $outOdo < $counterAtOut) || ($inOdo !== null && $inOdo < $outOdo))) {
			$flags[] = self::ODO_BELOW;
		}
		$inAt = $booking->getInAt();
		if ($inAt !== null && $inAt > $booking->getEndsAt()) {
			$flags[] = self::LATE;
		}

		return $flags;
	}

	/**
	 * Bookings in their wire form: the booker by name, the trip by uuid, and `may` - what the
	 * caller may do to each, so the screen offers what the server takes.
	 *
	 * @param list<Booking> $bookings
	 * @return list<NextFleetBooking>
	 * @throws \OCP\DB\Exception
	 */
	public function wired(string $userId, Vehicle $vehicle, array $bookings): array {
		$tripIds = [];
		foreach ($bookings as $booking) {
			$tripId = $booking->getTripId();
			if ($tripId !== null) {
				$tripIds[] = $tripId;
			}
		}
		$trips = $this->trips->findAnyByIds((int)$vehicle->getId(), $tripIds);
		$counters = $this->bookings->findCountersAtOut($bookings);
		$now = $this->time->getTime();
		$names = [];
		$approves = $this->access->mayApprove($userId, $vehicle);

		return array_map(function (Booking $booking) use ($userId, $vehicle, $trips, $counters, $now, $approves, &$names): array {
			$booker = $booking->getUserId();
			$names[$booker] ??= $this->nameOf($booker);
			$maker = $booking->getCreatedBy();
			$names[$maker] ??= $this->nameOf($maker);
			$state = $booking->getState();
			$trip = $trips[$booking->getTripId() ?? 0] ?? null;
			$untripped = $state === Booking::RETURNED && $booking->getTripId() === null;
			// bookable()'s rule: a laid-up car keeps its bookings but moves none and is not taken.
			$active = $vehicle->getLifecycle() === Vehicle::ACTIVE;
			$may = $this->access->mayBooking($userId, $vehicle, $booker) ? array_keys(array_filter([
				'edit' => $state === Booking::BOOKED && $active,
				'cancel' => $state === Booking::BOOKED,
				'check_out' => $state === Booking::BOOKED && $active && $now < $booking->getEndsAt() && !$booking->waits(),
				'check_in' => $state === Booking::OUT,
				'log_trip' => $untripped,
				// Handover photos. DocumentService takes a paper on any booking by the same rule;
				// the screen offers only those handed over, which are the ones a photo is of.
				'attach' => $state === Booking::OUT || $state === Booking::RETURNED,
			])) : [];
			// The trip opens in the entry sheet by its own rule, as its timeline row does
			// (VehicleService::changes()); a voided one is no longer read.
			if ($trip !== null && $trip->getDeletedAt() === null && $this->access->mayChange($userId, VehicleAccess::EDIT, $vehicle, $trip->getCreatedBy())) {
				$may[] = 'open_trip';
			}
			// answer()'s rule, one word for each answer.
			if ($state === Booking::BOOKED && $booking->waits() && $approves && $now < $booking->getEndsAt()) {
				array_push($may, 'approve', 'ask', 'decline');
			}

			return [
				'uuid' => $booking->getUuid(),
				'user_id' => $booker,
				'user_name' => $names[$booker],
				'starts_at' => $booking->getStartsAt(),
				'starts_at_off' => $booking->getStartsAtOff(),
				'ends_at' => $booking->getEndsAt(),
				'ends_at_off' => $booking->getEndsAtOff(),
				'purpose' => $booking->getPurpose(),
				'state' => $state,
				'out_at' => $booking->getOutAt(),
				'out_at_off' => $booking->getOutAtOff(),
				'out_odo' => $booking->getOutOdo(),
				'out_level' => $booking->getOutLevel(),
				'out_notes' => $booking->getOutNotes(),
				'in_at' => $booking->getInAt(),
				'in_at_off' => $booking->getInAtOff(),
				'in_odo' => $booking->getInOdo(),
				'in_level' => $booking->getInLevel(),
				'in_notes' => $booking->getInNotes(),
				'trip_uuid' => $trip?->getUuid(),
				// The trip stays tied when voided: the booking was driven, whatever became of its log.
				'trip_voided' => $trip?->getDeletedAt() !== null,
				'trip_draft' => $untripped ? self::draft($booking) : null,
				'approval' => $booking->getApproval(),
				// Between the booker and whoever answers: a question may be about the booker.
				'approval_comment' => $booker === $userId || $approves ? $booking->getApprovalComment() : null,
				'approval_by' => $booking->getApprovalBy(),
				'flags' => self::flags($booking, $counters[(int)$booking->getId()] ?? null),
				'created_at' => $booking->getCreatedAt(),
				'updated_at' => $booking->getUpdatedAt(),
				'created_by' => $maker,
				'created_by_name' => $names[$maker],
				// Whether the booker read their notice is the booker's and their approvers' to know.
				'seen_at' => $booker === $userId || $approves ? $booking->getSeenAt() : null,
				'may' => $may,
			];
		}, $bookings);
	}
}
