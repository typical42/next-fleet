<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The docs point at each other by file and heading, and a heading reworded or a file moved breaks
 * a link nobody follows until a reader does. Only links inside the repository are checked; the
 * web is not this test's to reach.
 */
class DocsLinksTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** Trees that are not ours to keep linked: dependencies, build output, test output. */
	private const SKIP = ['vendor', 'vendor-bin', 'node_modules', 'build', 'js', 'test-results', '.git'];

	public function testEveryRelativeLinkResolves(): void {
		$broken = [];
		foreach ($this->markdownFiles() as $file) {
			foreach ($this->links($file) as [$target, $anchor]) {
				$path = $target === '' ? $file : dirname($file) . '/' . $target;
				if (!file_exists($path)) {
					$broken[] = $this->relative($file) . ' → ' . $target;
				} elseif ($anchor !== '' && is_file($path) && str_ends_with($path, '.md') && !in_array($anchor, $this->anchors($path), true)) {
					$broken[] = $this->relative($file) . ' → ' . $target . '#' . $anchor;
				}
			}
		}

		$this->assertSame([], $broken);
	}

	/**
	 * Code says why by pointing at a doc's heading, `docs/ui.md#screens`. A comment's pointer is
	 * read by fewer people than a doc's link, so it breaks unseen for longer.
	 */
	public function testEveryDocHeadingTheCodeNamesExists(): void {
		$broken = [];
		foreach (['lib', 'src', 'tools', 'tests', 'templates', 'appinfo', '.github'] as $tree) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $tree, \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				preg_match_all('#\bdocs/([\w-]+\.md)\#([\w-]+)#', (string)file_get_contents((string)$file), $matches, PREG_SET_ORDER);
				foreach ($matches as [$pointer, $doc, $anchor]) {
					$path = self::ROOT . '/docs/' . $doc;
					if (!is_file($path) || !in_array($anchor, $this->anchors($path), true)) {
						$broken[] = $this->relative((string)$file) . ' → ' . $pointer;
					}
				}
			}
		}

		$this->assertSame([], array_values(array_unique($broken)));
	}

	/** @return list<string> */
	private function markdownFiles(): array {
		$found = [];
		$walk = function (string $dir) use (&$walk, &$found): void {
			foreach (scandir($dir) ?: [] as $name) {
				if ($name === '.' || $name === '..' || ($dir === self::ROOT && in_array($name, self::SKIP, true))) {
					continue;
				}
				$path = $dir . '/' . $name;
				if (is_dir($path)) {
					$walk($path);
				} elseif (str_ends_with($name, '.md')) {
					$found[] = $path;
				}
			}
		};
		$walk(self::ROOT);

		$this->assertNotEmpty($found);
		return $found;
	}

	/**
	 * Inline links and images, outside code fences and code spans, that name no scheme.
	 *
	 * @return list<array{string, string}> target path and anchor, either possibly empty
	 */
	private function links(string $file): array {
		$text = (string)file_get_contents($file);
		$text = (string)preg_replace('/^(```|~~~).*?^\1/ms', '', $text);
		$text = (string)preg_replace('/`[^`\n]*`/', '', $text);

		preg_match_all('/\]\(<?([^)\s>]+)>?(?:\s+"[^"]*")?\)/', $text, $matches);
		$links = [];
		foreach ($matches[1] as $url) {
			if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 || str_starts_with($url, '//')) {
				continue;
			}
			[$target, $anchor] = array_pad(explode('#', $url, 2), 2, '');
			$links[] = [rawurldecode($target), $anchor];
		}

		return $links;
	}

	/**
	 * Heading anchors as GitHub renders them: lower case, punctuation dropped, spaces to hyphens,
	 * and a repeated one numbered.
	 *
	 * @return list<string>
	 */
	private function anchors(string $file): array {
		$text = (string)preg_replace('/^(```|~~~).*?^\1/ms', '', (string)file_get_contents($file));
		preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $text, $matches);

		$anchors = [];
		$seen = [];
		foreach ($matches[1] as $heading) {
			$heading = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
			$slug = (string)preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower($heading));
			$slug = str_replace(' ', '-', $slug);
			$count = $seen[$slug] ?? 0;
			$seen[$slug] = $count + 1;
			$anchors[] = $count === 0 ? $slug : $slug . '-' . $count;
		}

		return $anchors;
	}

	private function relative(string $file): string {
		return substr($file, strlen(self::ROOT) + 1);
	}
}
