<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One maintenance record, one property per column of `fleet_maintenance`
 * (docs/architecture.md#data-model). `type` is one kind of it, "service" among them; `cost` is
 * gross cents and `vatRate` basis points or null for "not stated".
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method int getDoneAt()
 * @method void setDoneAt(int $doneAt)
 * @method int getDoneAtOff()
 * @method void setDoneAtOff(int $doneAtOff)
 * @method int|null getOdo()
 * @method void setOdo(?int $odo)
 * @method int|null getSecondOdo()
 * @method void setSecondOdo(?int $secondOdo)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getVendor()
 * @method void setVendor(?string $vendor)
 * @method int|null getCost()
 * @method void setCost(?int $cost)
 * @method int|null getVatRate()
 * @method void setVatRate(?int $vatRate)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 */
class Maintenance extends BaseEntity implements \JsonSerializable {
	protected int $vehicleId = 0;
	protected ?string $type = null;
	protected int $doneAt = 0;
	protected int $doneAtOff = 0;
	protected ?int $odo = null;
	protected ?int $secondOdo = null;
	protected string $title = '';
	protected ?string $vendor = null;
	protected ?int $cost = null;
	protected ?int $vatRate = null;
	protected ?string $notes = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('type', Types::STRING);
		$this->addType('doneAt', Types::BIGINT);
		$this->addType('doneAtOff', Types::INTEGER);
		$this->addType('odo', Types::BIGINT);
		$this->addType('secondOdo', Types::BIGINT);
		$this->addType('title', Types::STRING);
		$this->addType('vendor', Types::STRING);
		$this->addType('cost', Types::BIGINT);
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
			'type' => $this->type,
			'done_at' => $this->doneAt,
			'done_at_off' => $this->doneAtOff,
			'odo' => $this->odo,
			'second_odo' => $this->secondOdo,
			'title' => $this->title,
			'vendor' => $this->vendor,
			'cost' => $this->cost,
			'vat_rate' => $this->vatRate,
			'notes' => $this->notes,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
