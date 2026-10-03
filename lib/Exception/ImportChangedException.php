<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * An entry an import's undo names is not one the import left: deleted since, on another vehicle,
 * entered by somebody else, or a Reading a fill-up wrote. Answered with 409.
 */
class ImportChangedException extends \RuntimeException {
}
