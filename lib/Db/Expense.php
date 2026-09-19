<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One cost that is neither energy nor maintenance, one property per column of `fleet_expenses`
 * (docs/architecture.md#data-model). `amount` is gross cents and `vatRate` basis points or null
 * for "not stated". It knows no counter.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getSpentAt()
 * @method void setSpentAt(int $spentAt)
 * @method int getSpentAtOff()
 * @method void setSpentAtOff(int $spentAtOff)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method int|null getVatRate()
 * @method void setVatRate(?int $vatRate)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 */
class Expense extends BaseEntity implements \JsonSerializable {
	protected int $vehicleId = 0;
	protected int $spentAt = 0;
	protected int $spentAtOff = 0;
	protected ?string $category = null;
	protected int $amount = 0;
	protected ?int $vatRate = null;
	protected ?string $notes = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('spentAt', Types::BIGINT);
		$this->addType('spentAtOff', Types::INTEGER);
		$this->addType('category', Types::STRING);
		$this->addType('amount', Types::BIGINT);
		$this->addType('vatRate', Types::INTEGER);
		$this->addType('notes', Types::STRING);
	}

	/**
	 * The wire form is the column names, as it is for every entity.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'spent_at' => $this->spentAt,
			'spent_at_off' => $this->spentAtOff,
			'category' => $this->category,
			'amount' => $this->amount,
			'vat_rate' => $this->vatRate,
			'notes' => $this->notes,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
