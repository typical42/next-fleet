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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/**
 * Who else may use a vehicle: the owner grants a user or a group a role, changes it and revokes
 * it (CONTEXT.md, Vehicle Access). Those routes take `own`; a grantee's own two - what they hold,
 * and leaving it - take `view`. What a grant allows is VehicleAccess's to say; this writes the
 * rows it reads, and tells the grantee.
 *
 * @psalm-import-type NextFleetGrant from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetHeld from \OCA\NextFleet\ResponseDefinitions
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
		private IShareManager $sharing,
		private GrantNotices $notices,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<NextFleetGrant>
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
	 * @return list<NextFleetGrant> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the grantee does not exist, is the owner, or the role is none
	 * @throws \OCP\DB\Exception
	 */
	public function grant(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$role = Field::word('role', $fields['role'] ?? null, VehicleAccess::roles());
		[$grantee, $type] = $this->grantee($userId, $fields['grantee'] ?? null, $fields['grantee_type'] ?? null);
		if ($type === Access::USER && $grantee === $vehicle->getUserId()) {
			throw new \InvalidArgumentException('The owner holds every right already');
		}

		// Retried for the reason TripService::record() gives.
		/** @var Access|null $new */
		$new = $this->atomicRetry(function () use ($userId, $vehicle, $grantee, $type, $role): ?Access {
			$this->vehicles->hold((int)$vehicle->getId());
			foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getGrantee() === $grantee && $one->getGranteeType() === $type) {
					$this->recast($one, $role);
					return null;
				}
			}

			$row = new Access();
			$row->setVehicleId((int)$vehicle->getId());
			$row->setGrantee($grantee);
			$row->setGranteeType($type);
			$row->setRole($role);
			$row->setCreatedBy($userId);

			return $this->grants->insert($row);
		}, $this->db);
		// After the commit: a notification out before a rollback would go out again.
		if ($new !== null) {
			$this->notices->tell($vehicle, $new);
		}

		return $this->of($vehicle);
	}

	/**
	 * A new role on one grant. Every role covers `view`, so a change takes nobody off the
	 * vehicle and nobody off its recipients.
	 *
	 * @param array<string, mixed> $fields `role`
	 * @return list<NextFleetGrant> the list as it now stands
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
	 * @return list<NextFleetGrant> the list as it now stands
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
		$this->notices->withdraw($grantUuid);

		return $this->of($vehicle);
	}

	/**
	 * Gives back the caller's own grant: the row in their name, never a group's. A personal
	 * opt-out from a group would be a weaker row beating a stronger one, so a group stays the
	 * owner's to change (CONTEXT.md, Vehicle Access). Revoking's rules apply.
	 *
	 * @return NextFleetHeld what they still hold
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if no grant names them, the owner included
	 * @throws \OCP\DB\Exception
	 */
	public function leave(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);

		/** @var Access $grant */
		$grant = $this->atomicRetry(function () use ($userId, $vehicle): Access {
			$this->vehicles->hold((int)$vehicle->getId());
			// No group ids: only the row in their own name.
			$grant = $this->grants->findGrants((int)$vehicle->getId(), $userId, [])[0]
				?? throw new DoesNotExistException('No grant of your own');
			$this->grants->softDelete($grant, $grant->getUpdatedAt());
			$this->prune($vehicle);

			return $grant;
		}, $this->db);
		$this->notices->withdraw($grant->getUuid());

		return $this->holding($userId, $vehicle);
	}

	/**
	 * Revokes a deleted group's grants: a group made later under the same id would otherwise
	 * inherit every car the old one reached. Revoking's rules apply, one vehicle per transaction
	 * under its hold. Run once the group is gone, so its former members reach nothing through it
	 * and prune() sees that.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function forgetGroup(string $groupId): void {
		foreach ($this->grants->findByGroup($groupId) as $grant) {
			$this->atomicRetry(function () use ($grant): void {
				$vehicleId = $grant->getVehicleId();
				$this->vehicles->hold($vehicleId);
				// Read again under the hold, for a token no owner's revoke has moved since.
				try {
					$grant = $this->grants->findOnVehicle($vehicleId, $grant->getUuid());
				} catch (DoesNotExistException) {
					return;
				}
				$this->grants->softDelete($grant, $grant->getUpdatedAt());
				$this->prune($this->vehicles->findAnyById($vehicleId));
			}, $this->db);
			$this->notices->withdraw($grant->getUuid());
		}
	}

	/**
	 * What the caller holds through grants: their own role, which they may leave, and each group
	 * that reaches the vehicle with its role, which only the owner can change. The owner holds no
	 * grant, so nothing - the screen offers them neither.
	 *
	 * @return NextFleetHeld
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function held(string $userId, string $vehicleUuid): array {
		return $this->holding($userId, $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid));
	}

	/**
	 * @return NextFleetHeld
	 * @throws \OCP\DB\Exception
	 */
	private function holding(string $userId, Vehicle $vehicle): array {
		$held = ['role' => null, 'groups' => []];
		if ($vehicle->getUserId() === $userId) {
			return $held;
		}

		$user = $this->users->get($userId);
		$groupIds = $user === null ? [] : array_values($this->groups->getUserGroupIds($user));
		foreach ($this->grants->findGrants((int)$vehicle->getId(), $userId, $groupIds) as $grant) {
			if ($grant->getGranteeType() === Access::USER) {
				$held['role'] = $grant->getRole();
			} else {
				$held['groups'][] = ['grantee' => $grant->getGrantee(), 'display_name' => $this->displayName($grant), 'role' => $grant->getRole()];
			}
		}

		return $held;
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
	private function grantee(string $userId, mixed $name, mixed $type): array {
		$type = Field::word('grantee_type', $type, [Access::USER, Access::GROUP]);
		if (!is_string($name) || $name === '') {
			throw new \InvalidArgumentException('grantee names a user or a group');
		}
		$grantee = $type === Access::USER ? $this->users->get($name)?->getUID() : $this->groups->get($name)?->getGID();
		// One answer for both: under "members only", whether somebody outside the owner's groups
		// exists is not the owner's to learn.
		if ($grantee === null || !$this->sharable($userId, $grantee, $type)) {
			throw new \InvalidArgumentException('grantee is no ' . $type . ' you may grant to');
		}

		return [$grantee, $type];
	}

	/**
	 * Whether the admin's sharing settings let the owner reach this grantee, read as core's own
	 * share checks read them. A grant is no share, but it hands over more than most shares do, and
	 * the picker offers only whom core's autocomplete does: the server holds the same line.
	 */
	private function sharable(string $userId, string $grantee, string $type): bool {
		if (!$this->sharing->shareApiEnabled() || $this->sharing->sharingDisabledForUser($userId)) {
			return false;
		}
		if ($type === Access::GROUP && !$this->sharing->allowGroupSharing()) {
			return false;
		}
		if (!$this->sharing->shareWithGroupMembersOnly()) {
			return true;
		}
		$exempt = $this->sharing->shareWithGroupMembersOnlyExcludeGroupsList();
		$own = array_diff($this->groupIdsOf($userId), $exempt);
		if ($type === Access::GROUP) {
			return in_array($grantee, $own, true);
		}

		return array_intersect($own, $this->groupIdsOf($grantee)) !== [];
	}

	/** @return list<string> */
	private function groupIdsOf(string $userId): array {
		$user = $this->users->get($userId);

		return $user === null ? [] : array_values(array_map('strval', $this->groups->getUserGroupIds($user)));
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
	 * @return list<NextFleetGrant>
	 * @throws \OCP\DB\Exception
	 */
	private function of(Vehicle $vehicle): array {
		return $this->wired($this->grants->findByVehicle((int)$vehicle->getId()));
	}

	/**
	 * Grants in their wire form, each grantee by name.
	 *
	 * @param list<Access> $grants
	 * @return list<NextFleetGrant>
	 */
	public function wired(array $grants): array {
		return array_map(fn (Access $grant): array => [
			'uuid' => $grant->getUuid(),
			'grantee' => $grant->getGrantee(),
			'grantee_type' => $grant->getGranteeType(),
			'display_name' => $this->displayName($grant),
			'role' => $grant->getRole(),
		], $grants);
	}

	/** A grantee deleted since keeps its row, and its id is all there is to show. */
	private function displayName(Access $grant): string {
		$name = $grant->getGranteeType() === Access::GROUP
			? $this->groups->get($grant->getGrantee())?->getDisplayName()
			: $this->users->getDisplayName($grant->getGrantee());

		return $name ?? $grant->getGrantee();
	}
}
