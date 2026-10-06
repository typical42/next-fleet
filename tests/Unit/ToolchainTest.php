<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The versions and strictness settings the toolchain is held to (docs/development.md#local-dev-environment).
 * Each lives in its own file, and none of them fails anything when it slips.
 */
class ToolchainTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** @return array<string, mixed> */
	private function packageJson(): array {
		return (array)json_decode((string)file_get_contents(self::ROOT . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Node 20 is out of support, and the tools the frontend is held back for need 22. CI runs the
	 * newest line the range admits, so every job meets the same Node.
	 */
	public function testCiRunsTheNodeLineTheEnginesRangeEndsOn(): void {
		$this->assertSame('^22.22 || >=24', $this->packageJson()['engines']['node'] ?? null);

		$workflow = (array)Yaml::parseFile(self::ROOT . '/.github/workflows/ci.yml');
		$versions = [];
		foreach ((array)$workflow['jobs'] as $name => $job) {
			foreach ((array)($job['steps'] ?? []) as $step) {
				if (str_starts_with((string)($step['uses'] ?? ''), 'actions/setup-node@')) {
					$versions[$name] = $step['with']['node-version'] ?? null;
				}
			}
		}

		$this->assertNotEmpty($versions, 'no job sets up Node');
		$this->assertSame(array_fill_keys(array_keys($versions), '24'), $versions);
	}

	/** Types for a newer Node than the floor let code use an API the floor lacks. */
	public function testTheNodeTypesAreTheFloorsLine(): void {
		$range = (string)($this->packageJson()['devDependencies']['@types/node'] ?? '');

		$this->assertMatchesRegularExpression('/^\^22\.\d+\.\d+$/', $range);
	}

	/** docs/development.md, "A deprecation is an error". Psalm only mentions one below level 2. */
	public function testPsalmFailsOnEveryDeprecation(): void {
		$psalm = simplexml_load_file(self::ROOT . '/psalm.xml');
		$this->assertNotFalse($psalm);
		$psalm->registerXPathNamespace('p', 'https://getpsalm.org/schema/config');

		$levels = [];
		foreach ($psalm->xpath('/p:psalm/p:issueHandlers/*') ?: [] as $handler) {
			$levels[$handler->getName()] = (string)$handler['errorLevel'];
		}

		$deprecations = ['DeprecatedClass', 'DeprecatedConstant', 'DeprecatedFunction', 'DeprecatedInterface',
			'DeprecatedMethod', 'DeprecatedProperty', 'DeprecatedTrait'];
		foreach ($deprecations as $issue) {
			$this->assertSame('error', $levels[$issue] ?? null, "$issue is not an error");
		}
	}

	/**
	 * The same for what PHP deprecates at run time, which only the integration suite meets. Ours
	 * only: the server's own would fail a weekly run with nothing here to fix.
	 */
	public function testTheIntegrationSuiteFailsOnADeprecationInTheApp(): void {
		$config = simplexml_load_file(self::ROOT . '/phpunit.integration.xml');
		$this->assertNotFalse($config);

		$this->assertSame('true', (string)$config['failOnDeprecation']);
		$this->assertSame('true', (string)$config->source['restrictDeprecations']);
	}
}
