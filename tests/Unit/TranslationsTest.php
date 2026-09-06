<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use OCA\NextFleet\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Both languages are first-class from M1, so a string that reaches the screen untranslated is a
 * defect and not a to-do: docs/ui.md#languages asks for exactly this check.
 */
class TranslationsTest extends TestCase {
	private const LANGUAGES = ['en', 'de', 'de_DE'];

	/** Where the app's own root is, from tests/Unit/. */
	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	public function testEveryStringTheFrontendMarksIsInEveryCatalogue(): void {
		$marked = self::markedStrings();
		$this->assertNotEmpty($marked, 'no marked strings found - has t() been spelled differently?');

		foreach (self::LANGUAGES as $language) {
			$catalogue = self::catalogue($language);
			foreach ($marked as $string) {
				$this->assertArrayHasKey($string, $catalogue, $language . ' does not translate: ' . $string);
				$this->assertNotSame('', $catalogue[$string], $language . ' translates ' . $string . ' to nothing');
			}
		}
	}

	/**
	 * A catalogue entry nothing marks any more is a string somebody has to keep translating for
	 * a screen that no longer shows it.
	 */
	public function testNoCatalogueCarriesAStringNothingMarks(): void {
		$marked = self::markedStrings();

		foreach (self::LANGUAGES as $language) {
			$orphans = array_diff(array_keys(self::catalogue($language)), $marked);
			$this->assertSame([], array_values($orphans), $language . ' translates strings nothing uses');
		}
	}

	/**
	 * The `.js` catalogue is what the browser loads and the `.json` one is what the translation
	 * tool maintains (docs/ui.md#languages). They are two files holding one fact, so a hand edit
	 * to either has to reach the other.
	 */
	public function testTheBrowsersCatalogueSaysWhatTheToolsCatalogueSays(): void {
		foreach (self::LANGUAGES as $language) {
			$script = file_get_contents(self::root() . '/l10n/' . $language . '.js');
			$this->assertIsString($script);
			$this->assertStringContainsString(Application::APP_ID, $script, $language . '.js registers another app');

			foreach (self::catalogue($language) as $source => $translation) {
				$this->assertStringContainsString(
					$translation,
					$script,
					$language . '.js is missing the translation of: ' . $source,
				);
			}
		}
	}

	/**
	 * Every string the Vue sources hand to `t()`. The app id is spelled out in the pattern
	 * because a call naming another app would be translated from another catalogue.
	 *
	 * @return list<string>
	 */
	private static function markedStrings(): array {
		$strings = [];
		$sources = new \RegexIterator(
			new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src')),
			'/\.(js|vue)$/',
		);

		foreach ($sources as $source) {
			$pattern = '/\bt\(\s*\'' . preg_quote(Application::APP_ID, '/') . '\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';
			preg_match_all($pattern, (string)file_get_contents((string)$source), $matches);
			$strings = array_merge($strings, $matches[1]);
		}

		return array_values(array_unique($strings));
	}

	/** @return array<string, string> */
	private static function catalogue(string $language): array {
		$decoded = json_decode(
			(string)file_get_contents(self::root() . '/l10n/' . $language . '.json'),
			true,
			flags: JSON_THROW_ON_ERROR,
		);

		return $decoded['translations'];
	}
}
