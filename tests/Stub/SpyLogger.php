<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Stub;

use Psr\Log\AbstractLogger;

/**
 * Every line at every level, so a test can say a line is the only one.
 */
final class SpyLogger extends AbstractLogger {
	/** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
	public array $lines = [];

	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}
}
