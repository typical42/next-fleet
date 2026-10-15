<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Fixture;

/**
 * The case tables a PHP test and a JS spec both read: a rule written in both languages is held to
 * one list, so the two cannot drift apart unseen.
 */
final class SharedCases {
	/**
	 * @param string $name a JSON file beside this class, without its extension
	 * @return list<array<string, mixed>>
	 */
	public static function read(string $name): array {
		/** @var list<array<string, mixed>> */
		return json_decode((string)file_get_contents(__DIR__ . '/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
	}
}
