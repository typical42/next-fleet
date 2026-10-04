<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\Tests\Unit\OcsRoutesTest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What the framework checks before a controller runs is stated on the method, so a method added
 * without it is open in a way nobody chose (docs/security.md#authorization). Every action is for
 * any user, none for a visitor, and only a navigation - the page and the downloads - goes without
 * a CSRF token.
 */
class ControllerAttributesTest extends TestCase {
	/** The page itself, and the downloads a browser opens by navigating, which carries no token. */
	private const NAVIGATED = ['page#index', ...OcsRoutesTest::DOWNLOADS];

	/**
	 * Every action of every controller, by the route name it answers for: `Ocs\Vehicle#index` for
	 * an OCS one.
	 *
	 * @return \Generator<string, array{string, ReflectionMethod}>
	 */
	public static function actions(): \Generator {
		$files = [...(glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: []), ...(glob(__DIR__ . '/../../../lib/Controller/Ocs/*Controller.php') ?: [])];
		foreach ($files as $file) {
			$ocs = basename(dirname($file)) === 'Ocs';
			$short = basename($file, '.php');
			$class = 'OCA\\NextFleet\\Controller\\' . ($ocs ? 'Ocs\\' : '') . $short;
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() || $method->isStatic()) {
					continue;
				}
				$name = ($ocs ? 'Ocs\\' . substr($short, 0, -strlen('Controller')) : lcfirst(substr($short, 0, -strlen('Controller')))) . '#' . $method->getName();
				yield $name => [$name, $method];
			}
		}
	}

	public function testItFindsTheActions(): void {
		$names = array_keys(iterator_to_array(self::actions()));

		$this->assertContains('vehicle#update', $names);
		$this->assertContains('Ocs\\Sync#index', $names);
		$this->assertContains('page#index', $names);
	}

	/** @dataProvider actions */
	public function testEveryActionIsForAnyUserAndNoVisitor(string $name, ReflectionMethod $method): void {
		$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class), $name . ' is admin-only');
		$this->assertSame([], $method->getAttributes(PublicPage::class), $name . ' is open to visitors');
		// The annotation form still counts in the framework, so it must not slip in that way either.
		$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment(), $name);
		$this->assertStringNotContainsString('@NoCSRFRequired', (string)$method->getDocComment(), $name);
	}

	/** @dataProvider actions */
	public function testOnlyANavigationGoesWithoutACsrfToken(string $name, ReflectionMethod $method): void {
		$this->assertSame(
			in_array($name, self::NAVIGATED, true),
			$method->getAttributes(NoCSRFRequired::class) !== [],
			$name . (in_array($name, self::NAVIGATED, true) ? ' wants a token a navigation does not carry' : ' takes a request without a CSRF token'),
		);
	}
}
