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

	/** @return list<string> */
	private function listIn(string $variable): array {
		$script = (string)file_get_contents(self::ROOT . '/tools/package.sh');
		$this->assertMatchesRegularExpression('/^' . $variable . '="[^"]+"$/m', $script);
		preg_match('/^' . $variable . '="([^"]+)"$/m', $script, $match);

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
