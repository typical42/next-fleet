<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Integration tests run inside a Nextcloud container, against its database and its classes.
 * See docs/development.md#testing for the command.
 *
 * PHPUnit has already loaded our autoloader, stubs and all. `lib/base.php` puts the server's
 * own in front of it, so `OCP\` resolves to the running server rather than to the pinned
 * stubs - checked, not assumed: tests/Integration/AutoloadingTest.php.
 */
$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($root . '/lib/base.php')) {
	fwrite(STDERR, "No Nextcloud at $root - run this suite inside the container, or point NEXTCLOUD_ROOT at one.\n");
	exit(1);
}

require_once $root . '/lib/base.php';
