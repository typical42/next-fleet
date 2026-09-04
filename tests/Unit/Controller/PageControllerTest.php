<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\PageController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PageControllerTest extends TestCase {
	private function controller(): PageController {
		return new PageController(Application::APP_ID, $this->createMock(IRequest::class));
	}

	/**
	 * The app id and the template name are how Nextcloud finds templates/main.php. Either
	 * one wrong is a 500 with no other symptom.
	 */
	public function testIndexRendersTheAppsOwnTemplate(): void {
		$response = $this->controller()->index();

		$this->assertSame(Application::APP_ID, $response->getApp());
		$this->assertSame('main', $response->getTemplateName());
	}

	/**
	 * The page belongs to every logged-in user, not to admins. Without the attribute the
	 * app framework rejects everyone else with a 403.
	 */
	public function testIndexIsOpenToOrdinaryUsers(): void {
		$attributes = (new ReflectionMethod(PageController::class, 'index'))->getAttributes();
		$names = array_map(static fn ($attribute) => $attribute->getName(), $attributes);

		$this->assertContains(NoAdminRequired::class, $names);
	}

	/**
	 * A bookmark or a link into the app is a plain GET with no token. Demanding one turns
	 * every entry into the app into a 412.
	 */
	public function testIndexDoesNotDemandACsrfToken(): void {
		$attributes = (new ReflectionMethod(PageController::class, 'index'))->getAttributes();
		$names = array_map(static fn ($attribute) => $attribute->getName(), $attributes);

		$this->assertContains(NoCSRFRequired::class, $names);
	}
}
