<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * The row moved on since the client read it. Answered with 412, never by overwriting
 * (docs/architecture.md#concurrency).
 */
class StaleUpdateException extends \RuntimeException {
}
