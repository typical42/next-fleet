<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\AppInfo;

use DOMDocument;
use LibXMLError;
use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Settings\ISettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

class InfoXmlTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The app store rejects an app whose info.xml does not validate, and so does
	 * `occ app:enable`. tests/schema/info.xsd is the store's own schema, vendored so
	 * this runs offline.
	 */
	public function testValidatesAgainstTheAppStoreSchema(): void {
		$doc = new DOMDocument();
		$this->assertTrue($doc->load(self::ROOT . '/appinfo/info.xml'), 'appinfo/info.xml is not well-formed');

		$previous = libxml_use_internal_errors(true);
		$valid = $doc->schemaValidate(self::ROOT . '/tests/schema/info.xsd');
		$messages = array_map(
			static fn (LibXMLError $error): string => trim($error->message),
			libxml_get_errors(),
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertTrue($valid, implode("\n", $messages));
	}

	/**
	 * The schema accepts any non-empty string, so it cannot tell a description from a
	 * placeholder. The app store reviewer can.
	 */
	public function testCarriesNoPlaceholders(): void {
		$xml = (string)file_get_contents(self::ROOT . '/appinfo/info.xml');

		$this->assertStringNotContainsString('TODO', $xml);
	}

	/**
	 * docs/legal.md: the licence has to read the same everywhere, and the store's
	 * `agpl` shorthand is deprecated.
	 */
	public function testLicenceMatchesTheRestOfTheRepository(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$this->assertSame('AGPL-3.0-or-later', (string)$info->licence);
		$this->assertSame('AGPL-3.0-or-later', $this->licenceOf('/composer.json'));
		$this->assertSame('AGPL-3.0-or-later', $this->licenceOf('/package.json'));
	}

	/**
	 * `occ upgrade` reads the version from info.xml alone, the bundle and its licence notices
	 * from package.json, and the store shows the CHANGELOG section of the same name. A release
	 * with one of them behind ships a changelog for a version nobody installs. Until the release
	 * the section says `not released` where the date will go.
	 */
	public function testTheVersionIsTheSameEverywhere(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);
		$version = (string)$info->version;

		$lock = json_decode((string)file_get_contents(self::ROOT . '/package-lock.json'), true);
		$this->assertIsArray($lock);

		$this->assertSame($version, $this->manifestOf('/package.json')['version'] ?? null);
		$this->assertSame($version, $lock['version'] ?? null);
		$this->assertSame($version, $lock['packages']['']['version'] ?? null);
		$this->assertMatchesRegularExpression(
			'/^## ' . preg_quote($version, '/') . ' — (\d{4}-\d{2}-\d{2}|not released)$/m',
			(string)file_get_contents(self::ROOT . '/CHANGELOG.md'),
		);
	}

	/**
	 * The store shows the coming version's section and no other unreleased one, so a second
	 * `not released` section is news nobody reads. 0.2.0 and 0.3.0 never went out: 0.3.1, the
	 * first release, says everything the app does (docs/development.md#release).
	 */
	public function testOnlyTheComingVersionIsUnreleased(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		preg_match_all('/^## (\S+) — not released$/m', (string)file_get_contents(self::ROOT . '/CHANGELOG.md'), $unreleased);

		$this->assertSame([], array_values(array_diff($unreleased[1], [(string)$info->version])));
	}

	/**
	 * docs/legal.md: another project's name never stands alone, as a feature or a selling point.
	 * It only says what a file is, as the import screen labels it.
	 */
	public function testNamesOtherProjectsOnlyToSayWhatAFileIs(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		foreach ($info->description as $description) {
			$bare = preg_replace('/\bCSV\s+\((LubeLogger|Spritmonitor)(\s+format|-Format)\)/', '', (string)$description);
			$this->assertDoesNotMatchRegularExpression('/Drivvo|LubeLogger|Spritmonitor/i', (string)$bare);
		}
	}

	/**
	 * CONTEXT.md: giving someone access to a vehicle is a grant, not a share. Nextcloud's own
	 * sharing is a different thing with different rules, so the listing must not promise it.
	 */
	public function testSpeaksOfGrantsNotShares(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		foreach ($info->xpath('/info/summary | /info/description') ?: [] as $text) {
			$this->assertDoesNotMatchRegularExpression('/\bshar(e|es|ed|ing)\b|\bteil(en|t|e)\b/i', (string)$text);
		}
	}

	/** The German listing is a translation, not a second, shorter one. */
	public function testBothLanguagesListTheSameFeatures(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$bullets = [];
		foreach ($info->description as $description) {
			$bullets[(string)($description['lang'] ?? 'en')] = preg_match_all('/^- \*\*/m', (string)$description);
		}

		$this->assertSame(['en', 'de'], array_keys($bullets));
		$this->assertSame($bullets['en'], $bullets['de']);
	}

	/**
	 * The store fetches each screenshot from `main` by URL, so one named here and never taken
	 * is a broken image on the listing, and nothing on our side fails.
	 */
	public function testEveryScreenshotItNamesIsInTheRepository(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$prefix = 'https://raw.githubusercontent.com/typical42/next-fleet/main/';
		$urls = [];
		foreach ($info->screenshot as $screenshot) {
			$urls[] = trim((string)$screenshot);
			$urls[] = (string)$screenshot['small-thumbnail'];
		}

		foreach (array_filter($urls) as $url) {
			$this->assertStringStartsWith($prefix, $url);
			$this->assertFileExists(self::ROOT . '/' . substr($url, strlen($prefix)));
		}
	}

	/**
	 * `occ` learns a command from this file and from nowhere else, so a command class that is
	 * not listed here is a command nobody can run - and the class is loadable, so nothing tells
	 * anyone it exists.
	 */
	public function testEveryConsoleCommandIsRegistered(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$registered = array_map('strval', $info->xpath('/info/commands/command') ?: []);

		// Order is the file's own business - what matters is that the two lists hold the same
		// classes.
		$this->assertEqualsCanonicalizing($this->commandClasses(), $registered);
	}

	/**
	 * The same trap one directory over: Nextcloud learns a settings form from this file alone, so
	 * an ISettings class that is not listed here never reaches a user's settings page - and it is
	 * loadable and green under its own unit test, so nothing says it is unreachable.
	 */
	public function testEveryPersonalSettingIsRegistered(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$this->assertEqualsCanonicalizing(
			$this->settingsClasses(),
			array_map('strval', $info->xpath('/info/settings/personal') ?: []),
		);
	}

	/**
	 * The same again for the job list: an unlisted timed job never runs, and no reminder is sent.
	 * A queued job is added with its argument when there is work for it; listed, it would run once
	 * with none.
	 */
	public function testEveryTimedJobIsRegisteredAndNoQueuedOne(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$jobs = [];
		foreach (glob(self::ROOT . '/lib/BackgroundJob/*.php') ?: [] as $file) {
			$class = 'OCA\\NextFleet\\BackgroundJob\\' . basename($file, '.php');
			$this->assertTrue(is_subclass_of($class, TimedJob::class) || is_subclass_of($class, QueuedJob::class), $class . ' is neither timed nor queued');
			if (is_subclass_of($class, TimedJob::class)) {
				$jobs[] = $class;
			}
		}

		$this->assertNotEmpty($jobs);
		$this->assertEqualsCanonicalizing($jobs, array_map('strval', $info->xpath('/info/background-jobs/job') ?: []));
	}

	/**
	 * Every class under lib/Settings/ the settings manager could render. A stub that does not
	 * implement ISettings yet is not one of them.
	 *
	 * @return list<string>
	 */
	private function settingsClasses(): array {
		$classes = [];
		foreach (glob(self::ROOT . '/lib/Settings/*.php') ?: [] as $file) {
			$class = 'OCA\\NextFleet\\Settings\\' . basename($file, '.php');
			if (is_subclass_of($class, ISettings::class)) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * Every class under lib/Command/ that `occ` could run. A stub that is not a Command yet is
	 * not one of them.
	 *
	 * @return list<string>
	 */
	private function commandClasses(): array {
		$classes = [];
		foreach (glob(self::ROOT . '/lib/Command/*.php') ?: [] as $file) {
			$class = 'OCA\\NextFleet\\Command\\' . basename($file, '.php');
			if (is_subclass_of($class, Command::class)) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	private function licenceOf(string $path): string {
		return (string)($this->manifestOf($path)['license'] ?? '');
	}

	/** @return array<array-key, mixed> */
	private function manifestOf(string $path): array {
		$manifest = json_decode((string)file_get_contents(self::ROOT . $path), true);
		$this->assertIsArray($manifest, $path . ' is not readable JSON');

		return $manifest;
	}
}
