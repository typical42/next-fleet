<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\NextFleet\AppInfo\Application;

// A settings page is somebody else's page, so it gets its own bundle rather than the app's:
// mounting the fleet inside a settings section would load every view for one dropdown.
script(Application::APP_ID, Application::APP_ID . '-settings');
style(Application::APP_ID, Application::APP_ID . '-settings');
?>
<div id="nextfleet-settings"></div>
