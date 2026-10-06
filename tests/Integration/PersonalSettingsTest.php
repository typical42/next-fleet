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
 * That the settings form is really registered: a `<personal>` entry in `appinfo/info.xml` the
 * server does not read is a form nobody sees, however green the unit test
 * (tests/Unit/Settings/PersonalTest.php).
 */
class PersonalSettingsTest extends TestCase {
	/**
	 * Asked of the app manager, not the settings manager: listing the personal forms builds every
	 * other app's too, and some want a session (`WebAuthn` wants a user id) a suite has not got.
	 */
	public function testTheServerReadsTheFormOutOfTheAppInfo(): void {
		$info = \OCP\Server::get(IAppManager::class)->getAppInfo(Application::APP_ID);

		$this->assertSame([Personal::class], $info['settings']['personal'] ?? []);
	}

	public function testTheFormIsBuiltByTheContainer(): void {
		$form = \OCP\Server::get(Personal::class);

		$this->assertInstanceOf(ISettings::class, $form);
		$this->assertSame('personal', $form->getForm()->getTemplateName());
	}
}
