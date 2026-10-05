<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Repair;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\RecipientService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Takes off the reminder lists whom 0.3.1's RecipientService::add() would refuse there
 * (RecipientService::dropStrangers()). Only on the upgrade from before 0.3.1: Nextcloud runs a
 * post-migration step before it stores the new version, and on every `occ maintenance:repair`
 * too, where the same sweep would silently drop people after an admin tightened sharing.
 */
class StrangerRecipients implements IRepairStep {
	public function __construct(
		private RecipientService $recipients,
		private IAppConfig $config,
	) {
	}

	public function getName(): string {
		return 'Take off the reminder lists whom nobody may share the vehicle with';
	}

	public function run(IOutput $output): void {
		$installed = $this->config->getValueString(Application::APP_ID, 'installed_version');
		// Empty on a first install, which has no list to sweep.
		if ($installed === '' || version_compare($installed, '0.3.1', '>=')) {
			return;
		}
		$dropped = $this->recipients->dropStrangers();
		if ($dropped > 0) {
			$output->info('Took ' . $dropped . ' recipients off reminder lists: neither the owner nor whoever listed them may share the vehicle with them, and they do not see it');
		}
	}
}
