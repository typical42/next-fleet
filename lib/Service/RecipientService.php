<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\BookingRecipient;
use OCA\NextFleet\Db\BookingRecipientMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who a vehicle's reminders go to (docs/architecture.md#reminder-engine), and who hears of its
 * bookings (docs/architecture.md#booking-notices). Reading the reminder list takes EDIT as writing
 * it does: who gets told is the managers' business, and the sheet shows the list to whoever may
 * change it and to nobody else. The booking list is the owner's to write, as the events it hears
 * are; a manager reads it.
 *
 * @psalm-import-type NextFleetRecipient from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetBookingRecipient from \OCA\NextFleet\ResponseDefinitions
 */
class RecipientService {
	use TTransactional;

	public function __construct(
		private ReminderRecipientMapper $recipients,
		private BookingRecipientMapper $bookingRecipients,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IUserManager $users,
		private IGroupManager $groups,
		private Names $names,
		private Sharable $sharable,
		private VehicleAccess $access,
		private NotificationService $notifications,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<NextFleetRecipient>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		return $this->of($this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid));
	}

	/**
	 * Puts one account on the list. Being on it grants nothing: the notification carries the
	 * plate and the title, and Vehicle Access decides what its link opens. Adding somebody already
	 * on it changes nothing. The plate goes only to whom the caller may share with, as a grant
	 * does, or to whoever sees the vehicle and knows it already - the caller included.
	 *
	 * @return list<NextFleetRecipient> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the instance has no such account, or none the caller may share with
	 * @throws \OCP\DB\Exception
	 */
	public function add(string $userId, string $vehicleUuid, mixed $recipient): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$recipient = is_string($recipient) ? $this->names->spelled(Access::USER, $recipient) : null;
		// One answer for both, for the reason GrantService::grantee() gives.
		if ($recipient === null || !$this->admits($userId, $vehicle, $recipient, Access::USER)) {
			throw new \InvalidArgumentException('user_id is not an account on this instance');
		}

		// Retried for the reason TripService::record() gives.
		$this->atomicRetry(function () use ($userId, $vehicle, $recipient): void {
			$this->vehicles->hold((int)$vehicle->getId());
			foreach ($this->recipients->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getUserId() === $recipient) {
					return;
				}
			}
			// Asked again under the hold: an account deleted since get() found it was erased off
			// every list already, and this row would wait for whoever takes the uid next.
			if (!$this->users->userExists($recipient)) {
				throw new \InvalidArgumentException('user_id is not an account on this instance');
			}

			$row = new ReminderRecipient();
			$row->setVehicleId((int)$vehicle->getId());
			$row->setUserId($recipient);
			$row->setCreatedBy($userId);
			$this->recipients->insert($row);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * Takes one account off the list, the owner's included; nothing keeps the list from ending up
	 * empty, which sends nothing. Somebody not on it is already off it. What they were sent goes
	 * with them, after the commit, as the sweep sends.
	 *
	 * @return list<NextFleetRecipient> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function remove(string $userId, string $vehicleUuid, string $recipient): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$gone = $recipient;
		$this->atomicRetry(function () use ($vehicle, $recipient, &$gone): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$listed = array_map(
				static fn (ReminderRecipient $row): string => $row->getUserId(),
				$this->recipients->findByVehicle((int)$vehicle->getId()),
			);
			$gone = $this->listedAs($listed, Access::USER, $recipient);
			$this->recipients->deleteByUser((int)$vehicle->getId(), $gone);
		}, $this->db);
		$this->notifications->withdrawFrom((int)$vehicle->getId(), $gone);

		return $this->of($vehicle);
	}

	/**
	 * Who hears of the vehicle's bookings; empty means the owner (BookingNotices).
	 *
	 * @return list<NextFleetBookingRecipient>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function listBooking(string $userId, string $vehicleUuid): array {
		return $this->bookingOf($this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid));
	}

	/**
	 * Puts one account or one group on the booking list, by add()'s rule: whom the owner may share
	 * with, or an account that sees the vehicle. Being on it grants nothing, and only those who see
	 * the vehicle are told. A new row hears the events given, else the vehicle's. Adding one
	 * already on it sets the events given, and without any changes nothing; the rule is not asked
	 * again, so a row the owner could no longer add keeps its events in reach, as removeBooking()
	 * keeps it in reach.
	 *
	 * @param mixed $events a set of Vehicle::NOTICES, or null
	 * @return list<NextFleetBookingRecipient> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the instance has no such account or group, or none the owner may share with, or an event is unknown
	 * @throws \OCP\DB\Exception
	 */
	public function addBooking(string $userId, string $vehicleUuid, mixed $recipient, mixed $type, mixed $events = null): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$type = self::recipientType($type);
		$events = $events === null ? null : array_values(array_intersect(Vehicle::NOTICES, Field::set('events', $events, Vehicle::NOTICES)));
		if (!is_string($recipient)) {
			throw new \InvalidArgumentException('recipient is not an account or group on this instance');
		}

		$this->atomicRetry(function () use ($userId, $vehicle, $recipient, $type, $events): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$rows = array_values(array_filter(
				$this->bookingRecipients->findByVehicle((int)$vehicle->getId()),
				static fn (BookingRecipient $one): bool => $one->type() === $type,
			));
			$listed = $this->listedAs(array_map(static fn (BookingRecipient $one): string => $one->name(), $rows), $type, $recipient);
			foreach ($rows as $one) {
				if ($one->name() === $listed) {
					if ($events !== null) {
						$one->setEvents($events);
						// Read under the hold, so nothing can have raced it.
						$this->bookingRecipients->updateChecked($one, $one->getUpdatedAt());
					}

					return;
				}
			}
			// The existence is asked under the hold, for the reason add() gives.
			$name = $this->names->spelled($type, $recipient);
			if ($name === null || !$this->admits($userId, $vehicle, $name, $type)
				|| ($type === Access::USER ? !$this->users->userExists($name) : !$this->groups->groupExists($name))) {
				throw new \InvalidArgumentException('recipient is not an account or group on this instance');
			}

			$row = new BookingRecipient();
			$row->setVehicleId((int)$vehicle->getId());
			$row->setUserId($type === Access::USER ? $name : null);
			$row->setGroupId($type === Access::GROUP ? $name : null);
			// Read under the hold: the owner may have changed the vehicle's events since reach().
			$row->setEvents($events ?? $this->vehicles->findAnyById((int)$vehicle->getId())->bookingNotices());
			$row->setCreatedBy($userId);
			$this->bookingRecipients->insert($row);
		}, $this->db);

		return $this->bookingOf($vehicle);
	}

	/**
	 * Takes one account or group off the booking list. One not on it is already off it; the
	 * notices it was sent stay, as they told what happened.
	 *
	 * @return list<NextFleetBookingRecipient> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the type is neither user nor group
	 * @throws \OCP\DB\Exception
	 */
	public function removeBooking(string $userId, string $vehicleUuid, string $type, string $recipient): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$type = self::recipientType($type);
		$this->atomicRetry(function () use ($vehicle, $type, $recipient): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$listed = [];
			foreach ($this->bookingRecipients->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->type() === $type) {
					$listed[] = $one->name();
				}
			}
			$this->bookingRecipients->deleteOne((int)$vehicle->getId(), $type, $this->listedAs($listed, $type, $recipient));
		}, $this->db);

		return $this->bookingOf($vehicle);
	}

	/**
	 * The row a name sent to remove(), removeBooking() or addBooking() names: as the list shows
	 * it, else as add() and addBooking() store it. As listed first, since a row an erased account
	 * left can share its uid with a live account in another case.
	 *
	 * @param list<string> $listed
	 * @param Access::USER|Access::GROUP $type
	 */
	private function listedAs(array $listed, string $type, string $name): string {
		return in_array($name, $listed, true) ? $name : ($this->names->spelled($type, $name) ?? $name);
	}

	/**
	 * @return Access::USER|Access::GROUP
	 * @throws \InvalidArgumentException
	 */
	private static function recipientType(mixed $type): string {
		return match ($type) {
			Access::USER => Access::USER,
			Access::GROUP => Access::GROUP,
			default => throw new \InvalidArgumentException('recipient_type is user or group'),
		};
	}

	/**
	 * Takes off every list whoever add() would refuse there now: neither the owner nor whoever
	 * listed them may share with them, nor do they see the vehicle. add() asks it of the caller,
	 * who may be a manager; the row's `created_by` is that caller. For the upgrade repair step
	 * (lib/Repair/StrangerRecipients.php, docs/architecture.md#reminder-engine). Each vehicle
	 * under its hold, as remove() does.
	 *
	 * @return int how many entries went
	 * @throws \OCP\DB\Exception
	 */
	public function dropStrangers(): int {
		$dropped = 0;
		foreach ($this->recipients->findVehicleIds() as $vehicleId) {
			/** @var list<string> $gone */
			$gone = $this->atomicRetry(function () use ($vehicleId): array {
				$this->vehicles->hold($vehicleId);
				try {
					$vehicle = $this->vehicles->findAnyById($vehicleId);
				} catch (DoesNotExistException) {
					// No vehicle is ever deleted for good; an orphan must not fail the upgrade.
					return [];
				}
				$gone = [];
				foreach ($this->recipients->findByVehicle($vehicleId) as $recipient) {
					$uid = $recipient->getUserId();
					if (!$this->sharable->reaches($vehicle->getUserId(), $uid, Access::USER)
						&& !$this->sharable->reaches((string)$recipient->getCreatedBy(), $uid, Access::USER)
						&& !$this->access->may($uid, VehicleAccess::VIEW, $vehicle)) {
						$this->recipients->deleteByUser($vehicleId, $uid);
						$gone[] = $uid;
					}
				}

				return $gone;
			}, $this->db);
			foreach ($gone as $uid) {
				$this->notifications->withdrawFrom($vehicleId, $uid);
			}
			$dropped += count($gone);
		}

		return $dropped;
	}

	/**
	 * The rule add() states, for both lists. A group only by sharing: a group sees nothing itself.
	 *
	 * @param Access::USER|Access::GROUP $type
	 * @throws \OCP\DB\Exception
	 */
	private function admits(string $userId, Vehicle $vehicle, string $name, string $type): bool {
		return $this->sharable->reaches($userId, $name, $type)
			|| ($type === Access::USER && $this->access->may($name, VehicleAccess::VIEW, $vehicle));
	}

	/**
	 * @return list<NextFleetBookingRecipient>
	 * @throws \OCP\DB\Exception
	 */
	private function bookingOf(Vehicle $vehicle): array {
		return array_map(function (BookingRecipient $recipient) use ($vehicle): array {
			$name = $recipient->name();
			$type = $recipient->type();

			return [
				'recipient' => $name,
				'recipient_type' => $type,
				// As of() names one whose account is gone.
				'display_name' => ($type === Access::GROUP ? $this->groups->getDisplayName($name) : $this->users->getDisplayName($name)) ?? $name,
				'events' => $recipient->events($vehicle),
			];
		}, $this->bookingRecipients->findByVehicle((int)$vehicle->getId()));
	}

	/**
	 * @return list<NextFleetRecipient>
	 * @throws \OCP\DB\Exception
	 */
	private function of(Vehicle $vehicle): array {
		return array_map(fn (ReminderRecipient $recipient): array => [
			'user_id' => $recipient->getUserId(),
			// An account deleted since keeps its row, and its id is all there is to show.
			'display_name' => $this->users->getDisplayName($recipient->getUserId()) ?? $recipient->getUserId(),
		], $this->recipients->findByVehicle((int)$vehicle->getId()));
	}
}
