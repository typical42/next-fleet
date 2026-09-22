<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One thing a vehicle is due for, one property per column of `fleet_reminders`
 * (docs/architecture.md#reminder-engine). `dueDate` is a plain calendar date; `dueOdo` and
 * `leadOdo` count on the main chain. A recurrence advances this row and counts up `occurrence`.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string|null getTemplateKey()
 * @method void setTemplateKey(?string $templateKey)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method \DateTime|null getDueDate()
 * @method void setDueDate(\DateTime|string|null $dueDate)
 * @method int|null getDueOdo()
 * @method void setDueOdo(?int $dueOdo)
 * @method int|null getLeadOdo()
 * @method void setLeadOdo(?int $leadOdo)
 * @method int|null getRecurMonths()
 * @method void setRecurMonths(?int $recurMonths)
 * @method int|null getRecurOdo()
 * @method void setRecurOdo(?int $recurOdo)
 * @method string getState()
 * @method void setState(string $state)
 * @method \DateTime|null getSnoozedUntil()
 * @method void setSnoozedUntil(\DateTime|string|null $snoozedUntil)
 * @method int getOccurrence()
 * @method void setOccurrence(int $occurrence)
 */
class Reminder extends BaseEntity implements \JsonSerializable {
	public const DATE = 'date';
	public const ODO = 'odo';
	public const EITHER = 'either';

	public const PLANNED = 'planned';
	public const WARNED = 'warned';
	public const DUE = 'due';
	public const OVERDUE = 'overdue';
	public const DONE = 'done';
	public const SNOOZED = 'snoozed';
	public const DISMISSED = 'dismissed';

	protected int $vehicleId = 0;
	protected ?string $templateKey = null;
	protected ?string $title = null;
	protected string $mode = '';
	protected ?\DateTime $dueDate = null;
	protected ?int $dueOdo = null;
	protected ?int $leadOdo = null;
	// The defaults the columns carry, so a reminder nobody asked about warns as one should.
	protected ?bool $warnMonthBefore = true;
	protected ?bool $warnMonthStart = false;
	protected ?bool $warnDueDate = true;
	protected ?int $recurMonths = null;
	protected ?int $recurOdo = null;
	protected string $state = '';
	protected ?\DateTime $snoozedUntil = null;
	protected int $occurrence = 1;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('templateKey', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('mode', Types::STRING);
		$this->addType('dueDate', Types::DATE);
		$this->addType('dueOdo', Types::BIGINT);
		$this->addType('leadOdo', Types::BIGINT);
		$this->addType('warnMonthBefore', Types::BOOLEAN);
		$this->addType('warnMonthStart', Types::BOOLEAN);
		$this->addType('warnDueDate', Types::BOOLEAN);
		$this->addType('recurMonths', Types::INTEGER);
		$this->addType('recurOdo', Types::BIGINT);
		$this->addType('state', Types::STRING);
		$this->addType('snoozedUntil', Types::DATE);
		$this->addType('occurrence', Types::INTEGER);
	}

	/**
	 * The warning points are nullable for the reason every boolean is, and an unanswered one
	 * means what the column's default says.
	 */
	public function getWarnMonthBefore(): bool {
		return $this->warnMonthBefore ?? true;
	}

	public function setWarnMonthBefore(bool $warn): void {
		$this->setter('warnMonthBefore', [$warn]);
	}

	public function getWarnMonthStart(): bool {
		return $this->warnMonthStart ?? false;
	}

	public function setWarnMonthStart(bool $warn): void {
		$this->setter('warnMonthStart', [$warn]);
	}

	public function getWarnDueDate(): bool {
		return $this->warnDueDate ?? true;
	}

	public function setWarnDueDate(bool $warn): void {
		$this->setter('warnDueDate', [$warn]);
	}

	/**
	 * The wire form is the column names, as it is for every entity.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'template_key' => $this->templateKey,
			'title' => $this->title,
			'mode' => $this->mode,
			'due_date' => $this->dueDate?->format('Y-m-d'),
			'due_odo' => $this->dueOdo,
			'lead_odo' => $this->leadOdo,
			'warn_month_before' => $this->getWarnMonthBefore(),
			'warn_month_start' => $this->getWarnMonthStart(),
			'warn_due_date' => $this->getWarnDueDate(),
			'recur_months' => $this->recurMonths,
			'recur_odo' => $this->recurOdo,
			'state' => $this->state,
			'snoozed_until' => $this->snoozedUntil?->format('Y-m-d'),
			'occurrence' => $this->occurrence,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
		];
	}
}
