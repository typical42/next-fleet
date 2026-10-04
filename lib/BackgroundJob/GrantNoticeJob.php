<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\BackgroundJob;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\GrantNotices;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Tells the members of a group of its new grant (GrantNotices::tell()): a group of thousands is
 * cron's to tell, not the owner's request. A grant revoked or a vehicle deleted before it runs
 * tells nobody.
 */
class GrantNoticeJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private VehicleMapper $vehicles,
		private AccessMapper $grants,
		private GrantNotices $notices,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `vehicle` and `grant`, each a uuid
	 * @throws \OCP\DB\Exception
	 */
	protected function run($argument): void {
		/** @var array{vehicle: string, grant: string} $argument */
		try {
			$vehicle = $this->vehicles->findByUuid($argument['vehicle']);
			$grant = $this->grants->findOnVehicle((int)$vehicle->getId(), $argument['grant']);
		} catch (DoesNotExistException) {
			return;
		}
		$this->notices->tellMembers($vehicle, $grant);
	}
}
