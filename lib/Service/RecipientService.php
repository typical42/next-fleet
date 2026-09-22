<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Who a vehicle's reminders go to (docs/architecture.md#reminder-engine). Reading the list takes
 * EDIT as writing it does: who gets told is the managers' business, and the sheet shows the list
 * to whoever may change it and to nobody else.
 */
class RecipientService {
	use TTransactional;

	public function __construct(
		private ReminderRecipientMapper $recipients,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IUserManager $users,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array{user_id: string, display_name: string}>
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
	 * on it changes nothing.
	 *
	 * @return list<array{user_id: string, display_name: string}> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the instance has no such account
	 * @throws \OCP\DB\Exception
	 */
	public function add(string $userId, string $vehicleUuid, mixed $recipient): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$account = is_string($recipient) ? $this->users->get($recipient) : null;
		if ($account === null) {
			throw new \InvalidArgumentException('user_id is not an account on this instance');
		}
		// The backend finds an account in any case; stored as sent, `Admin` beside `admin` would be
		// one person told twice.
		$recipient = $account->getUID();

		// Retried for the reason TripService::record() gives.
		$this->atomicRetry(function () use ($userId, $vehicle, $recipient): void {
			$this->vehicles->hold((int)$vehicle->getId());
			foreach ($this->recipients->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getUserId() === $recipient) {
					return;
				}
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
	 * empty, which sends nothing. Somebody not on it is already off it.
	 *
	 * @return list<array{user_id: string, display_name: string}> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function remove(string $userId, string $vehicleUuid, string $recipient): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$this->atomicRetry(function () use ($vehicle, $recipient): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$this->recipients->deleteByUser((int)$vehicle->getId(), $recipient);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * @return list<array{user_id: string, display_name: string}>
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
