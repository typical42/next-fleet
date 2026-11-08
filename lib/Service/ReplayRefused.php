<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * A later delete an undo cannot replay (ReminderService::replay()), or an undo the replay would
 * leave closing nothing (MaintenanceService::undelete()). Beside the services, not in
 * Exception/: no caller ever sees it, since MaintenanceService::restore() catches it, rolls back
 * and restores the record alone.
 */
final class ReplayRefused extends \RuntimeException {
}
