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
	 * @return list<string>
	 */
	private function groupIdsOf(string $userId): array {
		$user = $this->users->get($userId);

		return $user === null ? [] : array_values($this->groups->getUserGroupIds($user));
	}
}
