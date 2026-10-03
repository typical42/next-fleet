<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * One cell of an import that is not the value its column holds. The reason is a word the screen
 * puts into words. It costs the row, never the file: that is ImportRefusedException.
 */
class UnreadableCellException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		/** The header to blame: Values reads a bare cell and leaves it to Cells to name one. */
		public readonly ?string $column = null,
	) {
		parent::__construct($reason);
	}
}
