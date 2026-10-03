<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A route added later is held to what every route has (docs/security.md#authorization): an arm in
 * the stranger sweep and, unless it is the page or a download, an OCS twin. The sweep's own
 * `default` arm catches a missing arm too, but only on a server; this catches it in the unit suite.
 */
class RouteSweepTest extends TestCase {
	private const SWEEP = __DIR__ . '/../Integration/VehicleIdorTest.php';

	/** @return array{routes: list<array{name: string, url: string, verb: string}>, ocs: list<array{name: string, url: string, verb: string}>} */
	private static function routes(): array {
		return require __DIR__ . '/../../appinfo/routes.php';
	}

	/** `Ocs\Vehicle#index` answers for `vehicle#index`, and is walked as it. */
	private static function twinOf(string $ocsName): string {
		[$controller, $method] = explode('#', $ocsName);

		return lcfirst(substr($controller, strlen('Ocs\\'))) . '#' . $method;
	}

	/**
	 * The match arms of the stranger sweep, read from its source: a string followed by `=>` inside
	 * the test method.
	 *
	 * @return list<string>
	 */
	private static function sweptRoutes(): array {
		$tokens = array_values(array_filter(
			token_get_all((string)file_get_contents(self::SWEEP)),
			static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
		));
		$arms = [];
		$inside = false;
		$depth = 0;
		foreach ($tokens as $i => $token) {
			if (is_array($token) && $token[1] === 'testAStrangerReachesNothingThroughAnyRoute') {
				$inside = true;
				continue;
			}
			if (!$inside) {
				continue;
			}
			if ($token === '{') {
				$depth++;
			} elseif ($token === '}' && --$depth === 0) {
				break;
			}
			if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
				$arms[] = trim($token[1], '\'"');
			}
		}

		return $arms;
	}

	public function testEveryRouteHasAnArmInTheStrangerSweep(): void {
		$arms = self::sweptRoutes();
		$this->assertContains('vehicle#show', $arms, 'the sweep was not found in ' . self::SWEEP);

		foreach (self::routes()['routes'] as $route) {
			if ($route['name'] !== 'page#index') {
				$this->assertContains($route['name'], $arms, $route['name'] . ' has no arm in the stranger sweep');
			}
		}
		foreach (self::routes()['ocs'] as $route) {
			$this->assertContains(self::twinOf($route['name']), $arms, $route['name'] . ' has no arm in the stranger sweep');
		}
	}

	/** Not only the families opened so far: a new family is behind both doors from its first route. */
	public function testEveryRouteButThePageAndTheDownloadsHasAnOcsTwin(): void {
		$twins = array_map(self::twinOf(...), array_column(self::routes()['ocs'], 'name'));

		foreach (self::routes()['routes'] as $route) {
			if ($route['name'] !== 'page#index' && !in_array($route['name'], OcsRoutesTest::DOWNLOADS, true)) {
				$this->assertContains($route['name'], $twins, $route['name'] . ' has no OCS twin');
			}
		}
	}
}
