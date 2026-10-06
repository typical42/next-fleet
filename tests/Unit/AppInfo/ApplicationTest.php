<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\AppInfo;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\UserMigration\FleetMigrator;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	/**
	 * Nextcloud resolves an app by the id in info.xml; the constant is what the code
	 * passes to the server. They disagree and nothing loads.
	 */
	public function testAppIdMatchesInfoXml(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($info, 'appinfo/info.xml is not readable XML');

		$this->assertSame((string)$info->id, Application::APP_ID);
	}

	/**
	 * Nextcloud loads this class by convention and only wires the app's container and
	 * routes if it is the framework's own App. A plain class enables and does nothing.
	 */
	public function testItIsTheAppTheFrameworkExpects(): void {
		$this->assertTrue(is_subclass_of(Application::class, App::class));
		$this->assertTrue(is_subclass_of(Application::class, IBootstrap::class));
	}

	/** Nextcloud's user migration runs the data export, and knows only what is registered. */
	public function testItRegistersTheDataExport(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerUserMigrator')->with(FleetMigrator::class);

		// App's constructor wants a server; register() needs none of it.
		(new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
	}
}
