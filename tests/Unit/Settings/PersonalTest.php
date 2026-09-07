<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Settings;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Settings\Personal;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use PHPUnit\Framework\TestCase;

/**
 * The form Nextcloud puts into a user's own settings. It carries no logic - the screen it renders
 * talks to /api/preferences like any other client - so what is worth checking is that the
 * framework can place it and that it names a page that exists.
 */
class PersonalTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	/** Nextcloud calls nothing that is not this interface, so a form that misses it never shows. */
	public function testItIsWhatTheSettingsManagerCanRender(): void {
		$this->assertInstanceOf(ISettings::class, new Personal());
	}

	/** A template Nextcloud cannot find renders as an exception on the user's settings page. */
	public function testItRendersATemplateThisAppShips(): void {
		$form = (new Personal())->getForm();

		$this->assertSame(Application::APP_ID, $form->getApp());
		$this->assertFileExists(self::ROOT . '/templates/' . $form->getTemplateName() . '.php');
	}

	/**
	 * The settings page renders this into a section of its own page. A response's default is the
	 * whole user layout, which would nest a second page - doctype, navigation and all - inside
	 * that section, and nothing about the result would throw.
	 */
	public function testItRendersIntoTheSettingsPageRatherThanAroundIt(): void {
		$this->assertSame(TemplateResponse::RENDER_AS_BLANK, (new Personal())->getForm()->getRenderAs());
	}

	/**
	 * A form whose section nothing registers is a form the settings page drops on the floor, and
	 * a priority outside 0-100 is one the manager refuses to sort.
	 */
	public function testItIsPlaceableOnThePersonalSettingsPage(): void {
		$personal = new Personal();

		$this->assertNotSame('', $personal->getSection());
		$this->assertGreaterThanOrEqual(0, $personal->getPriority());
		$this->assertLessThanOrEqual(100, $personal->getPriority());
	}
}
