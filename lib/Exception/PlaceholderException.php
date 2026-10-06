<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * A request body names a route placeholder too, with another value. Answered with 400 by
 * PlaceholderMiddleware.
 */
class PlaceholderException extends \RuntimeException {
}
