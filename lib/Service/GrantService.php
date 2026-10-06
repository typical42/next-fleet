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
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Who else may use a vehicle: the owner grants, re-roles and revokes a user's or a group's role
 * (CONTEXT.md, Vehicle Access), and a grantee reads or leaves their own. What a grant allows is
 * VehicleAccess's to say; this writes the rows it reads, and tells the grantee.
 *
 * @psalm-import-type NextFleetGrant from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetHeld from \OCA\NextFleet\ResponseDefinitions
 */
class GrantService {
	use TTransactional;

	/** What holders() calls the owner: a role no grant carries. */
	private const OWNER = 'owner';

	/** @var array<string, list<string>> by group id, what noteGroup() found */
	private array $members = [];

	public function __construct(
		private AccessMapper $grants,
		private VehicleService $fleet,
		private VehicleAccess $access,
		private VehicleMapper $vehicles,
		private ReminderRecipientMapper $recipients,
		private IUserManager $users,
		private IGroupManager $groups,
		private Sharable $sharable,
		private GrantNotices $notices,
		private NotificationService $reminderNotices,
		private IDBConnection $db,
		private Pending $pending,
		private LoggerInterface $logger,
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
		$once = Once::of(
			$fields,
			$this->grants,
			static fn (Access $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			fn (): array => $this->of($vehicle),
		);

		return $once->run(fn (): array => $this->insert($userId, $vehicle, $fields, $once));
	}

	/**
	 * What grant() writes once it knows the request is no retry.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Access> $once
	 * @return list<NextFleetGrant>
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, Vehicle $vehicle, array $fields, Once $once): array {
		$role = Field::word('role', $fields['role'] ?? null, VehicleAccess::roles());
		[$grantee, $type] = $this->grantee($userId, $fields['grantee'] ?? null, $fields['grantee_type'] ?? null);
		if ($type === Access::USER && $grantee === $vehicle->getUserId()) {
			throw new \InvalidArgumentException('The owner holds every right already');
		}

		// Retried for the reason TripService::record() gives.
		/** @var Access|null $new */
		$new = $this->atomicRetry(function () use ($userId, $vehicle, $grantee, $type, $role, $once): ?Access {
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();
			foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getGrantee() === $grantee && $one->getGranteeType() === $type) {
					$this->recast($one, $role);
					return null;
				}
			}

			$row = new Access();
			$once->stamp($row);
			$row->setVehicleId((int)$vehicle->getId());
			$row->setGrantee($grantee);
			$row->setGranteeType($type);
			$row->setRole($role);
			$row->setCreatedBy($userId);

			return $this->grants->insert($row);
		}, $this->db);
		// After the commit: a notification out before a rollback would go out again.
		if ($new !== null) {
			if (!$this->took($new)) {
				throw new \InvalidArgumentException('grantee is no ' . $type . ' you may grant to');
			}
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
	 * Takes one grant away. Soft-deleted like every row (docs/architecture.md#data-model), so who
	 * had access when stays on record; granting the same grantee again is a new row.
	 *
	 * @return list<NextFleetGrant> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if either the vehicle or the grant on it is not there
	 * @throws \OCP\DB\Exception
	 */
	public function revoke(string $userId, string $vehicleUuid, string $grantUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);

		/** @var list<string> $pruned */
		$pruned = $this->atomicRetry(function () use ($vehicle, $grantUuid): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$grant = $this->grants->findOnVehicle((int)$vehicle->getId(), $grantUuid);
			$saw = $this->seeing($vehicle);
			$this->grants->softDelete($grant, $grant->getUpdatedAt());

			return $this->prune($vehicle, $saw);
		}, $this->db);
		$this->notices->withdraw($grantUuid);
		$this->unremind((int)$vehicle->getId(), $pruned);

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
		/** @var list<string> $pruned */
		[$grant, $pruned] = $this->atomicRetry(function () use ($userId, $vehicle): array {
			$this->vehicles->hold((int)$vehicle->getId());
			// No group ids: only the row in their own name.
			$grant = $this->grants->findGrants((int)$vehicle->getId(), $userId, [])[0]
				?? throw new DoesNotExistException('No grant of your own');
			$saw = $this->seeing($vehicle);
			$this->grants->softDelete($grant, $grant->getUpdatedAt());

			return [$grant, $this->prune($vehicle, $saw)];
		}, $this->db);
		$this->notices->withdraw($grant->getUuid());
		$this->unremind((int)$vehicle->getId(), $pruned);

		return $this->holding($userId, $vehicle);
	}

	/**
	 * Takes every grant off a vehicle whose owner is erased, by revoking's rules. In the caller's
	 * transaction and under its hold, so the vehicle closes with them (ErasureService::erase()).
	 * Whom it prunes needs no withdrawal of its own: the vehicle's close withdraws every notice.
	 *
	 * @return list<string> the grants revoked, whose notices the caller withdraws after its commit
	 * @throws \OCP\DB\Exception
	 */
	public function revokeAll(Vehicle $vehicle): array {
		$saw = $this->seeing($vehicle);
		$revoked = [];
		foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $grant) {
			$this->grants->softDelete($grant, $grant->getUpdatedAt());
			$revoked[] = $grant->getUuid();
		}
		$this->prune($vehicle, $saw);

		return $revoked;
	}

	/**
	 * Notes which of its vehicles' recipients a group about to be deleted holds: once it is gone,
	 * nothing tells them from a recipient who never saw the car, and forgetGroup() needs to.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function noteGroup(string $groupId): void {
		$members = [];
		foreach ($this->grants->findByGroup($groupId) as $grant) {
			foreach ($this->recipients->findByVehicle($grant->getVehicleId()) as $recipient) {
				if ($this->groups->isInGroup($recipient->getUserId(), $groupId)) {
					$members[] = $recipient->getUserId();
				}
			}
		}
		$this->members[$groupId] = array_values(array_unique($members));
	}

	/**
	 * Revokes a deleted group's grants: a group made later under the same id would otherwise
	 * inherit every car the old one reached. Revoking's rules apply, one vehicle per transaction
	 * under its hold. The members noteGroup() found count as having seen the car; without it, every
	 * recipient who no longer sees the car comes off. Run once the group is gone, and pending until
	 * done (docs/architecture.md#data-model, "Both finish, whatever fails").
	 *
	 * @param int|null $since when the group went, for a finish: a grant made or changed later
	 *                        is the owner's for a group made again under the same id
	 * @throws \OCP\DB\Exception
	 */
	public function forgetGroup(string $groupId, ?int $since = null): void {
		$this->pending->begin(Pending::GROUP, $groupId);
		$members = $this->members[$groupId] ?? null;
		unset($this->members[$groupId]);
		foreach ($this->grants->findByGroup($groupId) as $grant) {
			if ($since === null || $grant->getUpdatedAt() <= $since) {
				$this->revokeGone($grant, $members);
			}
		}
		$this->pending->end(Pending::GROUP, $groupId);
	}

	/**
	 * Finishes every group's revokes a failure left pending (PendingJob), a group of that id made again or not.
	 *
	 * @return list<array{id: string, error: \Throwable}> the groups whose revokes failed again, still marked
	 */
	public function finish(): array {
		$failed = [];
		foreach ($this->pending->of(Pending::GROUP) as ['id' => $groupId, 'since' => $since]) {
			try {
				$this->forgetGroup($groupId, $since);
			} catch (\Throwable $e) {
				$this->logger->error('A deleted group\'s pending revokes failed again', ['app' => Application::APP_ID, 'group' => $groupId, 'exception' => $e]);
				$failed[] = ['id' => $groupId, 'error' => $e];
			}
		}

		return $failed;
	}

	/**
	 * Takes a member just out of a group off the recipients of each car the group reached when
	 * they left, unless they still see it another way: no revoke runs, so prune() would never hear
	 * of them. The group's grants stand. One vehicle per transaction under its hold.
	 *
	 * Queued (ForgetMemberJob), so the group may be gone by then. Its grants revoked since
	 * `$removedAt` still count: forgetGroup() counted only who was still a member. A vehicle that
	 * fails leaves the others done; the first failure is thrown after them, so the job runs again.
	 *
	 * @throws \Throwable
	 */
	public function forgetMember(string $groupId, string $userId, int $removedAt): void {
		$failed = null;
		foreach ($this->grants->findByGroup($groupId, $removedAt) as $grant) {
			try {
				/** @var list<string> $pruned */
				$pruned = $this->atomicRetry(function () use ($grant, $userId): array {
					$vehicleId = $grant->getVehicleId();
					$this->vehicles->hold($vehicleId);

					return $this->prune($this->vehicles->findAnyById($vehicleId), [$userId]);
				}, $this->db);
				$this->unremind($grant->getVehicleId(), $pruned);
			} catch (\Throwable $e) {
				$failed ??= $e;
			}
		}
		if ($failed !== null) {
			throw $failed;
		}
	}

	/**
	 * Revokes one grant whose grantee is gone, by revoking's rules, under its vehicle's hold.
	 *
	 * @param ?list<string> $members who saw the vehicle through it though it no longer says so;
	 *                               null for not known, which counts every recipient
	 * @throws \OCP\DB\Exception
	 */
	private function revokeGone(Access $grant, ?array $members): void {
		/** @var list<string> $pruned */
		$pruned = $this->atomicRetry(function () use ($grant, $members): array {
			$vehicleId = $grant->getVehicleId();
			$this->vehicles->hold($vehicleId);
			// Read again under the hold, for a token no owner's revoke has moved since.
			try {
				$grant = $this->grants->findOnVehicle($vehicleId, $grant->getUuid());
			} catch (DoesNotExistException) {
				return [];
			}
			$vehicle = $this->vehicles->findAnyById($vehicleId);
			$saw = $members === null
				? array_map(static fn (ReminderRecipient $recipient): string => $recipient->getUserId(), $this->recipients->findByVehicle($vehicleId))
				: [...$this->seeing($vehicle), ...$members];
			$this->grants->softDelete($grant, $grant->getUpdatedAt());

			return $this->prune($vehicle, $saw);
		}, $this->db);
		$this->notices->withdraw($grant->getUuid());
		$this->unremind($grant->getVehicleId(), $pruned);
	}

	/**
	 * Whether a new grant's grantee still exists now that the row is in. One deleted since
	 * grantee() found it ran its erasure or forgetGroup() before the row existed, and the grant
	 * would wait for whoever takes the name next - so the row goes, for good: nobody was told of
	 * it or saw it. Asked under the vehicle's hold, so a grant of the same name made meanwhile is
	 * answered for too, not removed behind its back. Never erases the grantee: a backend briefly
	 * out of reach answers "no such user" for a live account (docs/architecture.md#data-model).
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function took(Access $grant): bool {
		/** @var list<string>|null $pruned null while the grantee exists */
		$pruned = $this->atomicRetry(function () use ($grant): ?array {
			$vehicleId = $grant->getVehicleId();
			$this->vehicles->hold($vehicleId);
			$exists = $grant->getGranteeType() === Access::USER
				? $this->users->userExists($grant->getGrantee())
				: $this->groups->groupExists($grant->getGrantee());
			if ($exists) {
				return null;
			}
			$vehicle = $this->vehicles->findAnyById($vehicleId);
			$saw = $this->seeing($vehicle);
			$this->grants->discard($grant);

			return $this->prune($vehicle, $saw);
		}, $this->db);
		if ($pruned === null) {
			return true;
		}
		$this->unremind($grant->getVehicleId(), $pruned);

		return false;
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
		$held = ['role' => null, 'groups' => [], 'holders' => []];
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
		// Somebody who just left holds nothing, and is told no more than a stranger.
		if ($held['role'] !== null || $held['groups'] !== []) {
			$held['holders'] = $this->holders($vehicle);
		}

		return $held;
	}

	/**
	 * Who reads the vehicle, as a grantee may know it: whoever they hand a trip's purpose to
	 * (docs/legal.md). Display names only: the one list of people a grantee did not meet through a
	 * row, which carry uids (docs/security.md, accepted risks). An account without a
	 * display name shows its uid as one, as everywhere in Nextcloud. A grantee gone from the
	 * instance reaches nobody, so it is left out rather than shown by the id its row keeps.
	 *
	 * @return list<array{display_name: string, grantee_type: string, role: string}>
	 * @throws \OCP\DB\Exception
	 */
	private function holders(Vehicle $vehicle): array {
		$owner = $this->users->getDisplayName($vehicle->getUserId());
		$holders = $owner === null ? [] : [['display_name' => $owner, 'grantee_type' => Access::USER, 'role' => self::OWNER]];
		foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $grant) {
			$name = $grant->getGranteeType() === Access::GROUP
				? $this->groups->get($grant->getGrantee())?->getDisplayName()
				: $this->users->getDisplayName($grant->getGrantee());
			if ($name !== null) {
				$holders[] = ['display_name' => $name, 'grantee_type' => $grant->getGranteeType(), 'role' => $grant->getRole()];
			}
		}

		return $holders;
	}

	/**
	 * The vehicle's recipients who see it, asked before a change so prune() can tell whom it took
	 * the car from. Asked per recipient, since each may reach it through a group or a second grant
	 * - and a vehicle has a handful of recipients.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	private function seeing(Vehicle $vehicle): array {
		$seeing = [];
		foreach ($this->recipients->findByVehicle((int)$vehicle->getId()) as $recipient) {
			if ($this->access->may($recipient->getUserId(), VehicleAccess::VIEW, $vehicle)) {
				$seeing[] = $recipient->getUserId();
			}
		}

		return $seeing;
	}

	/**
	 * Takes off the vehicle's recipients whom the change took the car from: a reminder would name a
	 * car its link cannot open. Whoever never saw it - a bookkeeper on the owner's list - lost
	 * nothing and stays. In the caller's transaction, so the list and the grants change together.
	 *
	 * @param list<string> $saw what seeing() answered before the change
	 * @return list<string> whom it took off, for unremind() once the caller has committed
	 * @throws \OCP\DB\Exception
	 */
	private function prune(Vehicle $vehicle, array $saw): array {
		$pruned = [];
		foreach (array_unique($saw) as $userId) {
			if (!$this->access->may($userId, VehicleAccess::VIEW, $vehicle)) {
				$this->recipients->deleteByUser((int)$vehicle->getId(), $userId);
				$pruned[] = $userId;
			}
		}

		return $pruned;
	}

	/**
	 * Takes back the reminders prune() took its recipients off: a notice left standing would still
	 * name the car. After the commit, so a rollback leaves them told.
	 *
	 * @param list<string> $pruned
	 * @throws \OCP\DB\Exception
	 */
	private function unremind(int $vehicleId, array $pruned): void {
		foreach ($pruned as $userId) {
			$this->reminderNotices->withdrawFrom($vehicleId, $userId);
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
		if ($grantee === null || !$this->sharable->reaches($userId, $grantee, $type)) {
			throw new \InvalidArgumentException('grantee is no ' . $type . ' you may grant to');
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
