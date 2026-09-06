<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * Someone asked for a vehicle they hold no grant on (VehicleAccess). It carries no message worth
 * showing: what the row is, and whether it is there at all, is exactly what the answer withholds.
 */
class AccessDeniedException extends \RuntimeException {
}
