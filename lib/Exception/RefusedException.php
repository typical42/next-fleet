<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * A 400 like any refused field, told apart by a reason word so a client can word it in the
 * driver's language (docs/api.md). The message stays English, for a log.
 */
class RefusedException extends \InvalidArgumentException {
	public function __construct(
		string $message,
		public readonly string $reason,
	) {
		parent::__construct($message);
	}
}
