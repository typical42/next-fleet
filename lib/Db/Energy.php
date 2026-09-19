<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One fill-up or charging session, one property per column of `fleet_energy`
 * (docs/architecture.md#data-model). `amount` is millilitres or watt-hours per `energy`; money is
 * gross cents, `unitPrice` tenths of a cent per litre or kWh, `vatRate` basis points or null for
 * "not stated". Either counter is optional, and the Reading it writes is its own row.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getFilledAt()
 * @method void setFilledAt(int $filledAt)
 * @method int getFilledAtOff()
 * @method void setFilledAtOff(int $filledAtOff)
 * @method int|null getOdo()
 * @method void setOdo(?int $odo)
 * @method int|null getSecondOdo()
 * @method void setSecondOdo(?int $secondOdo)
 * @method string getEnergy()
 * @method void setEnergy(string $energy)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method int|null getUnitPrice()
 * @method void setUnitPrice(?int $unitPrice)
 * @method int|null getTotal()
 * @method void setTotal(?int $total)
 * @method int|null getVatRate()
 * @method void setVatRate(?int $vatRate)
 * @method string|null getStation()
 * @method void setStation(?string $station)
 * @method string|null getLocationKind()
 * @method void setLocationKind(?string $locationKind)
 */
class Energy extends BaseEntity implements \JsonSerializable {
	protected int $vehicleId = 0;
	protected int $filledAt = 0;
	protected int $filledAtOff = 0;
	protected ?int $odo = null;
	protected ?int $secondOdo = null;
	protected string $energy = '';
	protected int $amount = 0;
	protected ?int $unitPrice = null;
	protected ?int $total = null;
	protected ?int $vatRate = null;
	protected ?bool $fullTank = null;
	protected ?bool $missedPrevious = null;
	protected ?string $station = null;
	protected ?bool $isDc = null;
	protected ?string $locationKind = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('filledAt', Types::BIGINT);
		$this->addType('filledAtOff', Types::INTEGER);
		$this->addType('odo', Types::BIGINT);
		$this->addType('secondOdo', Types::BIGINT);
		$this->addType('energy', Types::STRING);
		$this->addType('amount', Types::BIGINT);
		$this->addType('unitPrice', Types::BIGINT);
		$this->addType('total', Types::BIGINT);
		$this->addType('vatRate', Types::INTEGER);
		$this->addType('fullTank', Types::BOOLEAN);
		$this->addType('missedPrevious', Types::BOOLEAN);
		$this->addType('station', Types::STRING);
		$this->addType('isDc', Types::BOOLEAN);
		$this->addType('locationKind', Types::STRING);
	}

	/**
	 * The three booleans are nullable in the database for the reason `flagged` is on a Reading,
	 * and false is what an untouched column means.
	 */
	public function getFullTank(): bool {
		return (bool)$this->fullTank;
	}

	public function setFullTank(bool $fullTank): void {
		$this->setter('fullTank', [$fullTank]);
	}

	public function getMissedPrevious(): bool {
		return (bool)$this->missedPrevious;
	}

	public function setMissedPrevious(bool $missedPrevious): void {
		$this->setter('missedPrevious', [$missedPrevious]);
	}

	public function getIsDc(): bool {
		return (bool)$this->isDc;
	}

	public function setIsDc(bool $isDc): void {
		$this->setter('isDc', [$isDc]);
	}

	/**
	 * The wire form is the column names, as it is for every entity.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'filled_at' => $this->filledAt,
			'filled_at_off' => $this->filledAtOff,
			'odo' => $this->odo,
			'second_odo' => $this->secondOdo,
			'energy' => $this->energy,
			'amount' => $this->amount,
			'unit_price' => $this->unitPrice,
			'total' => $this->total,
			'vat_rate' => $this->vatRate,
			'full_tank' => $this->getFullTank(),
			'missed_previous' => $this->getMissedPrevious(),
			'station' => $this->station,
			'is_dc' => $this->getIsDc(),
			'location_kind' => $this->locationKind,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
