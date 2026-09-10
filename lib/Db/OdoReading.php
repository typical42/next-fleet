<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * A vehicle's counter at one moment, one property per column of `fleet_odo_readings`
 * (docs/architecture.md#data-model). `value` is kilometres or engine hours, per the vehicle's
 * `odo_unit`; `readAt` is the user's own instant, so it carries the offset it was read at.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getReadAt()
 * @method void setReadAt(int $readAt)
 * @method int getReadAtOff()
 * @method void setReadAtOff(int $readAtOff)
 * @method int getValue()
 * @method void setValue(int $value)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 * @method int|null getSourceId()
 * @method void setSourceId(?int $sourceId)
 */
class OdoReading extends BaseEntity implements \JsonSerializable {
	/**
	 * An Odometer Entry is its own Reading and carries nothing beyond the number (CONTEXT.md), so
	 * it is a row of the timeline in its own right. Every other source type names the Entry that
	 * wrote the Reading, and that Entry is the row - `trip` is the only one M2 has.
	 */
	public const MANUAL = 'manual';
	public const TRIP = 'trip';

	protected int $vehicleId = 0;
	protected int $readAt = 0;
	protected int $readAtOff = 0;
	protected int $value = 0;
	protected string $kind = '';
	protected string $origin = '';
	protected ?bool $flagged = null;
	protected string $sourceType = '';
	protected ?int $sourceId = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('readAt', Types::BIGINT);
		$this->addType('readAtOff', Types::INTEGER);
		$this->addType('value', Types::BIGINT);
		$this->addType('kind', Types::STRING);
		$this->addType('origin', Types::STRING);
		$this->addType('flagged', Types::BOOLEAN);
		$this->addType('sourceType', Types::STRING);
		$this->addType('sourceId', Types::BIGINT);
	}

	/**
	 * The column is nullable because Nextcloud refuses a NOT NULL boolean, and an unanswered
	 * question is the same fact as an unflagged row.
	 */
	public function getFlagged(): bool {
		return (bool)$this->flagged;
	}

	public function setFlagged(bool $flagged): void {
		$this->setter('flagged', [$flagged]);
	}

	/**
	 * The wire form is the column names, as it is for a vehicle. `flagged` goes out as a real
	 * boolean: the timeline asks the follow-up question off it (docs/ui.md).
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'read_at' => $this->readAt,
			'read_at_off' => $this->readAtOff,
			'value' => $this->value,
			'kind' => $this->kind,
			'origin' => $this->origin,
			'flagged' => $this->getFlagged(),
			'source_type' => $this->sourceType,
			'source_id' => $this->sourceId,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
