<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One journey, one property per column of `fleet_trips` (docs/architecture.md#data-model). Both
 * ends are the user's own instants, so each carries the offset it was entered at; `startOdo` is a
 * claim about the counter and not a Reading, which is what gap detection measures against.
 *
 * Either `endOdo` or `distance` is what the driver entered, never both, and the other one stays
 * null - the counter is not restated into a distance nor the distance into a counter. What is
 * derived is the Reading written at `endedAt`, whose `origin` says which of the two it came from.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getStartedAtOff()
 * @method void setStartedAtOff(int $startedAtOff)
 * @method int getEndedAt()
 * @method void setEndedAt(int $endedAt)
 * @method int getEndedAtOff()
 * @method void setEndedAtOff(int $endedAtOff)
 * @method int|null getStartOdo()
 * @method void setStartOdo(?int $startOdo)
 * @method int|null getEndOdo()
 * @method void setEndOdo(?int $endOdo)
 * @method int|null getDistance()
 * @method void setDistance(?int $distance)
 * @method string|null getFromLabel()
 * @method void setFromLabel(?string $fromLabel)
 * @method string|null getToLabel()
 * @method void setToLabel(?string $toLabel)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string|null getPartner()
 * @method void setPartner(?string $partner)
 * @method string getCategory()
 * @method void setCategory(string $category)
 */
class Trip extends BaseEntity implements \JsonSerializable {
	public const BUSINESS = 'business';
	public const PRIVATE = 'private';
	public const COMMUTE = 'commute';

	protected int $vehicleId = 0;
	protected int $startedAt = 0;
	protected int $startedAtOff = 0;
	protected int $endedAt = 0;
	protected int $endedAtOff = 0;
	protected ?int $startOdo = null;
	protected ?int $endOdo = null;
	protected ?int $distance = null;
	protected ?string $fromLabel = null;
	protected ?string $toLabel = null;
	protected ?string $purpose = null;
	protected ?string $partner = null;
	protected string $category = '';
	protected ?bool $reconciled = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('startedAt', Types::BIGINT);
		$this->addType('startedAtOff', Types::INTEGER);
		$this->addType('endedAt', Types::BIGINT);
		$this->addType('endedAtOff', Types::INTEGER);
		$this->addType('startOdo', Types::BIGINT);
		$this->addType('endOdo', Types::BIGINT);
		$this->addType('distance', Types::BIGINT);
		$this->addType('fromLabel', Types::STRING);
		$this->addType('toLabel', Types::STRING);
		$this->addType('purpose', Types::STRING);
		$this->addType('partner', Types::STRING);
		$this->addType('category', Types::STRING);
		$this->addType('reconciled', Types::BOOLEAN);
	}

	/**
	 * True only of a Reconciliation Trip - one created to close a Gap. Nullable in the database
	 * for the reason `flagged` is on a Reading, and false is what an untouched column means.
	 */
	public function getReconciled(): bool {
		return (bool)$this->reconciled;
	}

	public function setReconciled(bool $reconciled): void {
		$this->setter('reconciled', [$reconciled]);
	}

	/**
	 * The wire form is the column names, as it is for a vehicle and a reading. `reconciled` goes
	 * out as a real boolean: the timeline marks a Reconciliation Trip as one.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'started_at' => $this->startedAt,
			'started_at_off' => $this->startedAtOff,
			'ended_at' => $this->endedAt,
			'ended_at_off' => $this->endedAtOff,
			'start_odo' => $this->startOdo,
			'end_odo' => $this->endOdo,
			'distance' => $this->distance,
			'from_label' => $this->fromLabel,
			'to_label' => $this->toLabel,
			'purpose' => $this->purpose,
			'partner' => $this->partner,
			'category' => $this->category,
			'reconciled' => $this->getReconciled(),
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
