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
 * @method string|null getApproval()
 * @method void setApproval(?string $approval)
 * @method string|null getApprovalComment()
 * @method void setApprovalComment(?string $approvalComment)
 * @method string|null getApprovalBy()
 * @method void setApprovalBy(?string $approvalBy)
 * @method bool|null getAnnounced()
 * @method void setAnnounced(?bool $announced)
 */
class Booking extends BaseEntity {
	public const BOOKED = 'booked';
	public const OUT = 'out';
	public const RETURNED = 'returned';
	public const CANCELLED = 'cancelled';
	public const STATES = [self::BOOKED, self::OUT, self::RETURNED, self::CANCELLED];
	/** The states that hold the vehicle: only these collide with another booking. */
	public const LIVE = [self::BOOKED, self::OUT];

	/**
	 * Where an answer to the booking stands, beside its state rather than in it, so a client that
	 * knows only the four states reads a request as `booked` (docs/adr/0011-a-small-firm-may-opt-into-approval-and-hidden-costs.md).
	 * Null where the vehicle asked for none.
	 */
	public const REQUESTED = 'requested';
	/** The approver asked back; the booker changes or cancels it. */
	public const ASKED = 'asked';
	public const APPROVED = 'approved';
	/** Cancelled by the answer, or by its end come unanswered. */
	public const DECLINED = 'declined';
	/** The ones an approver may still answer. */
	public const OPEN = [self::REQUESTED, self::ASKED];

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
	protected ?string $approval = null;
	/** For the booker and the approvers only. */
	protected ?string $approvalComment = null;
	/** Who answered last. */
	protected ?string $approvalBy = null;
	/** Set once its list has heard of it, for when its approval no longer says so (heard()). */
	protected ?bool $announced = false;

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
		$this->addType('approval', Types::STRING);
		$this->addType('approvalComment', Types::STRING);
		$this->addType('approvalBy', Types::STRING);
		$this->addType('announced', Types::BOOLEAN);
	}

	/** Approved by `$by`, which tells its list (heard()). */
	public function approve(string $by): void {
		$this->setApproval(self::APPROVED);
		$this->setApprovalBy($by);
		$this->setAnnounced(true);
	}

	/**
	 * Whether the vehicle's list has heard that it stands (docs/architecture.md#booking-approval):
	 * at once where nobody was asked, else from its first approval on.
	 */
	public function heard(): bool {
		return $this->approval === null || $this->announced === true;
	}

	/**
	 * Whether an approver still has to answer it: until then it holds its span, but is not taken.
	 * A cancel keeps the approval it stood at, so the state is read with it.
	 */
	public function waits(): bool {
		return $this->state === self::BOOKED && in_array($this->approval, self::OPEN, true);
	}
}
