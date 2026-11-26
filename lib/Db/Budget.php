<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * The amount the owner plans for one vehicle in one calendar year (CONTEXT.md, Budget), one
 * property per column of `fleet_budgets`. `amount` is cents in the vehicle's currency, counted net
 * or gross as `net` says, whatever the reader's VAT switch.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method int getWarned()
 * @method void setWarned(int $warned)
 *
 * @psalm-import-type NextFleetBudget from \OCA\NextFleet\ResponseDefinitions
 */
class Budget extends BaseEntity implements \JsonSerializable {
	protected int $vehicleId = 0;
	protected int $year = 0;
	protected int $amount = 0;
	protected ?bool $net = null;
	protected int $warned = 0;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('year', Types::INTEGER);
		$this->addType('amount', Types::BIGINT);
		$this->addType('net', Types::BOOLEAN);
		$this->addType('warned', Types::SMALLINT);
	}

	/** Nullable in the database for the reason `flagged` is on a Reading; false is gross. */
	public function getNet(): bool {
		return (bool)$this->net;
	}

	public function setNet(bool $net): void {
		$this->setter('net', [$net]);
	}

	/**
	 * Without `warned`: what the warning has told is the server's to keep, not a field a client
	 * edits.
	 *
	 * @return NextFleetBudget
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'year' => $this->year,
			'amount' => $this->amount,
			'net' => $this->getNet(),
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
