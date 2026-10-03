<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * A file an import will not read at all. The reason is a word the screen puts into words; the row
 * is where reading stopped, null when no row is to blame.
 */
class ImportRefusedException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		public readonly ?int $row = null,
	) {
		parent::__construct($row === null ? $reason : $reason . ' at row ' . $row);
	}
}
