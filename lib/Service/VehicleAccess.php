<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The one place that decides who reaches a vehicle
 * (docs/adr/0001-own-access-table.md).
 */
class VehicleAccess {
	public const VIEW = 'view';
	/** Add an Entry, and change one you entered (docs/architecture.md#nextcloud-integration). */
	public const LOG = 'log';
	public const EDIT = 'edit';
	/** Delete anybody's Entry. Not the vehicle: that is `own`. */
	public const DELETE = 'delete';
	/** The owner's alone: access, and whether the vehicle exists. */
	public const OWN = 'own';
	/**
	 * See what the vehicle costs: every amount, every sum, and what it was bought for. Without it
	 * a reader still sees the amounts they entered (mayCost()).
	 */
	public const COSTS = 'costs';

	/**
	 * Every operation, in the order an answer lists them, so the same caller always gets the
	 * same list.
	 *
	 * @var list<string>
	 */
	private const OPERATIONS = [self::VIEW, self::LOG, self::EDIT, self::DELETE, self::OWN, self::COSTS];

	/**
	 * What each role of `fleet_access` covers. No role covers `own`: the car is the owner's, so
	 * a manager can neither grant nor delete it, and cannot promote themselves to either. The
	 * manager handles the costs; the other roles see them only while the vehicle shares them
	 * (shared()).
	 *
	 * @var array<string, list<string>>
	 */
	private const ROLES = [
		'manager' => [self::VIEW, self::LOG, self::EDIT, self::DELETE, self::COSTS],
		'driver' => [self::VIEW, self::LOG],
		'viewer' => [self::VIEW],
	];

	public function __construct(
		private AccessMapper $grants,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	/**
	 * The words a grant's role takes: what a grant may carry is what this class can read.
	 *
	 * @return list<string>
	 */
	public static function roles(): array {
		return array_keys(self::ROLES);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function may(string $userId, string $operation, Vehicle $vehicle): bool {
		return in_array($operation, $this->operations($userId, $vehicle), true);
	}

	/**
	 * Whether the user may change one Entry with `$operation` - `edit`, or `delete` for a delete, a
	 * void and their undo: anybody's with the operation itself, one they entered (`created_by`)
	 * with `log`. Read off what operations() left on the vehicle, so it asks nothing twice; a
	 * vehicle that never passed the gate carries nothing and is refused.
	 */
	public function mayChange(string $userId, string $operation, Vehicle $vehicle, ?string $createdBy): bool {
		if (self::erased($userId)) {
			return false;
		}
		$held = $vehicle->getMay();

		return in_array($operation, $held, true)
			|| (in_array(self::LOG, $held, true) && $createdBy === $userId);
	}

	/**
	 * Whether the user may change, cancel, check out or check in one Booking: anybody's with `edit`,
	 * their own with `log` (CONTEXT.md, Pool). mayChange()'s rule with the booker in the author's
	 * place, so a booking needs no operation of its own.
	 */
	public function mayBooking(string $userId, Vehicle $vehicle, string $bookerId): bool {
		return $this->mayChange($userId, self::EDIT, $vehicle, $bookerId);
	}

	/** Whether the user answers the bookings of a vehicle that asks for approval. */
	public function mayApprove(string $userId, Vehicle $vehicle): bool {
		return $this->oversees($userId, $vehicle);
	}

	/** Whether the user sees every driver's figures on the Statistics screen, not only their own. */
	public function mayCompareDrivers(string $userId, Vehicle $vehicle): bool {
		return $this->oversees($userId, $vehicle);
	}

	/**
	 * The owner and the managers: who change anybody's trip and booking anyway, so answering one
	 * or comparing drivers needs no operation of its own.
	 */
	private function oversees(string $userId, Vehicle $vehicle): bool {
		return $this->mayChange($userId, self::EDIT, $vehicle, null);
	}

	/**
	 * Whether the user sees the amount on one Entry: anybody's with `costs`, one they entered
	 * whatever their role says now. Read off the gate's answer, as mayChange() is.
	 */
	public function mayCost(string $userId, Vehicle $vehicle, ?string $createdBy): bool {
		if (self::erased($userId)) {
			return false;
		}
		$held = $vehicle->getMay();

		return in_array(self::COSTS, $held, true) || ($held !== [] && $createdBy === $userId);
	}

	/**
	 * Everything the user may do on one vehicle - what the vehicle JSON carries as `may`, so the
	 * screen hides by the same answer the server refuses by. The owner needs no grant query.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	public function operations(string $userId, Vehicle $vehicle): array {
		if (self::erased($userId)) {
			return [];
		}
		if ($vehicle->getUserId() === $userId) {
			return self::OPERATIONS;
		}

		$roles = array_map(
			static fn (Access $grant): string => $grant->getRole(),
			$this->grants->findGrants((int)$vehicle->getId(), $userId, $this->groupIdsOf($userId)),
		);

		return self::shared(self::covered($roles), $vehicle);
	}

	/**
	 * Every vehicle this user may look at through a grant, by id, with what their roles cover on
	 * it; what the vehicle's own switch adds is listed()'s. What they own is not in it: that is a
	 * column on the vehicle itself.
	 *
	 * The roles travel with the query, so the same table decides here as in may(): a row whose
	 * role covers nothing must not list a vehicle with its plate, its VIN and what it cost.
	 *
	 * @return array<int, list<string>>
	 * @throws \OCP\DB\Exception
	 */
	public function reachable(string $userId): array {
		if (self::erased($userId)) {
			return [];
		}

		return array_map(
			self::covered(...),
			$this->grants->findReachable($userId, $this->groupIdsOf($userId), self::rolesCovering(self::VIEW)),
		);
	}

	/**
	 * operations() for a vehicle in a list, answered from what reachable() found for it rather
	 * than one query per row.
	 *
	 * @param array<int, list<string>> $reachable
	 * @return list<string>
	 */
	public function listed(string $userId, Vehicle $vehicle, array $reachable): array {
		if (self::erased($userId)) {
			return [];
		}
		if ($vehicle->getUserId() === $userId) {
			return self::OPERATIONS;
		}

		return self::shared($reachable[(int)$vehicle->getId()] ?? [], $vehicle);
	}

	/**
	 * What a grant holds once the vehicle has its say: `costs` to every role while the owner
	 * shares them. Only to a grant that holds something, so the switch opens nothing by itself.
	 *
	 * @param list<string> $held
	 * @return list<string>
	 */
	private static function shared(array $held, Vehicle $vehicle): array {
		if ($held === [] || !$vehicle->sharesCosts()) {
			return $held;
		}

		return array_values(array_intersect(self::OPERATIONS, [...$held, self::COSTS]));
	}

	/**
	 * What a set of rows adds up to. Only ever the union: the strongest row wins and no row
	 * narrows another.
	 *
	 * @param list<string> $roles
	 * @return list<string>
	 */
	private static function covered(array $roles): array {
		$held = array_merge([], ...array_map(static fn (string $role): array => self::ROLES[$role] ?? [], $roles));

		return array_values(array_intersect(self::OPERATIONS, $held));
	}

	/**
	 * A pseudonym is no account, whatever rows still name it: no session carries one, so a caller
	 * handing one in took it off a row, and it must open nothing
	 * (docs/adr/0008-erasing-a-driver-pseudonymises.md).
	 */
	private static function erased(string $userId): bool {
		return str_starts_with($userId, ErasureService::PREFIX);
	}

	/**
	 * The roles whose grant covers `$operation`.
	 *
	 * @return list<string>
	 */
	public static function rolesCovering(string $operation): array {
		return array_values(array_keys(array_filter(
			self::ROLES,
			static fn (array $operations): bool => in_array($operation, $operations, true),
		)));
	}

	/**
	 * @return list<string>
	 */
	private function groupIdsOf(string $userId): array {
		$user = $this->users->get($userId);

		return $user === null ? [] : array_values($this->groups->getUserGroupIds($user));
	}
}
