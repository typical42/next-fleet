<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who else may use a vehicle: the owner grants a user or a group a role, changes it and revokes
 * it (CONTEXT.md, Vehicle Access). Every route takes `own`. What a grant allows is
 * VehicleAccess's to say; this only writes the rows it reads.
 */
class GrantService {
	use TTransactional;

	public function __construct(
		private AccessMapper $grants,
		private VehicleService $fleet,
		private VehicleAccess $access,
		private VehicleMapper $vehicles,
		private ReminderRecipientMapper $recipients,
		private IUserManager $users,
		private IGroupManager $groups,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		return $this->of($this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid));
	}

	/**
	 * Gives a user or a group a role on the vehicle. One row per grantee: granting somebody who
	 * already holds a grant changes its role, so no second row is left for "strongest wins" to
	 * quietly pick between.
	 *
	 * @param array<string, mixed> $fields `grantee`, `grantee_type` (`user` or `group`) and `role`
	 * @return list<array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the grantee does not exist, is the owner, or the role is none
	 * @throws \OCP\DB\Exception
	 */
	public function grant(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$role = Field::word('role', $fields['role'] ?? null, VehicleAccess::roles());
		[$grantee, $type] = $this->grantee($fields['grantee'] ?? null, $fields['grantee_type'] ?? null);
		if ($type === Access::USER && $grantee === $vehicle->getUserId()) {
			throw new \InvalidArgumentException('The owner holds every right already');
		}

		// Retried for the reason TripService::record() gives.
		$this->atomicRetry(function () use ($userId, $vehicle, $grantee, $type, $role): void {
			$this->vehicles->hold((int)$vehicle->getId());
			foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getGrantee() === $grantee && $one->getGranteeType() === $type) {
					$this->recast($one, $role);
					return;
				}
			}

			$row = new Access();
			$row->setVehicleId((int)$vehicle->getId());
			$row->setGrantee($grantee);
			$row->setGranteeType($type);
			$row->setRole($role);
			$row->setCreatedBy($userId);
			$this->grants->insert($row);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * A new role on one grant. Every role covers `view`, so a change takes nobody off the
	 * vehicle and nobody off its recipients.
	 *
	 * @param array<string, mixed> $fields `role`
	 * @return list<array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if either the vehicle or the grant on it is not there
	 * @throws \InvalidArgumentException if the role is none
	 * @throws \OCP\DB\Exception
	 */
	public function change(string $userId, string $vehicleUuid, string $grantUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$role = Field::word('role', $fields['role'] ?? null, VehicleAccess::roles());

		$this->atomicRetry(function () use ($vehicle, $grantUuid, $role): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$this->recast($this->grants->findOnVehicle((int)$vehicle->getId(), $grantUuid), $role);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * Takes one grant away. The row is soft-deleted like every other (docs/architecture.md), so
	 * who had access when stays on record; granting the same grantee again is a new row.
	 *
	 * @return list<array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if either the vehicle or the grant on it is not there
	 * @throws \OCP\DB\Exception
	 */
	public function revoke(string $userId, string $vehicleUuid, string $grantUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);

		$this->atomicRetry(function () use ($vehicle, $grantUuid): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$grant = $this->grants->findOnVehicle((int)$vehicle->getId(), $grantUuid);
			$this->grants->softDelete($grant, $grant->getUpdatedAt());
			$this->prune($vehicle);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * Takes off the vehicle's recipients everyone who no longer sees it: a reminder would name a
	 * car its link cannot open. Asked per recipient, since whoever is left may still reach it
	 * through a group or a second grant - and a vehicle has a handful of recipients. In the
	 * caller's transaction, so the list and the grants change together.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function prune(Vehicle $vehicle): void {
		foreach ($this->recipients->findByVehicle((int)$vehicle->getId()) as $recipient) {
			if (!$this->access->may($recipient->getUserId(), VehicleAccess::VIEW, $vehicle)) {
				$this->recipients->deleteByUser((int)$vehicle->getId(), $recipient->getUserId());
			}
		}
	}

	/**
	 * A user or a group as the instance spells it. The backends find a name in any case, and a
	 * row stored as sent would let `Anna` and `anna` be two grants on one person.
	 *
	 * @return array{string, string} the grantee and its type
	 * @throws \InvalidArgumentException
	 */
	private function grantee(mixed $name, mixed $type): array {
		$type = Field::word('grantee_type', $type, [Access::USER, Access::GROUP]);
		if (!is_string($name) || $name === '') {
			throw new \InvalidArgumentException('grantee names a user or a group');
		}
		$grantee = $type === Access::USER ? $this->users->get($name)?->getUID() : $this->groups->get($name)?->getGID();
		if ($grantee === null) {
			throw new \InvalidArgumentException('grantee is no ' . $type . ' on this instance');
		}

		return [$grantee, $type];
	}

	/**
	 * A new role on a grant the caller holds the vehicle for. Checked against the token the row
	 * was just read with: the vehicle is held, so nobody moved it since.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function recast(Access $grant, string $role): void {
		if ($grant->getRole() === $role) {
			return;
		}
		$grant->setRole($role);
		$this->grants->updateChecked($grant, $grant->getUpdatedAt());
	}

	/**
	 * @return list<array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}>
	 * @throws \OCP\DB\Exception
	 */
	private function of(Vehicle $vehicle): array {
		return array_map(fn (Access $grant): array => [
			'uuid' => $grant->getUuid(),
			'grantee' => $grant->getGrantee(),
			'grantee_type' => $grant->getGranteeType(),
			'display_name' => $this->displayName($grant),
			'role' => $grant->getRole(),
		], $this->grants->findByVehicle((int)$vehicle->getId()));
	}

	/** A grantee deleted since keeps its row, and its id is all there is to show. */
	private function displayName(Access $grant): string {
		$name = $grant->getGranteeType() === Access::GROUP
			? $this->groups->get($grant->getGrantee())?->getDisplayName()
			: $this->users->getDisplayName($grant->getGrantee());

		return $name ?? $grant->getGrantee();
	}
}
