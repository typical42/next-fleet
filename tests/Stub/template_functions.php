<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Nextcloud hands every template a global `script()`; a unit test has no server to load it
 * from and rendering the template without it is a fatal. The calls are recorded so a test
 * can assert which bundle the page asks for.
 */
final class RecordedTemplateScripts {
	/** @var list<string> */
	public static array $requested = [];
}

if (!function_exists('script')) {
	function script(string $app, string $file): void {
		RecordedTemplateScripts::$requested[] = $app . '/' . $file;
	}
}
