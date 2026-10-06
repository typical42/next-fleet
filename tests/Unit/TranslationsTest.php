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
 * Both languages are first-class, so a string that reaches the screen untranslated is a defect
 * (docs/ui.md#languages).
 */
class TranslationsTest extends TestCase {
	private const LANGUAGES = ['en', 'de', 'de_DE'];

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
	 * The `.js` catalogue is what the browser loads and the `.json` one is what PHP reads
	 * (docs/ui.md#languages). They are two files holding one fact, so a hand edit to either has to
	 * reach the other: the same app, the same plural rule, and the same entries key by key.
	 */
	public function testTheBrowsersCatalogueSaysWhatTheToolsCatalogueSays(): void {
		foreach (self::LANGUAGES as $language) {
			$registered = self::registered($language);
			$json = self::decoded($language);

			$this->assertSame(Application::APP_ID, $registered['app'], $language . '.js registers another app');
			$this->assertSame($json['pluralForm'], $registered['pluralForm'], $language . '.js counts plurals otherwise');
			$expected = $json['translations'];
			$actual = $registered['translations'];
			ksort($expected);
			ksort($actual);
			$this->assertSame($expected, $actual, $language . '.js and ' . $language . '.json differ');
		}
	}

	/**
	 * A translation that drops `{name}` or `%1$s` shows the reader a sentence without its subject,
	 * and one that adds a placeholder shows them the braces.
	 */
	public function testEveryTranslationKeepsThePlaceholdersOfItsSource(): void {
		$placeholders = static function (string $text): array {
			preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}|%(?:\d+\$)?[sd]/', $text, $matches);
			sort($matches[0]);

			return $matches[0];
		};

		foreach (self::LANGUAGES as $language) {
			foreach (self::catalogue($language) as $source => $translation) {
				$this->assertSame($placeholders($source), $placeholders($translation), $language . ' changes the placeholders of: ' . $source);
			}
		}
	}

	/**
	 * The `.js` catalogue as the browser registers it: run in Node against a stub of
	 * `OC.L10N.register`, so what is compared is what the browser would load, quoting and escapes
	 * included.
	 *
	 * @return array{app: string, translations: array<string, string>, pluralForm: string}
	 */
	private static function registered(string $language): array {
		$script = 'const fs = require("fs"), vm = require("vm"); let got = null;'
			. 'const OC = { L10N: { register: (app, translations, pluralForm) => { got = { app, translations, pluralForm } } } };'
			. 'vm.runInNewContext(fs.readFileSync(process.argv[1], "utf8"), { OC });'
			. 'process.stdout.write(JSON.stringify(got));';
		$process = proc_open(['node', '-e', $script, self::root() . '/l10n/' . $language . '.js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process, 'node did not start');
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		self::assertSame(0, proc_close($process), $language . '.js does not run: ' . $err);

		/** @var array{app: string, translations: array<string, string>, pluralForm: string} */
		return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * `de_DE` says Sie (docs/ui.md#languages). A du-imperative needs no pronoun, so the check also
	 * knows the ones `de` uses; extend the list when `de` gains another.
	 */
	public function testTheFormalCatalogueNeverSaysDu(): void {
		// (*UCP): without it \b takes "ü" for a word's edge.
		$du = '/(*UCP)\b(du|dich|dir|dein\w*|wähle|korrigiere|zeige|tippe|buche|erfasse|leg|hänge|richte|speichere|'
			. 'sag|versuch es|prüfe|importiere|beantworte|lösche|trag|gib)\b/iu';

		foreach (self::catalogue('de_DE') as $source => $translation) {
			$this->assertDoesNotMatchRegularExpression($du, $translation, 'de_DE says du in: ' . $source);
		}
	}

	/**
	 * `de` says du. "Ihr" or a "Sie" inside a sentence reads as the formal address there, even
	 * where it was meant as "its" or "they".
	 */
	public function testTheInformalCatalogueNeverSaysSie(): void {
		$sie = '/(*UCP)\b(Ihnen|Ihr\w*)\b|(?<![.!?:]\s)(?<!^)\bSie\b/u';

		foreach (self::catalogue('de') as $source => $translation) {
			$this->assertDoesNotMatchRegularExpression($sie, $translation, 'de says Sie in: ' . $source);
		}
	}

	/**
	 * Every string the Vue sources hand to `t()`, and every one the PHP sources hand to an
	 * IL10N's `->t()` - the notifier's, which translate late (docs/architecture.md#reminder-engine).
	 * The app id is spelled out in the Vue pattern because a call naming another app would be
	 * translated from another catalogue.
	 *
	 * @return list<string>
	 */
	private static function markedStrings(): array {
		$quoted = '\'((?:[^\'\\\\]|\\\\.)*)\'';
		$patterns = [
			'/src' => ['/\.(js|vue)$/', '/\bt\(\s*\'' . preg_quote(Application::APP_ID, '/') . '\'\s*,\s*' . $quoted . '/'],
			'/lib' => ['/\.php$/', '/->t\(\s*' . $quoted . '/'],
		];

		$strings = [];
		foreach ($patterns as $directory => [$files, $pattern]) {
			$sources = new \RegexIterator(
				new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . $directory)),
				$files,
			);
			foreach ($sources as $source) {
				preg_match_all($pattern, (string)file_get_contents((string)$source), $matches);
				$strings = array_merge($strings, $matches[1]);
			}
		}

		return array_values(array_unique($strings));
	}

	/** @return array<string, string> */
	private static function catalogue(string $language): array {
		return self::decoded($language)['translations'];
	}

	/** @return array{translations: array<string, string>, pluralForm: string} */
	private static function decoded(string $language): array {
		/** @var array{translations: array<string, string>, pluralForm: string} */
		return json_decode(
			(string)file_get_contents(self::root() . '/l10n/' . $language . '.json'),
			true,
			flags: JSON_THROW_ON_ERROR,
		);
	}
}
