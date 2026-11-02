<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Users and groups as the instance knows them, for every service. One place, because a row written
 * in one spelling and looked up in another is a delete that removes nothing.
 */
class Names {
	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	/**
	 * The name as the instance spells it, or null if it has no such user or group. The backends
	 * find a name in any case; stored as sent, `Anna` beside `anna` would be one person twice.
	 *
	 * @param Access::USER|Access::GROUP $type
	 */
	public function spelled(string $type, string $name): ?string {
		return $type === Access::GROUP ? $this->groups->get($name)?->getGID() : $this->users->get($name)?->getUID();
	}

	/**
	 * A group's members as it has them now; a group deleted since has none.
	 *
	 * @return list<string>
	 */
	public function members(string $groupId): array {
		return array_values(array_map(static fn (IUser $user): string => $user->getUID(), $this->groups->get($groupId)?->getUsers() ?? []));
	}
}
