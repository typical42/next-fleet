<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/**
 * Whom the admin's sharing settings let a user hand a vehicle to, read as core's own share checks
 * read them. A grant is no share, but it hands over more than most shares do, and a recipient is
 * mailed the plate; the pickers offer only whom core's autocomplete does, and the server holds the
 * same line.
 */
class Sharable {
	public function __construct(
		private IShareManager $sharing,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	/**
	 * @param string $name a user or a group as the instance spells it
	 * @param string $type `user` or `group`
	 */
	public function reaches(string $userId, string $name, string $type): bool {
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
			return in_array($name, $own, true);
		}

		return array_intersect($own, $this->groupIdsOf($name)) !== [];
	}

	/** @return list<string> */
	private function groupIdsOf(string $userId): array {
		$user = $this->users->get($userId);

		return $user === null ? [] : array_values(array_map('strval', $this->groups->getUserGroupIds($user)));
	}
}
