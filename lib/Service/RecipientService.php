<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Who a vehicle's reminders go to (docs/architecture.md#reminder-engine). Reading the list takes
 * EDIT as writing it does: who gets told is the managers' business, and the sheet shows the list
 * to whoever may change it and to nobody else.
 *
 * @psalm-import-type NextFleetRecipient from \OCA\NextFleet\ResponseDefinitions
 */
class RecipientService {
	use TTransactional;

	public function __construct(
		private ReminderRecipientMapper $recipients,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IUserManager $users,
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
		// The backend finds an account in any case; stored as sent, `Admin` beside `admin` would be
		// one person told twice.
		$recipient = is_string($recipient) ? $this->users->get($recipient)?->getUID() : null;
		// One answer for both, for the reason GrantService::grantee() gives.
		if ($recipient === null || (!$this->sharable->reaches($userId, $recipient, Access::USER)
			&& !$this->access->may($recipient, VehicleAccess::VIEW, $vehicle))) {
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
			// The row as the list shows it, else the spelling add() stores. A row an erased account
			// left can share its uid with a live account in another case.
			$gone = in_array($recipient, $listed, true)
				? $recipient
				: ($this->users->get($recipient)?->getUID() ?? $recipient);
			$this->recipients->deleteByUser((int)$vehicle->getId(), $gone);
		}, $this->db);
		$this->notifications->withdrawFrom((int)$vehicle->getId(), $gone);

		return $this->of($vehicle);
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
