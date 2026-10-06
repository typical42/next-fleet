<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The half of `reuse lint` that runs without Python, so a missing header is caught by
 * `composer test` rather than only by CI. What it cannot catch is a REUSE.toml entry gone
 * stale; the workflow's `reuse` job is there for that. Also the dependency rule of
 * docs/legal.md: nothing the tarball can carry is under a licence the AGPL does not admit.
 */
class LicensingTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** Formats that carry a comment. Everything else is annotated in REUSE.toml. */
	private const SOURCE_EXTENSIONS = ['php', 'js', 'cjs', 'mjs', 'ts', 'vue', 'css', 'scss', 'xml', 'svg', 'yml', 'yaml'];

	/** Third-party, or generated: psalm rewrites its baseline wholesale and would drop a header. */
	private const NOT_OURS = ['tests/schema/info.xsd', 'psalm-baseline.xml'];

	/**
	 * Licences a package may carry to be combined into the AGPL bundle: the permissive ones, the
	 * GPL-3.0 by AGPL section 13, the MPL-2.0 by its section 3.3. Extend it only after reading
	 * the new licence; docs/legal.md says why.
	 */
	private const ADMITTED = [
		'0BSD', 'AGPL-3.0-or-later', 'Apache-2.0', 'BlueOak-1.0.0', 'BSD-2-Clause', 'BSD-3-Clause',
		'GPL-3.0-or-later', 'ISC', 'MIT', 'MPL-2.0',
	];

	public function testEverySourceFileDeclaresItsLicence(): void {
		$missing = [];
		foreach ($this->sourceFiles() as $path) {
			$head = (string)file_get_contents(self::ROOT . '/' . $path, false, null, 0, 512);
			// REUSE-IgnoreStart - the needle below is a needle, not this file's own licence.
			if (!str_contains($head, 'SPDX-License-Identifier: AGPL-3.0-or-later')) {
				$missing[] = $path;
			}
			// REUSE-IgnoreEnd
		}

		$this->assertSame([], $missing, "No SPDX header in:\n" . implode("\n", $missing));
	}

	/** REUSE resolves an SPDX identifier against LICENSES/, not against the root LICENSE. */
	public function testTheLicenceTextIsWhereReuseLooksForIt(): void {
		$this->assertFileEquals(self::ROOT . '/LICENSE', self::ROOT . '/LICENSES/AGPL-3.0-or-later.txt');
	}

	/**
	 * Every package the lock files install outside dev may end up in the tarball: npm's through
	 * the bundle, Composer's through vendor/ should PHP ever gain a runtime dependency.
	 */
	public function testEveryRuntimeDependencyIsUnderALicenceTheAgplAdmits(): void {
		$npm = json_decode((string)file_get_contents(self::ROOT . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
		$composer = json_decode((string)file_get_contents(self::ROOT . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertGreaterThan(100, count($npm['packages']), 'the lock file lists no packages, so this proves nothing');

		$refused = self::refused($npm, $composer);

		$this->assertSame([], $refused, "Not admitted:\n" . implode("\n", $refused));
	}

	/**
	 * The lock files cannot tell which dev packages reach the bundle (Vite's preload helper, the
	 * Vue plugin's, the node polyfills), so the build's own list is read. CI's PHP jobs do not
	 * build; `npm run build` before `composer test` runs this half.
	 */
	public function testEveryBundledPackageIsUnderALicenceTheAgplAdmits(): void {
		$files = glob(self::ROOT . '/js/*.license') ?: [];
		if ($files === []) {
			$this->markTestSkipped('js/ holds no build; run npm run build first');
		}

		$refused = self::refusedInBundle(array_map(fn (string $file): string => (string)file_get_contents($file), $files));

		$this->assertSame([], $refused, "Not admitted:\n" . implode("\n", $refused));
	}

	/** A build plugin is a dev package, yet the bundle may carry its code. */
	public function testABundledPackageIsJudgedByTheBuildsLicenceFile(): void {
		$text = "This file is generated from multiple sources. Included packages:\n"
			. "- vite\n\t- version: 7.3.6\n\t- license: MIT\n"
			. "- old-polyfill\n\t- version: 1.0.0\n\t- license: GPL-2.0-only\n";

		$this->assertSame(['old-polyfill@1.0.0 (GPL-2.0-only)'], self::refusedInBundle([$text]));
	}

	public function testAPackageUnderALicenceTheAgplDoesNotAdmitIsRefused(): void {
		$lock = ['packages' => [
			'' => ['name' => 'nextfleet'],
			'node_modules/old' => ['version' => '1.0.0', 'license' => 'GPL-2.0-only'],
			'node_modules/fine' => ['version' => '2.0.0', 'license' => 'MIT'],
		]];

		$this->assertSame(['old@1.0.0 (GPL-2.0-only)'], self::refused($lock, ['packages' => []]));
	}

	/**
	 * A choice (OR) needs one admitted branch, a combination (AND) needs all. A package with no
	 * licence at all grants nothing.
	 */
	public function testALicenceExpressionIsReadAsSpdxDefinesIt(): void {
		$lock = ['packages' => [
			'node_modules/either' => ['version' => '1.0.0', 'license' => '(MPL-2.0 OR Apache-2.0)'],
			'node_modules/both' => ['version' => '1.0.0', 'license' => '(MIT AND ISC)'],
			'node_modules/half' => ['version' => '1.0.0', 'license' => 'MIT AND GPL-2.0-only'],
			'node_modules/a/node_modules/none' => ['version' => '1.0.0'],
			'node_modules/nested' => ['version' => '1.0.0', 'license' => '(MIT OR Apache-2.0) AND GPL-2.0-only'],
		]];

		$this->assertSame(
			['half@1.0.0 (MIT AND GPL-2.0-only)', 'none@1.0.0 ()', 'nested@1.0.0 ((MIT OR Apache-2.0) AND GPL-2.0-only)'],
			self::refused($lock, ['packages' => []]),
		);
	}

	/** Composer lists licences as a choice; `packages-dev` never ships. */
	public function testAComposerPackageIsJudgedAsWell(): void {
		$composer = [
			'packages' => [
				['name' => 'vendor/fine', 'version' => '1.0.0', 'license' => ['GPL-2.0-only', 'MIT']],
				['name' => 'vendor/old', 'version' => '2.0.0', 'license' => ['GPL-2.0-only']],
			],
			'packages-dev' => [
				['name' => 'vendor/tool', 'version' => '3.0.0', 'license' => ['OSL-3.0']],
			],
		];

		$this->assertSame(['vendor/old@2.0.0 (GPL-2.0-only)'], self::refused(['packages' => []], $composer));
	}

	/** A dev package runs on the developer's machine and never reaches the tarball. */
	public function testADevPackageIsNotJudged(): void {
		$lock = ['packages' => [
			'node_modules/linter' => ['version' => '1.0.0', 'license' => 'OSL-3.0', 'dev' => true],
			'node_modules/native' => ['version' => '1.0.0', 'license' => 'OSL-3.0', 'devOptional' => true],
		]];

		$this->assertSame([], self::refused($lock, ['packages' => []]));
	}

	/**
	 * @param array{packages: array<string, array<string, mixed>>, ...} $npm package-lock.json
	 * @param array{packages: list<array<string, mixed>>, ...} $composer composer.lock
	 * @return list<string> "name@version (licence)" of each package refused
	 */
	private static function refused(array $npm, array $composer): array {
		$refused = [];
		foreach ($npm['packages'] as $path => $package) {
			if ($path === '' || ($package['dev'] ?? false) || ($package['devOptional'] ?? false)) {
				continue;
			}
			$licence = (string)($package['license'] ?? '');
			if (!self::admits($licence)) {
				$name = substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
				$refused[] = "$name@{$package['version']} ($licence)";
			}
		}
		foreach ($composer['packages'] as $package) {
			$licence = implode(' OR ', (array)($package['license'] ?? []));
			if (!self::admits($licence)) {
				$refused[] = "{$package['name']}@{$package['version']} ($licence)";
			}
		}
		return $refused;
	}

	/**
	 * @param list<string> $texts the `js/*.license` files @nextcloud/vite-config writes
	 * @return list<string> "name@version (licence)" of each package refused
	 */
	private static function refusedInBundle(array $texts): array {
		$refused = [];
		foreach ($texts as $text) {
			preg_match_all('/^- (\S+)\n\t- version: (\S+)\n\t- license: (.+)$/m', $text, $packages, PREG_SET_ORDER);
			foreach ($packages as [, $name, $version, $licence]) {
				if (!self::admits($licence)) {
					$refused[] = "$name@$version ($licence)";
				}
			}
		}
		return array_values(array_unique($refused));
	}

	/**
	 * Reads the flat expressions npm writes: one level of brackets, OR or AND, not mixed.
	 * Anything else is refused rather than half read; a person judges it.
	 */
	private static function admits(string $expression): bool {
		$bare = trim($expression, '() ');
		if (strpbrk($bare, '()') !== false || (str_contains($bare, ' OR ') && str_contains($bare, ' AND '))) {
			return false;
		}
		if (str_contains($bare, ' OR ')) {
			return array_filter(explode(' OR ', $bare), self::admits(...)) !== [];
		}
		if (str_contains($bare, ' AND ')) {
			return array_filter(explode(' AND ', $bare), fn (string $part): bool => !self::admits($part)) === [];
		}
		return in_array($bare, self::ADMITTED, true);
	}

	/**
	 * Everything git would carry, tracked or not yet, as REUSE sees it. Asking git means
	 * .gitignore excludes a new build directory, not a list that rots.
	 *
	 * @return list<string> repository-relative paths
	 */
	private function sourceFiles(): array {
		$command = 'git -C ' . escapeshellarg((string)realpath(self::ROOT)) . ' ls-files --cached --others --exclude-standard';
		exec($command, $tracked, $status);
		$this->assertSame(0, $status, 'git ls-files failed; the licence check needs a checkout');

		$paths = array_values(array_filter(
			$tracked,
			fn (string $path): bool => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SOURCE_EXTENSIONS, true)
				&& !in_array($path, self::NOT_OURS, true)
				&& is_file(self::ROOT . '/' . $path),
		));

		$this->assertNotEmpty($paths, 'the listing found nothing, so it proves nothing');

		return $paths;
	}
}
