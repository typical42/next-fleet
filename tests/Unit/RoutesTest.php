<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use OCA\NextFleet\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RoutesTest extends TestCase {
	/**
	 * The route table is data, so nothing but a request checks that its `controller#method`
	 * still names something the framework can dispatch to. A rename in lib/ — or a method
	 * that is no longer public — is otherwise silent until the page 500s in a browser.
	 */
	public function testEveryRouteNamesADispatchableControllerMethod(): void {
		$routes = require __DIR__ . '/../../appinfo/routes.php';

		$this->assertNotEmpty($routes['routes'], 'the app serves no web route');

		foreach ($routes['routes'] as $route) {
			[$controller, $method] = explode('#', $route['name']);
			$class = 'OCA\\NextFleet\\Controller\\' . self::camelCase(ucfirst($controller)) . 'Controller';
			$action = self::camelCase($method);

			$this->assertTrue(
				method_exists($class, $action),
				sprintf('route %s points at %s::%s, which does not exist', $route['url'], $class, $action),
			);
			$this->assertTrue(
				(new ReflectionMethod($class, $action))->isPublic(),
				sprintf('route %s points at %s::%s, which the framework cannot call', $route['url'], $class, $action),
			);
		}
	}

	/**
	 * How Nextcloud itself reads a route name: `vehicle_access` becomes
	 * `VehicleAccessController`, `soft_delete` becomes `softDelete`.
	 */
	private static function camelCase(string $name): string {
		return (string)preg_replace_callback(
			'/_[a-z]?/',
			static fn (array $match): string => strtoupper(ltrim($match[0], '_')),
			$name,
		);
	}

	/**
	 * The app's entry in the Nextcloud app menu links to the front page of its own route
	 * namespace. Without this route the menu entry is a dead link.
	 */
	public function testTheFrontPageIsRouted(): void {
		$routes = require __DIR__ . '/../../appinfo/routes.php';

		$this->assertContains(
			['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
			$routes['routes'],
		);
	}

	/**
	 * The app menu entry is the only way into the app, and info.xml names its target as
	 * `<app>.<controller>.<method>` — a spelling nothing but a click checks.
	 */
	public function testTheAppMenuEntryPointsAtADeclaredRoute(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$this->assertNotFalse($info);

		$navigations = $info->navigations->navigation;
		$this->assertNotEmpty($navigations, 'the app has no entry in the Nextcloud app menu');

		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$declared = array_map(
			static fn (array $route): string => Application::APP_ID . '.' . str_replace('#', '.', $route['name']),
			$routes['routes'],
		);

		foreach ($navigations as $navigation) {
			$this->assertContains((string)$navigation->route, $declared);
		}
	}
}
