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
 * stale; the workflow's `reuse` job is there for that.
 */
class LicensingTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** Formats that carry a comment. Everything else is annotated in REUSE.toml. */
	private const SOURCE_EXTENSIONS = ['php', 'js', 'cjs', 'mjs', 'ts', 'vue', 'css', 'scss', 'xml', 'svg', 'yml', 'yaml'];

	/** Third-party, or generated: psalm rewrites its baseline wholesale and would drop a header. */
	private const NOT_OURS = ['tests/schema/info.xsd', 'psalm-baseline.xml'];

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
	 * Everything git would carry, tracked or not yet — which is what ends up in a release,
	 * and what REUSE itself looks at. Asking git rather than walking the tree means a new
	 * build or coverage directory is excluded by .gitignore instead of by a list that rots.
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
				&& !in_array($path, self::NOT_OURS, true),
		));

		$this->assertNotEmpty($paths, 'the listing found nothing, so it proves nothing');

		return $paths;
	}
}
