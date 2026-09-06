<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Nextcloud hands every template globals `script()` and `style()`; a unit test has no server to
 * load them from and rendering the template without them is a fatal. The calls are recorded so a
 * test can assert which bundle and which stylesheet the page asks for.
 */
final class RecordedTemplateScripts {
	/** @var list<string> */
	public static array $requested = [];

	/** @var list<string> */
	public static array $styled = [];
}

if (!function_exists('script')) {
	function script(string $app, string $file): void {
		RecordedTemplateScripts::$requested[] = $app . '/' . $file;
	}
}

if (!function_exists('style')) {
	function style(string $app, string $file): void {
		RecordedTemplateScripts::$styled[] = $app . '/' . $file;
	}
}
