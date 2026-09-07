<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use OCA\NextFleet\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use RecordedTemplateScripts;

/**
 * The settings page is a second bundle on a page this app does not own, so the three things that
 * would leave it blank without an error are each pinned here: the element, the script, and the
 * Vite entry that has to exist for the script to be built at all.
 */
class PersonalTemplateTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	private const BUNDLE = Application::APP_ID . '-settings';

	protected function setUp(): void {
		require_once self::ROOT . '/tests/Stub/template_functions.php';
		RecordedTemplateScripts::$requested = [];
		RecordedTemplateScripts::$styled = [];
	}

	private function render(): string {
		ob_start();
		require self::ROOT . '/templates/personal.php';

		return (string)ob_get_clean();
	}

	/** As in main.php: the entry mounts by id and stays silent when the id is not there. */
	public function testTheTemplateRendersTheElementTheBundleMountsInto(): void {
		$entry = (string)file_get_contents(self::ROOT . '/src/settings.js');
		$this->assertSame(1, preg_match('/getElementById\(\'([^\']+)\'\)/', $entry, $found));

		$this->assertStringContainsString('id="' . $found[1] . '"', $this->render());
	}

	/**
	 * The app's own page and its settings page are two bundles, and asking for the wrong one puts
	 * the whole fleet into a settings section.
	 */
	public function testTheTemplateAsksForTheSettingsBundleAndItsStylesheet(): void {
		$this->render();

		$this->assertSame([Application::APP_ID . '/' . self::BUNDLE], RecordedTemplateScripts::$requested);
		$this->assertSame([Application::APP_ID . '/' . self::BUNDLE], RecordedTemplateScripts::$styled);
	}

	/**
	 * Vite names each output after its entry key, so a template asking for a bundle no entry
	 * declares is a 404 in the console and an empty settings section.
	 */
	public function testTheBundleTheTemplateAsksForIsOneTheBuildProduces(): void {
		$this->render();
		$config = (string)file_get_contents(self::ROOT . '/vite.config.js');

		foreach (RecordedTemplateScripts::$requested as $requested) {
			$entry = str_replace(Application::APP_ID . '/' . Application::APP_ID . '-', '', $requested);
			$this->assertMatchesRegularExpression(
				'/\b' . preg_quote($entry, '/') . ':\s*resolve\(/',
				$config,
				'vite.config.js declares no "' . $entry . '" entry, so ' . $requested . ' is never built',
			);
		}
	}
}
