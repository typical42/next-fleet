<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Repair;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\LookalikeNotices;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * A repair step, not a migration: the schema stays, rows that already exist change
 * (ErasureService::renameOld()). Outside lib/Migration/, which Nextcloud reads as migrations only.
 */
class ErasedPseudonyms implements IRepairStep {
	public function __construct(
		private ErasureService $erasure,
		private LoggerInterface $logger,
		private LookalikeNotices $notices,
	) {
	}

	public function getName(): string {
		return 'Rename the pseudonyms of erased accounts so no new account can take them';
	}

	public function run(IOutput $output): void {
		['renamed' => $renamed, 'kept' => $kept] = $this->erasure->renameOld();
		if ($renamed > 0) {
			$output->info('Renamed ' . $renamed . ' pseudonyms');
		}
		foreach ($kept as $account) {
			$output->warning('Kept the account ' . $account . ': it is named like an erased driver. If it was made to take their rows, delete it, which erases them; otherwise nothing is wrong.');
			$this->logger->warning('Kept the account {account}: it is named like an erased driver', ['app' => Application::APP_ID, 'account' => $account]);
			$this->notices->tell($account);
		}
	}
}
