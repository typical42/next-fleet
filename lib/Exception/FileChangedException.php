<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * The file an import names is not the one its preview read: its etag moved on. Answered with 409,
 * so the screen previews again rather than importing rows nobody saw.
 */
class FileChangedException extends \RuntimeException {
}
