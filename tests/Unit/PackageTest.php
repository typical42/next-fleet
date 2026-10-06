<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/package.sh copies a list, not the tree, so the tarball carries only our own code. A list
 * rots silently: a new runtime directory missing from it is an app that installs and then fails
 * on the server. So every top-level entry git carries must be named, shipped or left out.
 */
class PackageTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	public function testEveryTopLevelEntryIsShippedOrLeftOutOnPurpose(): void {
		$ship = $this->listIn('SHIP');
		$leave = $this->listIn('LEAVE');

		$this->assertSame([], array_values(array_intersect($ship, $leave)), 'named both shipped and left out');
		$this->assertSame([], array_values(array_diff($this->topLevel(), $ship, $leave)), 'named neither shipped nor left out');
	}

	public function testItShipsWhatTheServerReads(): void {
		$ship = $this->listIn('SHIP');

		foreach (['appinfo', 'lib', 'templates', 'img', 'l10n', 'css', 'js'] as $entry) {
			$this->assertContains($entry, $ship);
		}
	}

	/**
	 * The contract of the version a server runs travels with it, where Nextcloud's OCS API viewer
	 * looks for it; the tool that writes it does not.
	 */
	public function testItShipsTheApiDocumentAndNotItsGenerator(): void {
		$this->assertContains('openapi.json', $this->listIn('SHIP'));
		$this->assertContains('vendor-bin', $this->listIn('LEAVE'));
	}

	/**
	 * The build writes a source map beside every chunk. A map is our source, comments and all, for
	 * anyone to fetch from the server, and much of the tarball's size; the store has no use for it.
	 */
	public function testItShipsNoSourceMaps(): void {
		$script = (string)file_get_contents(self::ROOT . '/tools/package.sh');

		$remove = strpos($script, 'find "$stage" -name \'*.map\' -delete');
		$pack = strpos($script, 'tar --sort=name');
		$check = strpos($script, '\.map$');
		$this->assertIsInt($remove, 'the stage keeps its source maps');
		$this->assertIsInt($pack);
		$this->assertLessThan($pack, $remove, 'the maps go after the tarball is packed');
		$this->assertIsInt($check, 'nothing checks the tarball for a map');
		$this->assertGreaterThan($pack, $check);
	}

	/**
	 * The bundle carries other people's code; their licences want their notices beside it
	 * (tools/third-party-notices.mjs).
	 */
	public function testItShipsTheNoticesOfTheBundledPackages(): void {
		$script = (string)file_get_contents(self::ROOT . '/tools/package.sh');

		$write = strpos($script, 'node tools/third-party-notices.mjs . >"$stage/THIRD-PARTY-NOTICES.txt"');
		$pack = strpos($script, 'tar --sort=name');
		$this->assertIsInt($write, 'the stage gets no notices');
		$this->assertIsInt($pack);
		$this->assertLessThan($pack, $write, 'the notices are written after the tarball is packed');
		$this->assertMatchesRegularExpression('/^for needed in [^\n]*\bTHIRD-PARTY-NOTICES\.txt\b/m', $script, 'nothing checks the tarball for them');
	}

	/** @return list<string> */
	private function listIn(string $variable): array {
		$script = (string)file_get_contents(self::ROOT . '/tools/package.sh');
		$this->assertSame(1, preg_match('/^' . $variable . '="([^"]+)"$/m', $script, $match), $variable . ' is not a one-line list');

		return preg_split('/\s+/', trim($match[1])) ?: [];
	}

	/**
	 * The top level of what git carries, tracked or not yet, plus the build output .gitignore
	 * hides but the tarball needs.
	 *
	 * @return list<string>
	 */
	private function topLevel(): array {
		$command = 'git -C ' . escapeshellarg((string)realpath(self::ROOT)) . ' ls-files --cached --others --exclude-standard';
		exec($command, $paths, $status);
		$this->assertSame(0, $status, 'git ls-files failed; the check needs a checkout');

		$entries = array_map(static fn (string $path): string => explode('/', $path)[0], $paths);
		$entries = array_filter($entries, fn (string $entry): bool => file_exists(self::ROOT . '/' . $entry));

		return array_values(array_unique([...$entries, 'js']));
	}
}
