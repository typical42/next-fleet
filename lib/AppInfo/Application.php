<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\AppInfo;

use OCA\NextFleet\Jurisdiction\Generic\ServiceTemplates;
use OCA\NextFleet\Jurisdiction\IServiceTemplates;
use OCA\NextFleet\Notification\Notifier;
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
	 * Controllers, services and mappers are autowired from their constructor types; only an
	 * interface needs telling which class it is. The dashboard widget and the search provider
	 * arrive with the milestones that need them. The reminder job is registered in info.xml.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(IServiceTemplates::class, ServiceTemplates::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
