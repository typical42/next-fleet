<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

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
	public const EDIT = 'edit';
	public const DELETE = 'delete';

	/**
	 * What each role of `fleet_access` covers. A driver reads the same vehicle a viewer does -
	 * what a driver may do beyond that is write Entries, and those tables arrive with M2.
	 *
	 * @var array<string, list<string>>
	 */
	private const ROLES = [
		'manager' => [self::VIEW, self::EDIT, self::DELETE],
		'driver' => [self::VIEW],
		'viewer' => [self::VIEW],
	];

	public function __construct(
		private AccessMapper $grants,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function may(string $userId, string $operation, Vehicle $vehicle): bool {
		if ($vehicle->getUserId() === $userId) {
			return true;
		}

		foreach ($this->grants->findGrants((int)$vehicle->getId(), $userId, $this->groupIdsOf($userId)) as $grant) {
			if (in_array($operation, self::ROLES[$grant->getRole()] ?? [], true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every vehicle this user may look at through a grant. What they own is not in it - that is a
	 * column on the vehicle itself, and asking this table for it would be a second query.
	 *
	 * The roles travel with the query, so the same table decides here as in may(): a row whose
	 * role covers nothing must not put a vehicle in a list that carries its plate, its VIN and
	 * what it cost.
	 *
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function reachableVehicleIds(string $userId): array {
		return $this->grants->findVehicleIds(
			$userId,
			$this->groupIdsOf($userId),
			self::rolesCovering(self::VIEW),
		);
	}

	/**
	 * @return list<string>
	 */
	private static function rolesCovering(string $operation): array {
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
