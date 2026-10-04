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
 *
 * @psalm-import-type NextFleetReading from \OCA\NextFleet\ResponseDefinitions
 */
class OdoReading extends BaseEntity implements \JsonSerializable {
	/**
	 * An Odometer Entry is its own Reading and carries nothing beyond the number (CONTEXT.md), so
	 * it is a row of the timeline in its own right. Every other source type names the Entry that
	 * wrote the Reading, and that Entry is the row.
	 */
	public const MANUAL = 'manual';
	public const TRIP = 'trip';
	public const ENERGY = 'energy';
	public const MAINTENANCE = 'maintenance';

	/**
	 * Which of a vehicle's counters the Reading is on: the one `odo_unit` names, or the engine
	 * hours `second_unit` adds beside it. Each is a chain of its own.
	 */
	public const MAIN = 'main';
	public const SECOND = 'second';

	/**
	 * What a Reading is (`kind`): read off the counter, or the answer "the counter was replaced"
	 * to one the chain questioned. A reset stands whatever came before it and starts a new segment
	 * (docs/architecture.md#odometer-rules, rule 3).
	 */
	public const READING = 'reading';
	public const RESET = 'reset';

	protected int $vehicleId = 0;
	protected int $readAt = 0;
	protected int $readAtOff = 0;
	protected int $value = 0;
	protected string $kind = '';
	protected string $origin = '';
	protected ?bool $flagged = null;
	protected string $sourceType = '';
	protected ?int $sourceId = null;
	protected ?string $counter = null;

	/**
	 * The uuid of the Entry `source_id` names, so a client can tie the Reading to it. Not a
	 * column: looked up for a page at once (OdoReadingMapper::nameSources()), and private so the
	 * entity's magic setter never marks it for an INSERT. Null for an Odometer Entry.
	 */
	private ?string $sourceUuid = null;

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
		$this->addType('counter', Types::STRING);
	}

	/**
	 * Null is `main`: the column arrived with M3, and every Reading written before it is on the
	 * only counter there was.
	 */
	public function getCounter(): string {
		return $this->counter ?? self::MAIN;
	}

	public function setCounter(string $counter): void {
		$this->setter('counter', [$counter]);
	}

	/**
	 * Where the Reading stands in its chain, comparable with `<=>`: OdoReadingMapper::findChain()'s
	 * order, `id` breaking a tie in time (docs/architecture.md#odometer-rules, rule 1).
	 *
	 * @return array{int, int}
	 */
	public function place(): array {
		return [$this->readAt, (int)$this->id];
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

	public function getSourceUuid(): ?string {
		return $this->sourceUuid;
	}

	public function setSourceUuid(?string $sourceUuid): void {
		$this->sourceUuid = $sourceUuid;
	}

	/**
	 * The wire form is the column names, as it is for a vehicle, and `source_uuid` beside them.
	 * `flagged` goes out as a real boolean: the timeline asks the follow-up question off it
	 * (docs/ui.md).
	 *
	 * @return NextFleetReading
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
			'source_uuid' => $this->sourceUuid,
			'counter' => $this->getCounter(),
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
