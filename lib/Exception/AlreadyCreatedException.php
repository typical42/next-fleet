<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Exception;

/**
 * A create sent again under the client uuid of a row it already wrote (Service\Once). Not a
 * refusal: answered with 200 and what the first create answered, built from the row as it stands.
 */
class AlreadyCreatedException extends \RuntimeException {
	/**
	 * @param mixed $answer what the create returns, the row in the form its service hands out
	 */
	public function __construct(
		public readonly mixed $answer,
	) {
		parent::__construct('Created already');
	}
}
