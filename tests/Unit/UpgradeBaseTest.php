<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The version users upgrade from is written in five files, and each release moves it on. One left
 * behind checks the upgrade from a release users no longer have, or asserts a base no script uses.
 */
class UpgradeBaseTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	public function testEveryPlaceNamesTheSameBase(): void {
		$base = $this->only('tools/release.sh', '/^BASES="(v[0-9.]+)"$/m');

		$this->assertSame($base, $this->only('tools/upgrade-check.sh', '/^base=\$\{2:-(v[0-9.]+)\}$/m'));
		$this->assertSame($base, $this->only('tools/upgrade-check.sh', '/It defaults to (v[0-9.]+),/'));
		$this->assertSame($base, $this->only('.github/workflows/ci.yml', '/^ +base: \[(v[0-9.]+)\]$/m'));
		$this->assertSame($base, $this->only('tests/Unit/CiWorkflowTest.php', '/\[\'(v[0-9.]+)\'\], \$job\[\'strategy\'\]\[\'matrix\'\]\[\'base\'\]/'));
		$this->assertSame([$base, $base, $base], $this->all('tests/Unit/ReleaseScriptTest.php', '/\.tar\.gz (v[0-9.]+)\'/'));
	}

	/**
	 * The base is the last release before the one being made: the first dated CHANGELOG section that
	 * is not info.xml's version. Comparing the five places only with each other stays green when all
	 * of them are left behind together.
	 */
	public function testTheBaseIsTheLastReleaseBeforeThisOne(): void {
		$version = $this->only('appinfo/info.xml', '/<version>([0-9.]+)<\/version>/');
		$released = array_values(array_diff($this->all('CHANGELOG.md', '/^## ([0-9.]+) — \d{4}-\d{2}-\d{2}$/m'), [$version]));

		$this->assertNotEmpty($released, 'CHANGELOG.md has no dated section before ' . $version);
		$this->assertSame('v' . $released[0], $this->only('tools/release.sh', '/^BASES="(v[0-9.]+)"$/m'));
	}

	/** The one version the pattern finds in the file. */
	private function only(string $file, string $pattern): string {
		$found = $this->all($file, $pattern);
		$this->assertCount(1, $found, "$file names the base " . count($found) . ' times');

		return $found[0];
	}

	/** @return list<string> every version the pattern finds in the file */
	private function all(string $file, string $pattern): array {
		preg_match_all($pattern, (string)file_get_contents(self::ROOT . '/' . $file), $matches);

		return $matches[1];
	}
}
