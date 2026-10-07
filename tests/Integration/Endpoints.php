<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

/**
 * Where the suite reaches what it reads over HTTP: Mailpit, and the server's own routes.
 *
 * `NEXTFLEET_MAILPIT_URL` and `NEXTFLEET_SERVER_URL` override them. The defaults are the dev
 * stack's (.docker/compose.yml), seen from inside its app container, where the suite runs.
 */
final class Endpoints {
	/** Mailpit's API root, `/api/v1` under its base URL. */
	public static function mailpit(): string {
		return self::from('NEXTFLEET_MAILPIT_URL', 'http://mail:8025') . '/api/v1';
	}

	public static function server(): string {
		return self::from('NEXTFLEET_SERVER_URL', 'http://localhost');
	}

	private static function from(string $variable, string $default): string {
		return rtrim(getenv($variable) ?: $default, '/');
	}
}
