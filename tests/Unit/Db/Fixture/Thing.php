<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db\Fixture;

use OCA\NextFleet\Db\BaseEntity;
use OCP\DB\Types;

/**
 * The smallest entity BaseMapper can carry: the five common columns plus one of its own, so a
 * test can tell a stamped column from a payload column.
 *
 * @method string getLabel()
 * @method void setLabel(string $label)
 */
class Thing extends BaseEntity {
	protected string $label = '';

	public function __construct() {
		parent::__construct();
		$this->addType('label', Types::STRING);
	}
}
