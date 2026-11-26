<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One cost centre in its owner's list, one property per column of `fleet_cost_centres`
 * (CONTEXT.md, Cost Centre). Trips, fill-ups, maintenance records and expenses name one
 * (CostCentred), and a vehicle names the one a new entry takes.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getCode()
 * @method void setCode(?string $code)
 * @method string getName()
 * @method void setName(string $name)
 */
class CostCentre extends BaseEntity {
	/** The columns' lengths, in characters. */
	public const CODE_LENGTH = 32;
	public const NAME_LENGTH = 255;

	protected string $userId = '';
	protected ?string $code = null;
	protected string $name = '';
	protected ?bool $archived = null;

	public function __construct() {
		parent::__construct();
		$this->addType('userId', Types::STRING);
		$this->addType('code', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('archived', Types::BOOLEAN);
	}

	/**
	 * No longer offered, but kept on the entries that name it and in Statistics. Nullable in the
	 * database for the reason `flagged` is on a Reading, and false is what an untouched column
	 * means.
	 */
	public function getArchived(): bool {
		return (bool)$this->archived;
	}

	public function setArchived(bool $archived): void {
		$this->setter('archived', [$archived]);
	}
}
