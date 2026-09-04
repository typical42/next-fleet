<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\NextFleet\AppInfo\Application;

// The bundle is requested from the template rather than from PageController, because the
// OCP call behind script() reaches into the server container and would put the controller
// out of reach of a unit test.
script(Application::APP_ID, Application::APP_ID . '-main');
?>
<div id="nextfleet"></div>
