<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The OCS API is the internal routes' second door, not a second set of rules (docs/api.md): each
 * OCS route answers at `/api/v1` what its internal twin answers at `/api`, through a controller of
 * the same name under `Ocs\` that takes the same arguments.
 */
class OcsRoutesTest extends TestCase {
	/**
	 * The routes that stay internal for every client: a file or a page to save, not an answer in
	 * the envelope. A client opens them with its app password as they are (docs/api.md#downloads).
	 * Every other route has a twin (RouteSweepTest).
	 */
	public const DOWNLOADS = ['document#download', 'report#logbook', 'report#mileage', 'report#csv'];

	/**
	 * The routes a client has and the web UI does not: they read, and nothing internal answers
	 * them to twin (docs/api.md#sync).
	 */
	private const OCS_ONLY = ['Ocs\Sync#index'];

	/** @return list<array{name: string, url: string, verb: string}> */
	private static function ocs(): array {
		return (require __DIR__ . '/../../appinfo/routes.php')['ocs'] ?? [];
	}

	/** @return list<array{name: string, url: string, verb: string}> every OCS route with a twin */
	private static function twinned(): array {
		return array_values(array_filter(self::ocs(), static fn (array $route): bool => !in_array($route['name'], self::OCS_ONLY, true)));
	}

	/** @return array<string, array{name: string, url: string, verb: string}> by name */
	private static function internal(): array {
		$routes = (require __DIR__ . '/../../appinfo/routes.php')['routes'];

		return array_column($routes, null, 'name');
	}

	/** `Ocs\Vehicle#index` is the twin of `vehicle#index`. */
	private static function twinName(string $ocsName): string {
		[$controller, $method] = explode('#', $ocsName);

		return lcfirst(substr($controller, strlen('Ocs\\'))) . '#' . $method;
	}

	/** @return array{class-string, string} */
	private static function target(string $ocsName): array {
		[$controller, $method] = explode('#', $ocsName);
		/** @var class-string $class */
		$class = 'OCA\\NextFleet\\Controller\\' . $controller . 'Controller';

		return [$class, (string)preg_replace_callback('/_[a-z]?/', static fn (array $m): string => strtoupper(ltrim($m[0], '_')), $method)];
	}

	public function testEveryOcsRouteTwinsAnInternalOneAtTheSamePathUnderV1(): void {
		$internal = self::internal();
		$this->assertNotEmpty(self::ocs(), 'the app serves no OCS route');

		foreach (self::twinned() as $route) {
			$this->assertStringStartsWith('Ocs\\', $route['name']);
			$twin = $internal[self::twinName($route['name'])] ?? null;
			$this->assertNotNull($twin, $route['name'] . ' has no internal twin');
			$this->assertSame($twin['verb'], $route['verb'], $route['name']);
			$this->assertSame('/api/v1' . substr($twin['url'], strlen('/api')), $route['url'], $route['name']);
		}
	}

	/**
	 * A download is a link a client opens with its app password over Basic auth, and a link carries
	 * no CSRF token. It reads, so it is a GET, and it has no twin to drift from.
	 */
	public function testEveryDownloadIsALinkThatTakesNoCsrfToken(): void {
		$internal = self::internal();
		$twins = array_map(static fn (array $route): string => self::twinName($route['name']), self::ocs());

		foreach (self::DOWNLOADS as $name) {
			$this->assertSame('GET', $internal[$name]['verb'] ?? null, $name);
			$this->assertNotContains($name, $twins, $name . ' is in the envelope too');
			[$class, $action] = self::target('Ocs\\' . ucfirst($name));
			$method = new ReflectionMethod(str_replace('\\Ocs\\', '\\', $class), $action);
			$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class), $name . ' wants a CSRF token');
			$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class), $name . ' is admin-only');
		}
	}

	/**
	 * It takes its twin's parameters - the same service call cannot be made with fewer - then the
	 * body fields it names for the OpenAPI document. Each is optional: whether a field is required
	 * is the service's rule, answered with its 400, never the framework's.
	 */
	public function testEveryOcsRouteNamesAnOcsControllerMethodShapedLikeItsTwin(): void {
		foreach (self::twinned() as $route) {
			[$class, $action] = self::target($route['name']);
			$this->assertTrue(method_exists($class, $action), $route['name'] . ' points at nothing');
			$this->assertTrue(is_subclass_of($class, OCSController::class), $class . ' is no OCSController');

			$method = new ReflectionMethod($class, $action);
			$this->assertTrue($method->isPublic(), $route['name']);
			$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class), $route['name'] . ' is admin-only');

			[$twinClass, $twinAction] = self::target('Ocs\\' . ucfirst(self::twinName($route['name'])));
			$twinClass = str_replace('\\Ocs\\', '\\', $twinClass);
			$twin = new ReflectionMethod($twinClass, $twinAction);
			$own = $method->getParameters();
			$this->assertSame(
				array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $twin->getParameters()),
				array_map(static fn (\ReflectionParameter $p): string => $p->getName(), array_slice($own, 0, $twin->getNumberOfParameters())),
				$route['name'] . ' takes other parameters than its twin',
			);
			foreach (array_slice($own, $twin->getNumberOfParameters()) as $field) {
				$this->assertTrue($field->isOptional(), $route['name'] . ' requires $' . $field->getName());
				$this->assertSame('mixed', (string)$field->getType(), $route['name'] . ' types $' . $field->getName() . ' for the framework to enforce');
			}
		}
	}

	/**
	 * A write is limited as its twin is (docs/security.md). Nextcloud counts per controller method,
	 * so the doors count apart - at most twice the limit, which docs/api.md states.
	 */
	public function testEveryOcsWriteIsRateLimitedLikeItsTwin(): void {
		foreach (self::twinned() as $route) {
			if (in_array($route['verb'], ['GET', 'HEAD'], true)) {
				continue;
			}
			[$class, $action] = self::target($route['name']);
			$limits = (new ReflectionMethod($class, $action))->getAttributes(UserRateLimit::class);
			$this->assertNotEmpty($limits, $route['name'] . ' writes and carries no #[UserRateLimit]');

			[$twinClass, $twinAction] = self::target('Ocs\\' . ucfirst(self::twinName($route['name'])));
			$twinLimits = (new ReflectionMethod(str_replace('\\Ocs\\', '\\', $twinClass), $twinAction))->getAttributes(UserRateLimit::class);
			$this->assertEquals($twinLimits[0]->getArguments(), $limits[0]->getArguments(), $route['name']);
		}
	}

	/**
	 * A route with no twin is held to what a twin would give it: a read under `/api/v1`, through an
	 * OCSController open to every user, and limited, since one call reads every table.
	 */
	public function testEveryOcsOnlyRouteIsALimitedReadUnderV1(): void {
		$names = array_column(self::ocs(), null, 'name');
		foreach (self::OCS_ONLY as $name) {
			$this->assertSame('GET', $names[$name]['verb'] ?? null, $name);
			$this->assertStringStartsWith('/api/v1/', $names[$name]['url']);
			[$class, $action] = self::target($name);
			$this->assertTrue(is_subclass_of($class, OCSController::class), $class . ' is no OCSController');
			$method = new ReflectionMethod($class, $action);
			$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class), $name . ' is admin-only');
			$this->assertNotEmpty($method->getAttributes(UserRateLimit::class), $name . ' carries no #[UserRateLimit]');
		}
	}

	/** Each answer is typed, since the types are what the OpenAPI document is read from. */
	public function testEveryOcsMethodDeclaresTheDataResponsesItAnswers(): void {
		foreach (self::ocs() as $route) {
			[$class, $action] = self::target($route['name']);
			$doc = (string)(new ReflectionMethod($class, $action))->getDocComment();
			$this->assertMatchesRegularExpression('/@return\s+DataResponse<Http::STATUS_/', $doc, $route['name'] . ' has no typed @return');
		}
	}
}
