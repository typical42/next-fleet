<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\IUserManager;

/**
 * Who entered what, for the timeline and the logbook to say on a vehicle others use. Read off
 * `created_by`, the provenance every Entry already carries - there is no driver column.
 */
class EnteredBy {
	public function __construct(
		private AccessMapper $access,
		private IUserManager $users,
	) {
	}

	/**
	 * The display name of each author, by uid, or null on a vehicle nobody else was ever given
	 * access to: there everything is the owner's, and naming them on every row says nothing. An
	 * author with no account - an erased one's pseudonym (docs/adr/0008-erasing-a-driver-pseudonymises.md)
	 * - reads as the uid the row carries.
	 *
	 * @param list<string> $uids
	 * @return ?array<string, string>
	 * @throws \OCP\DB\Exception
	 */
	public function names(Vehicle $vehicle, array $uids): ?array {
		if (!$this->access->everGranted((int)$vehicle->getId())) {
			return null;
		}

		$names = [];
		foreach (array_unique($uids) as $uid) {
			$names[$uid] = $this->users->getDisplayName($uid) ?? $uid;
		}

		return $names;
	}
}
