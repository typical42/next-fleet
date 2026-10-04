<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One vehicle's master data, one property per column of `fleet_vehicles`
 * (docs/architecture.md#data-model). Money is integer cents, volumes millilitres, energy
 * watt-hours; `odoValue` is a cache of the newest Reading and is written by the odometer, never
 * by a client.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getPlate()
 * @method void setPlate(?string $plate)
 * @method string|null getManufacturer()
 * @method void setManufacturer(?string $manufacturer)
 * @method string|null getModel()
 * @method void setModel(?string $model)
 * @method string getVehicleType()
 * @method void setVehicleType(string $vehicleType)
 * @method string|null getEngine()
 * @method void setEngine(?string $engine)
 * @method list<string>|null getEnergyTypes()
 * @method void setEnergyTypes(?array $energyTypes)
 * @method int|null getTankMl()
 * @method void setTankMl(?int $tankMl)
 * @method int|null getBatteryWh()
 * @method void setBatteryWh(?int $batteryWh)
 * @method \DateTime|null getFirstReg()
 * @method void setFirstReg(\DateTime|string|null $firstReg)
 * @method \DateTime|null getDisposedAt()
 * @method void setDisposedAt(\DateTime|string|null $disposedAt)
 * @method string|null getVin()
 * @method void setVin(?string $vin)
 * @method int|null getOdoValue()
 * @method void setOdoValue(?int $odoValue)
 * @method string getOdoUnit()
 * @method void setOdoUnit(string $odoUnit)
 * @method int|null getPurchasePrice()
 * @method void setPurchasePrice(?int $purchasePrice)
 * @method int|null getResidualEst()
 * @method void setResidualEst(?int $residualEst)
 * @method string|null getCurrency()
 * @method void setCurrency(?string $currency)
 * @method string getJurisdiction()
 * @method void setJurisdiction(string $jurisdiction)
 * @method bool|null getLogbookMode()
 * @method void setLogbookMode(?bool $logbookMode)
 * @method string getLifecycle()
 * @method void setLifecycle(string $lifecycle)
 * @method int|null getFolderFileId()
 * @method void setFolderFileId(?int $folderFileId)
 * @method int|null getRetentionMonths()
 * @method void setRetentionMonths(?int $retentionMonths)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getSecondUnit()
 * @method void setSecondUnit(?string $secondUnit)
 * @method int|null getSecondValue()
 * @method void setSecondValue(?int $secondValue)
 * @method string getReminderMail()
 * @method void setReminderMail(string $reminderMail)
 *
 * @psalm-import-type NextFleetVehicle from \OCA\NextFleet\ResponseDefinitions
 */
class Vehicle extends BaseEntity implements \JsonSerializable {
	public const ACTIVE = 'active';
	public const LAID_UP = 'laid_up';
	public const DISPOSED = 'disposed';

	public const MAIL_OFF = 'off';
	public const MAIL_DAILY = 'daily';
	public const MAIL_WEEKLY = 'weekly';
	public const MAIL_MONTHLY = 'monthly';

	protected string $userId = '';
	protected ?string $plate = null;
	protected ?string $manufacturer = null;
	protected ?string $model = null;
	protected string $vehicleType = '';
	protected ?string $engine = null;
	/** @var list<string>|null */
	protected ?array $energyTypes = null;
	protected ?int $tankMl = null;
	protected ?int $batteryWh = null;
	protected ?\DateTime $firstReg = null;
	protected ?\DateTime $disposedAt = null;
	protected ?string $vin = null;
	protected ?int $odoValue = null;
	protected string $odoUnit = '';
	protected ?int $purchasePrice = null;
	protected ?int $residualEst = null;
	protected ?string $currency = null;
	protected string $jurisdiction = '';
	protected ?bool $logbookMode = null;
	protected string $lifecycle = '';
	protected ?int $folderFileId = null;
	protected ?int $retentionMonths = null;
	protected ?string $color = null;
	protected ?string $notes = null;
	/** Null, or `h` when engine hours are counted beside the kilometres. */
	protected ?string $secondUnit = null;
	/** The second chain's `odoValue`: a cache, written by the odometer. */
	protected ?int $secondValue = null;
	/** How often the reminder digest covers this vehicle; the column's default. */
	protected string $reminderMail = self::MAIL_WEEKLY;

	/**
	 * What whoever asked may do on the vehicle (VehicleAccess::operations()). Not a column:
	 * private and outside the magic accessors, so no write can mark it and no mapper sees it.
	 *
	 * @var list<string>
	 */
	private array $may = [];
	/**
	 * The owner's display name, to whoever reaches the vehicle through a grant; null to the owner,
	 * whose own vehicle needs no name beneath it. Not a column, like `may`.
	 */
	private ?string $ownedBy = null;
	/**
	 * Who has the car: the booker it is out with and when they planned to bring it back
	 * (VehicleService::pool()). Not a column, like `may`.
	 *
	 * @var array{user_id: string, user_name: string, ends_at: int, ends_at_off: int}|null
	 */
	private ?array $outWith = null;
	/**
	 * The reader's own next booking of it within a week, its uuid and span. Not a column, like `may`.
	 *
	 * @var array{uuid: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int}|null
	 */
	private ?array $myNextBooking = null;
	/**
	 * Whether anybody but the owner has or had access (AccessMapper::everGranted()): the rule
	 * *Entered by* follows, and the screen shows Bookings by. Not a column, like `may`.
	 */
	private bool $everGranted = false;

	public function __construct() {
		parent::__construct();
		$this->addType('userId', Types::STRING);
		$this->addType('plate', Types::STRING);
		$this->addType('manufacturer', Types::STRING);
		$this->addType('model', Types::STRING);
		$this->addType('vehicleType', Types::STRING);
		$this->addType('engine', Types::STRING);
		$this->addType('energyTypes', Types::JSON);
		$this->addType('tankMl', Types::INTEGER);
		$this->addType('batteryWh', Types::INTEGER);
		$this->addType('firstReg', Types::DATE);
		$this->addType('disposedAt', Types::DATE);
		$this->addType('vin', Types::STRING);
		$this->addType('odoValue', Types::BIGINT);
		$this->addType('odoUnit', Types::STRING);
		$this->addType('purchasePrice', Types::BIGINT);
		$this->addType('residualEst', Types::BIGINT);
		$this->addType('currency', Types::STRING);
		$this->addType('jurisdiction', Types::STRING);
		$this->addType('logbookMode', Types::BOOLEAN);
		$this->addType('lifecycle', Types::STRING);
		$this->addType('folderFileId', Types::BIGINT);
		$this->addType('retentionMonths', Types::INTEGER);
		$this->addType('color', Types::STRING);
		$this->addType('notes', Types::STRING);
		$this->addType('secondUnit', Types::STRING);
		$this->addType('secondValue', Types::BIGINT);
		$this->addType('reminderMail', Types::STRING);
	}

	/** @return list<string> */
	public function getMay(): array {
		return $this->may;
	}

	/** @param list<string> $may */
	public function setMay(array $may): void {
		$this->may = $may;
	}

	public function getOwnedBy(): ?string {
		return $this->ownedBy;
	}

	public function setOwnedBy(?string $ownedBy): void {
		$this->ownedBy = $ownedBy;
	}

	/** @return array{user_id: string, user_name: string, ends_at: int, ends_at_off: int}|null */
	public function getOutWith(): ?array {
		return $this->outWith;
	}

	/** @param array{user_id: string, user_name: string, ends_at: int, ends_at_off: int}|null $outWith */
	public function setOutWith(?array $outWith): void {
		$this->outWith = $outWith;
	}

	public function getEverGranted(): bool {
		return $this->everGranted;
	}

	public function setEverGranted(bool $everGranted): void {
		$this->everGranted = $everGranted;
	}

	/** @return array{uuid: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int}|null */
	public function getMyNextBooking(): ?array {
		return $this->myNextBooking;
	}

	/** @param array{uuid: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int}|null $myNextBooking */
	public function setMyNextBooking(?array $myNextBooking): void {
		$this->myNextBooking = $myNextBooking;
	}

	/**
	 * The wire form is the column names: what a client reads back is what it may send. The
	 * numeric `id` stays inside - the uuid is the identity - and `updated_at` goes out because
	 * the next write has to carry it back (docs/architecture.md#concurrency).
	 *
	 * @return NextFleetVehicle
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'user_id' => $this->userId,
			'plate' => $this->plate,
			'manufacturer' => $this->manufacturer,
			'model' => $this->model,
			'vehicle_type' => $this->vehicleType,
			'engine' => $this->engine,
			'energy_types' => $this->energyTypes,
			'tank_ml' => $this->tankMl,
			'battery_wh' => $this->batteryWh,
			'first_reg' => $this->firstReg?->format('Y-m-d'),
			'disposed_at' => $this->disposedAt?->format('Y-m-d'),
			'vin' => $this->vin,
			'odo_value' => $this->odoValue,
			'odo_unit' => $this->odoUnit,
			'purchase_price' => $this->purchasePrice,
			'residual_est' => $this->residualEst,
			'currency' => $this->currency,
			'jurisdiction' => $this->jurisdiction,
			'logbook_mode' => $this->logbookMode,
			'lifecycle' => $this->lifecycle,
			'folder_file_id' => $this->folderFileId,
			'retention_months' => $this->retentionMonths,
			'color' => $this->color,
			'notes' => $this->notes,
			'second_unit' => $this->secondUnit,
			'second_value' => $this->secondValue,
			'reminder_mail' => $this->reminderMail,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'created_by' => $this->createdBy,
			// Read-only: the service writes only the columns it lists, so one sent back is dropped.
			'may' => $this->wiredMay(),
			'owned_by' => $this->ownedBy,
			'out_with' => $this->outWith,
			'my_next_booking' => $this->myNextBooking,
			'ever_granted' => $this->everGranted,
		];
	}

	/**
	 * `may`, and `book` where BookingService::bookable() takes a booking. A word the screen hides
	 * by, not a sixth operation - VehicleAccess never sees it. Derived here, at the last moment,
	 * so an edit that lays the car up answers without it. `'log'` is VehicleAccess::LOG, spelt
	 * out so an entity does not reach into the service layer.
	 *
	 * @return list<string>
	 */
	private function wiredMay(): array {
		return in_array('log', $this->may, true) && $this->lifecycle === self::ACTIVE
			? [...$this->may, 'book']
			: $this->may;
	}
}
