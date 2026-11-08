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
 * @method int|null getReminderId()
 * @method void setReminderId(?int $reminderId)
 * @method \DateTime|null getClosedDueDate()
 * @method void setClosedDueDate(\DateTime|string|null $closedDueDate)
 * @method int|null getClosedDueOdo()
 * @method void setClosedDueOdo(?int $closedDueOdo)
 * @method \DateTime|null getClosedSnoozedUntil()
 * @method void setClosedSnoozedUntil(\DateTime|string|null $closedSnoozedUntil)
 * @method int|null getClosedOccurrence()
 * @method void setClosedOccurrence(?int $closedOccurrence)
 * @method \DateTime|null getNextSnoozedUntil()
 * @method void setNextSnoozedUntil(\DateTime|string|null $nextSnoozedUntil)
 * @method int|null getHandedTo()
 * @method void setHandedTo(?int $handedTo)
 * @method int|null getKeptOccurrence()
 * @method void setKeptOccurrence(?int $keptOccurrence)
 * @method \DateTime|null getNextDueDate()
 * @method void setNextDueDate(\DateTime|string|null $nextDueDate)
 * @method int|null getNextDueOdo()
 * @method void setNextDueOdo(?int $nextDueOdo)
 * @method bool|null getTookBack()
 * @method void setTookBack(?bool $tookBack)
 *
 * @psalm-import-type NextFleetMaintenanceEntry from \OCA\NextFleet\ResponseDefinitions
 */
class Maintenance extends BaseEntity implements \JsonSerializable {
	/**
	 * The properties that hold the state of the reminder this record closed rather than what the
	 * record says: the other records that close it write them too.
	 *
	 * - what the reminder held before the close, which the record keeps: `closedDueDate`,
	 *   `closedDueOdo`, `closedSnoozedUntil`, `closedOccurrence`;
	 * - the due the close sets: `nextDueDate`, `nextDueOdo`;
	 * - what the record's own delete sets, for its undo: `nextSnoozedUntil`, `handedTo`, `tookBack`;
	 * - what a record below hands up besides the kept values: `keptOccurrence`.
	 */
	public const KEPT = ['closedDueDate', 'closedDueOdo', 'closedSnoozedUntil', 'closedOccurrence', 'nextSnoozedUntil', 'handedTo', 'keptOccurrence', 'nextDueDate', 'nextDueOdo', 'tookBack'];

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
	/** The reminder this record closed. An id, so it stays off the wire. */
	protected ?int $reminderId = null;
	/**
	 * What that reminder held just before this record closed it, so taking the close back puts
	 * it back. Null where nothing was kept: on a record from before they were kept, on one that
	 * closes no reminder, and for an axis the reminder did not have. Inside only, as the id is.
	 */
	protected ?\DateTime $closedDueDate = null;
	protected ?int $closedDueOdo = null;
	protected ?\DateTime $closedSnoozedUntil = null;
	/**
	 * The reminder's occurrence just before this record closed it. Every close sets it, so null
	 * marks a record from before it was kept, or one that closes no reminder.
	 */
	protected ?int $closedOccurrence = null;
	/**
	 * The snooze of the occurrence this record's close started, as it stood when the close was
	 * taken back, so an undo gives it back. Null while the close is in effect.
	 */
	protected ?\DateTime $nextSnoozedUntil = null;
	/**
	 * The record that took over what this one kept when taking the close back handed it up, so an
	 * undo gives that record back what it kept before. Null otherwise. An id, as `reminderId` is.
	 */
	protected ?int $handedTo = null;
	/**
	 * The occurrence of the due this record keeps, where a record below handed that due up. Null
	 * means `closedOccurrence`.
	 */
	protected ?int $keptOccurrence = null;
	/**
	 * The due this record's close set on each axis: what the reminder held right after it. Null on
	 * an axis the reminder did not recur by, and on both for a one-off, a record from before and one
	 * that closes no reminder.
	 * Taking the close back, moving it and finding the record above compare against it, so a later
	 * change of the interval does not hide the close.
	 */
	protected ?\DateTime $nextDueDate = null;
	protected ?int $nextDueOdo = null;
	/**
	 * Whether this record's delete took its close back: true when it gave the reminder back what
	 * the record kept or handed that to the record above, false when it changed nothing. Null on a
	 * live record, on one deleted before this column was stored, and on one whose delete found no
	 * reminder to take back: it closed none, or that reminder's row is gone. False keeps the link
	 * on the record's own undo. Only a set value lets the undo of an earlier delete replay this one
	 * (MaintenanceMapper::findDeletedAfter()).
	 */
	protected ?bool $tookBack = null;

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
		$this->addType('reminderId', Types::BIGINT);
		$this->addType('closedDueDate', Types::DATE);
		$this->addType('closedDueOdo', Types::BIGINT);
		$this->addType('closedSnoozedUntil', Types::DATE);
		$this->addType('closedOccurrence', Types::INTEGER);
		$this->addType('nextSnoozedUntil', Types::DATE);
		$this->addType('handedTo', Types::BIGINT);
		$this->addType('keptOccurrence', Types::INTEGER);
		$this->addType('nextDueDate', Types::DATE);
		$this->addType('nextDueOdo', Types::BIGINT);
		$this->addType('tookBack', Types::BOOLEAN);
	}

	/** The record as Energy::withoutCost() hands a fill-up out. */
	public function withoutCost(): static {
		$copy = clone $this;
		$copy->cost = null;

		return $copy;
	}

	/** @return NextFleetMaintenanceEntry */
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
