<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Starts every client's sync over (SyncService::EPOCH), for a change a cursor cannot see: rows
 * renamed without a new `updated_at`, or a vehicle whose reader now reads more of it.
 */
class SyncEpoch {
	public function __construct(
		private IAppConfig $config,
		private ISecureRandom $random,
	) {
	}

	public function reset(): void {
		$this->config->setValueString(Application::APP_ID, SyncService::EPOCH, $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC));
	}
}
