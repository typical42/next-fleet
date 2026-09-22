<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Jurisdiction\Generic\ServiceTemplates;
use OCA\NextFleet\Jurisdiction\IServiceTemplates;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An interface autowires only once `Application::register()` names its class, and the server,
 * not a unit test, runs that registration.
 */
class ContainerTest extends TestCase {
	public function testServiceTemplatesResolveToTheGenericSet(): void {
		$this->assertInstanceOf(ServiceTemplates::class, Server::get(IServiceTemplates::class));
	}
}
