<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Settings\Personal;
use OCP\App\IAppManager;
use OCP\Settings\ISettings;
use PHPUnit\Framework\TestCase;

/**
 * That the settings form is really registered. `appinfo/info.xml` is data the server parses on
 * its own terms, and a `<personal>` entry it does not read is a form nobody ever sees - green
 * unit tests and all (tests/Unit/Settings/PersonalTest.php).
 */
class PersonalSettingsTest extends TestCase {
	/**
	 * The server's own reading of info.xml, not ours. Asked of the app manager rather than of the
	 * settings manager, because listing the personal forms builds every other app's too and some
	 * of them want a session (`WebAuthn` wants a user id) that a suite has not got.
	 */
	public function testTheServerReadsTheFormOutOfTheAppInfo(): void {
		$info = \OCP\Server::get(IAppManager::class)->getAppInfo(Application::APP_ID);

		$this->assertSame([Personal::class], $info['settings']['personal'] ?? []);
	}

	/** The form the manager builds is the app's own, built by the container from nothing. */
	public function testTheFormIsBuiltByTheContainer(): void {
		$form = \OCP\Server::get(Personal::class);

		$this->assertInstanceOf(ISettings::class, $form);
		$this->assertSame('personal', $form->getForm()->getTemplateName());
	}
}
