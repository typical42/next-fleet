<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One user or one group a vehicle's booking notices go to, one property per column of
 * `fleet_booking_recipients`. Two columns rather than a name and a type, so an erasure rewrites
 * an account and never a group spelt like it. Being on the list grants nothing: BookingNotices
 * tells only whoever sees the vehicle.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method void setEvents(?array $events)
 */
class BookingRecipient extends BaseEntity {
	protected int $vehicleId = 0;
	protected ?string $userId = null;
	protected ?string $groupId = null;
	/**
	 * The booking events this row hears, from Vehicle::NOTICES. Every write sets them; null hears
	 * the vehicle's (events()).
	 *
	 * @var list<string>|null
	 */
	protected ?array $events = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('userId', Types::STRING);
		$this->addType('groupId', Types::STRING);
		$this->addType('events', Types::JSON);
	}

	/** @return list<string> */
	public function events(Vehicle $vehicle): array {
		return $this->events ?? $vehicle->bookingNotices();
	}

	/** @return Access::USER|Access::GROUP */
	public function type(): string {
		return $this->groupId === null ? Access::USER : Access::GROUP;
	}

	public function name(): string {
		return $this->groupId ?? (string)$this->userId;
	}
}
