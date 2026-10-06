<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/third-party-notices.mjs runs on a throwaway tree: a `.license` sidecar as the build writes
 * it, a lock file, and node_modules holding only licence files.
 */
class ThirdPartyNoticesTest extends TestCase {
	private const SCRIPT = __DIR__ . '/../../tools/third-party-notices.mjs';

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/nextfleet-notices-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700, true);
		$this->put('package.json', '{"name": "nextfleet"}');
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	/** MIT, ISC and BSD ask for the notice to travel with every copy, the bundle included. */
	public function testItCarriesEachBundledPackagesOwnLicenceText(): void {
		$this->bundle('a.chunk.mjs', ['zeta' => ['1.0.0', 'MIT'], 'alpha' => ['2.0.0', 'ISC']]);
		$this->bundle('b.chunk.mjs', ['alpha' => ['2.0.0', 'ISC']]);
		$this->install('node_modules/zeta', '1.0.0', ['LICENSE' => "MIT\nCopyright Zeta\n"]);
		$this->install('node_modules/alpha', '2.0.0', ['LICENCE.md' => "ISC\nCopyright Alpha\n"]);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertSame(1, substr_count($output, 'Copyright Alpha'), 'once per package, not per chunk');
		$this->assertStringContainsString("zeta 1.0.0 (MIT)\n", $output);
		$this->assertStringContainsString('Copyright Zeta', $output);
		$this->assertLessThan(strpos($output, 'zeta 1.0.0'), strpos($output, 'alpha 2.0.0'), 'sorted by name');
	}

	/** Packages that follow REUSE keep their texts in a folder. */
	public function testItReadsALicencesFolder(): void {
		$this->bundle('a.chunk.mjs', ['reused' => ['1.0.0', 'GPL-3.0-or-later']]);
		$this->install('node_modules/reused', '1.0.0', ['LICENSES/GPL-3.0-or-later.txt' => 'GNU GENERAL TEXT']);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('GNU GENERAL TEXT', $output);
	}

	public function testItLeavesOutTheAppItself(): void {
		$this->bundle('a.chunk.mjs', ['nextfleet' => ['0.3.1', 'AGPL-3.0-or-later'], 'zeta' => ['1.0.0', 'MIT']]);
		$this->install('node_modules/zeta', '1.0.0', ['LICENSE' => 'MIT']);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertStringNotContainsString('nextfleet', $output);
	}

	/** npm nests a second version under the package that needs it; the bundle names which one. */
	public function testItReadsTheInstallOfTheBundledVersion(): void {
		$this->bundle('a.chunk.mjs', ['zeta' => ['1.0.0', 'MIT']]);
		$this->install('node_modules/zeta', '2.0.0', ['LICENSE' => 'Copyright Zeta Two']);
		$this->install('node_modules/outer/node_modules/zeta', '1.0.0', ['LICENSE' => 'Copyright Zeta One']);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('Copyright Zeta One', $output);
		$this->assertStringNotContainsString('Copyright Zeta Two', $output);
	}

	/**
	 * @nextcloud/vue and @nextcloud/event-bus ship no licence file. Their licences' texts are in
	 * LICENSES/ or in another bundled package under the same licence.
	 */
	public function testAPackageWithoutALicenceFileGetsTheTextOfItsLicence(): void {
		$this->bundle('a.chunk.mjs', [
			'own' => ['1.0.0', 'AGPL-3.0-or-later'],
			'lent' => ['1.0.0', 'GPL-3.0-or-later'],
			'lender' => ['1.0.0', 'GPL-3.0-or-later'],
		]);
		$this->put('LICENSES/AGPL-3.0-or-later.txt', 'GNU AFFERO TEXT');
		$this->install('node_modules/own', '1.0.0', []);
		$this->install('node_modules/lent', '1.0.0', []);
		$this->install('node_modules/lender', '1.0.0', ['COPYING' => 'GNU GENERAL TEXT']);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('GNU AFFERO TEXT', $output);
		$this->assertSame(1, substr_count($output, 'GNU GENERAL TEXT'));
		$this->assertStringContainsString("lent 1.0.0 (GPL-3.0-or-later)\n\nThe same text as lender 1.0.0 above.", $output);
	}

	/**
	 * A dozen @nextcloud packages carry the same 35 kB GPL, some wrapped differently; once is
	 * enough.
	 */
	public function testItPrintsAnIdenticalTextOnce(): void {
		$this->bundle('a.chunk.mjs', ['first' => ['1.0.0', 'GPL-3.0-or-later'], 'second' => ['1.0.0', 'GPL-3.0-or-later']]);
		$this->install('node_modules/first', '1.0.0', ['LICENSE' => "GNU GENERAL\nTEXT"]);
		$this->install('node_modules/second', '1.0.0', ['LICENSE' => "GNU  GENERAL TEXT\n\n"]);

		[$status, $output] = $this->notices();

		$this->assertSame(0, $status, $output);
		$this->assertSame(1, substr_count($output, 'GNU GENERAL'));
		$this->assertStringContainsString('The same text as first 1.0.0 above.', $output);
	}

	/** A bundle shipped without its notices is the gap this script closes, so it stops the build. */
	public function testItFailsWhenNoTextIsFound(): void {
		$this->bundle('a.chunk.mjs', ['bare' => ['1.0.0', 'Zlib']]);
		$this->install('node_modules/bare', '1.0.0', []);

		[$status, $output] = $this->notices();

		$this->assertSame(1, $status);
		$this->assertStringContainsString('bare 1.0.0', $output);
	}

	public function testItFailsOnABundleWithNoLicenceFiles(): void {
		mkdir($this->dir . '/js');

		[$status, $output] = $this->notices();

		$this->assertSame(1, $status);
		$this->assertStringContainsString('npm run build', $output);
	}

	/** @param array<string, array{string, string}> $packages name => [version, licence] */
	private function bundle(string $chunk, array $packages): void {
		// REUSE-IgnoreStart - a sidecar's header, not this file's licence.
		$text = "SPDX-License-Identifier: MIT\n\nThis file is generated from multiple sources. Included packages:\n";
		// REUSE-IgnoreEnd
		foreach ($packages as $name => [$version, $licence]) {
			$text .= "- $name\n\t- version: $version\n\t- license: $licence\n";
		}
		$this->put('js/' . $chunk . '.license', $text);
	}

	/** @param array<string, string> $files */
	private function install(string $path, string $version, array $files): void {
		$lock = is_file($this->dir . '/package-lock.json')
			? json_decode((string)file_get_contents($this->dir . '/package-lock.json'), true)
			: ['packages' => ['' => ['name' => 'nextfleet']]];
		$lock['packages'][$path] = ['version' => $version];
		$this->put('package-lock.json', (string)json_encode($lock));
		$this->put($path . '/package.json', '{}');
		foreach ($files as $name => $content) {
			$this->put($path . '/' . $name, $content);
		}
	}

	private function put(string $path, string $content): void {
		$file = $this->dir . '/' . $path;
		if (!is_dir(dirname($file))) {
			mkdir(dirname($file), 0700, true);
		}
		file_put_contents($file, $content);
	}

	/** @return array{int, string} the exit status and stdout with stderr */
	private function notices(): array {
		exec('node ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($this->dir) . ' 2>&1', $output, $status);

		return [$status, implode("\n", $output) . "\n"];
	}
}
