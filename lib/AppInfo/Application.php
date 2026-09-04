<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nextfleet';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	/**
	 * Controllers, services and mappers are autowired from their constructor types, so
	 * nothing is registered here yet. The notifier, the dashboard widget and the search
	 * provider arrive with the milestones that need them.
	 */
	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
