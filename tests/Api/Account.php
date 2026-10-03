<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

/** A login as a client holds it: the account and an app password, never the login password. */
final class Account {
	public function __construct(
		public readonly string $uid,
		public readonly string $password,
	) {
	}
}
