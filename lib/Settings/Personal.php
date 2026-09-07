<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Settings;

use OCA\NextFleet\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * The app's own block on a user's settings page. It hands over a page and nothing else: what the
 * page then does goes through /api/preferences, exactly as the fleet screen goes through
 * /api/vehicles (docs/adr/0006-one-api-surface-in-v1.md).
 */
class Personal implements ISettings {
	/**
	 * Rendered blank, because the settings page renders this into a section it already owns. The
	 * default is the full user layout, which would put a second page - doctype, head, navigation
	 * and all - inside that section.
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'personal', [], TemplateResponse::RENDER_AS_BLANK);
	}

	/**
	 * Nextcloud's own catch-all section. An app that owns a section registers one, and one
	 * jurisdiction dropdown is not enough to make a page of its own worth the extra class and
	 * icon; it becomes one when the calendar target and the digest cadence join it
	 * (docs/architecture.md#extension-points).
	 */
	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
