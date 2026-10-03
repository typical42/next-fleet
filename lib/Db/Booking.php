<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One person's plan for a vehicle, one property per column of `fleet_bookings`
 * (docs/architecture.md#data-model). `userId` is the booker; check-out fills the `out*`
 * properties, check-in the `in*` ones.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getStartsAt()
 * @method void setStartsAt(int $startsAt)
 * @method int getStartsAtOff()
 * @method void setStartsAtOff(int $startsAtOff)
 * @method int getEndsAt()
 * @method void setEndsAt(int $endsAt)
 * @method int getEndsAtOff()
 * @method void setEndsAtOff(int $endsAtOff)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string getState()
 * @method void setState(string $state)
 * @method int|null getOutAt()
 * @method void setOutAt(?int $outAt)
 * @method int|null getOutAtOff()
 * @method void setOutAtOff(?int $outAtOff)
 * @method int|null getOutOdo()
 * @method void setOutOdo(?int $outOdo)
 * @method int|null getOutLevel()
 * @method void setOutLevel(?int $outLevel)
 * @method string|null getOutNotes()
 * @method void setOutNotes(?string $outNotes)
 * @method int|null getInAt()
 * @method void setInAt(?int $inAt)
 * @method int|null getInAtOff()
 * @method void setInAtOff(?int $inAtOff)
 * @method int|null getInOdo()
 * @method void setInOdo(?int $inOdo)
 * @method int|null getInLevel()
 * @method void setInLevel(?int $inLevel)
 * @method string|null getInNotes()
 * @method void setInNotes(?string $inNotes)
 * @method int|null getTripId()
 * @method void setTripId(?int $tripId)
 */
class Booking extends BaseEntity {
	public const BOOKED = 'booked';
	public const OUT = 'out';
	public const RETURNED = 'returned';
	public const CANCELLED = 'cancelled';
	public const STATES = [self::BOOKED, self::OUT, self::RETURNED, self::CANCELLED];
	/** The states that hold the vehicle: only these collide with another booking. */
	public const LIVE = [self::BOOKED, self::OUT];

	protected int $vehicleId = 0;
	protected string $userId = '';
	protected int $startsAt = 0;
	protected int $startsAtOff = 0;
	protected int $endsAt = 0;
	protected int $endsAtOff = 0;
	protected ?string $purpose = null;
	protected string $state = '';
	protected ?int $outAt = null;
	protected ?int $outAtOff = null;
	protected ?int $outOdo = null;
	protected ?int $outLevel = null;
	protected ?string $outNotes = null;
	protected ?int $inAt = null;
	protected ?int $inAtOff = null;
	protected ?int $inOdo = null;
	protected ?int $inLevel = null;
	protected ?string $inNotes = null;
	protected ?int $tripId = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('userId', Types::STRING);
		$this->addType('startsAt', Types::BIGINT);
		$this->addType('startsAtOff', Types::INTEGER);
		$this->addType('endsAt', Types::BIGINT);
		$this->addType('endsAtOff', Types::INTEGER);
		$this->addType('purpose', Types::STRING);
		$this->addType('state', Types::STRING);
		$this->addType('outAt', Types::BIGINT);
		$this->addType('outAtOff', Types::INTEGER);
		$this->addType('outOdo', Types::BIGINT);
		$this->addType('outLevel', Types::INTEGER);
		$this->addType('outNotes', Types::STRING);
		$this->addType('inAt', Types::BIGINT);
		$this->addType('inAtOff', Types::INTEGER);
		$this->addType('inOdo', Types::BIGINT);
		$this->addType('inLevel', Types::INTEGER);
		$this->addType('inNotes', Types::STRING);
		$this->addType('tripId', Types::BIGINT);
	}
}
