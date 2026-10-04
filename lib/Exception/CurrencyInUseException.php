<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * A vehicle's currency changed after an amount was recorded in it (docs/architecture.md#data-model).
 */
class CurrencyInUseException extends RefusedException {
	public const REASON = 'currency_in_use';

	public function __construct(string $message) {
		parent::__construct($message, self::REASON);
	}
}
