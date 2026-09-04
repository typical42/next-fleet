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

class MainTemplateTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	protected function setUp(): void {
		require_once self::ROOT . '/tests/Stub/template_functions.php';
		RecordedTemplateScripts::$requested = [];
	}

	private function render(): string {
		ob_start();
		require self::ROOT . '/templates/main.php';

		return (string)ob_get_clean();
	}

	/**
	 * src/main.js looks the element up by id and does nothing when it is absent, so a
	 * template that names it differently is a blank page with a clean console — which is
	 * exactly what the M0 gate would otherwise read as a pass.
	 */
	public function testTheTemplateRendersTheElementTheBundleMountsInto(): void {
		$main = (string)file_get_contents(self::ROOT . '/src/main.js');
		$this->assertSame(1, preg_match('/getElementById\(\'([^\']+)\'\)/', $main, $found));

		$this->assertStringContainsString('id="' . $found[1] . '"', $this->render());
	}

	/**
	 * Nothing else asks the server for the bundle, so a page that skips this loads no
	 * JavaScript at all.
	 */
	public function testTheTemplateAsksForTheAppsOwnBundle(): void {
		$this->render();

		$this->assertSame(
			[Application::APP_ID . '/' . Application::APP_ID . '-main'],
			RecordedTemplateScripts::$requested,
		);
	}
}
