<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\CostCentreMapper;
use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * An admin hands a vehicle to another account (`occ nextfleet:transfer`), so a pool outlives the
 * account of whoever set it up: an erased owner's vehicles close (ErasureService::erase()). No
 * route reaches this; only an admin with a shell does.
 */
class TransferService {
	use TTransactional;

	private const TRANSFERRED = 'transferred';
	/** The role the former owner keeps, one of VehicleAccess::roles(). */
	private const VIEWER = 'viewer';

	public function __construct(
		private VehicleMapper $vehicles,
		private AccessMapper $grants,
		private ReminderRecipientMapper $recipients,
		private AuditMapper $audit,
		private TripMapper $trips,
		private PartnerMapper $partners,
		private CostCentreMapper $centres,
		private TripService $tripService,
		private GrantNotices $notices,
		private BookingNotices $bookingNotices,
		private VehicleAccess $access,
		private SyncEpoch $epoch,
		private IUserManager $users,
		private Names $names,
		private IDBConnection $db,
	) {
	}

	/**
	 * Every grant stands but the new owner's own, which owning makes spent. The former owner keeps a
	 * viewer grant while their account exists, so what they entered stays readable to them. The new
	 * owner answers the bookings now, and is asked those that wait (BookingNotices::tellWaiting()).
	 * Whether bookings wait stays as it was, pinned to always or never: the new owner's default is
	 * not the one the drivers booked under.
	 *
	 * @return array{vehicle: Vehicle, from: string} the vehicle as it now stands, and whose it was
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if no live vehicle has this uuid
	 * @throws \InvalidArgumentException if the new owner does not exist or owns it already
	 * @throws \OCP\DB\Exception
	 */
	public function transfer(string $vehicleUuid, string $newOwner): array {
		// As the instance spells it, for the reason GrantService::grantee() gives.
		$newOwner = $this->names->spelled(Access::USER, $newOwner) ?? throw new \InvalidArgumentException('No such user: ' . $newOwner);

		/** @var array{vehicle: Vehicle, from: string, spent: list<string>, answered: bool} $done */
		$done = $this->atomicRetry(function () use ($vehicleUuid, $newOwner): array {
			$id = (int)$this->vehicles->findByUuid($vehicleUuid)->getId();
			// With the new owner's lists, which carryPartners() adds to.
			$this->vehicles->holdBeside($id, $newOwner);
			// Read again under the hold, for a token nobody moved since.
			$vehicle = $this->vehicles->findByUuid($vehicleUuid);
			$from = $vehicle->getUserId();
			if ($from === $newOwner) {
				throw new \InvalidArgumentException($newOwner . ' owns this vehicle already');
			}
			// A manager was asked of every request already.
			$answered = $this->access->may($newOwner, VehicleAccess::EDIT, $vehicle);

			$spent = [];
			foreach ($this->grants->findByVehicle($id) as $grant) {
				if ($grant->getGranteeType() === Access::USER && $grant->getGrantee() === $newOwner) {
					$this->grants->softDelete($grant, $grant->getUpdatedAt());
					$spent[] = $grant->getUuid();
				}
			}
			$this->vehicles->refreshOwnerApproval($vehicle);
			$vehicle->setBookingApproval($vehicle->needsApproval());
			// Before the write below, which carries the vehicle's default with it.
			$this->carryCostCentres($vehicle, $newOwner);
			$vehicle->setUserId($newOwner);
			$vehicle = $this->vehicles->updateChecked($vehicle, $vehicle->getUpdatedAt());
			if ($this->users->userExists($from)) {
				$this->viewer($id, $from);
			}
			$this->remind($id, $newOwner);
			$this->carryPartners($vehicle, $newOwner);

			$row = new Audit();
			$row->setCreatedBy(Audit::TRANSFERRED_BY);
			$row->setEntity(Audit::VEHICLE);
			$row->setEntityId($id);
			$row->setDiffJson(['change' => self::TRANSFERRED, 'fields' => ['user_id' => [$from, $newOwner]]]);
			$this->audit->insert($row);

			return ['vehicle' => $vehicle, 'from' => $from, 'spent' => $spent, 'answered' => $answered];
		}, $this->db);

		// After the commit, the reason GrantService::grant() gives. The new owner's client read no
		// grants of a vehicle it did not own, and none of them moved.
		foreach ($done['spent'] as $grantUuid) {
			$this->notices->withdraw($grantUuid);
		}
		if (!$done['answered']) {
			$this->bookingNotices->tellWaiting($done['vehicle'], [$newOwner]);
		}
		$this->epoch->reset();

		return ['vehicle' => $done['vehicle'], 'from' => $done['from']];
	}

	/** @throws \OCP\DB\Exception */
	private function viewer(int $vehicleId, string $userId): void {
		$grant = new Access();
		$grant->setVehicleId($vehicleId);
		$grant->setGrantee($userId);
		$grant->setGranteeType(Access::USER);
		$grant->setRole(self::VIEWER);
		$grant->setCreatedBy(Audit::TRANSFERRED_BY);
		$this->grants->insert($grant);
	}

	/**
	 * Links the vehicle's trips, voided ones too, to the new owner's list
	 * ([ADR 0013](docs/adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)): a record of the
	 * same name there is taken as it is, any other is copied with its address and note. Each move
	 * is on the trip's trail, as a merge's is. A deleted record stays the trip's mark
	 * (Trip::linkPartner()): copying it would bring back what its owner erased. The former owner's
	 * list stays as it is.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function carryPartners(Vehicle $vehicle, string $newOwner): void {
		$trips = $this->trips->findAnyLinkingOn((int)$vehicle->getId());
		$records = $this->partners->findAnyOf(array_values(array_unique(array_map(static fn (Trip $trip): int => (int)$trip->getPartnerId(), $trips))));
		/** @var array<int, Partner> $copies by the id of the record copied */
		$copies = [];
		foreach ($trips as $trip) {
			$record = $records[(int)$trip->getPartnerId()] ?? null;
			if ($record === null || $record->getDeletedAt() !== null || $record->getUserId() === $newOwner) {
				continue;
			}
			$copies[(int)$record->getId()] ??= $this->copy($record, $newOwner);
			$this->tripService->relink(Audit::TRANSFERRED_BY, $vehicle, $trip, $copies[(int)$record->getId()]);
		}
	}

	/** @throws \OCP\DB\Exception */
	private function copy(Partner $record, string $newOwner): Partner {
		$found = $this->partners->findByName($newOwner, $record->getName());
		if ($found !== null) {
			return $found;
		}
		$copy = new Partner();
		$copy->setUserId($newOwner);
		$copy->setName($record->getName());
		$copy->setAddress($record->getAddress());
		$copy->setNote($record->getNote());
		$copy->setCreatedBy(Audit::TRANSFERRED_BY);

		return $this->partners->insert($copy);
	}

	/**
	 * Links the cost centres the vehicle's entries and its default name, in any state, to the new
	 * owner's list: a record there with the same code, else the same name, is taken as it is; any
	 * other is copied. A trip's move is on its trail; the other entries are on none. A deleted
	 * record stays the entries' mark, as a deleted partner does. The former owner's list stays as
	 * it is.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function carryCostCentres(Vehicle $vehicle, string $newOwner): void {
		$id = (int)$vehicle->getId();
		$trips = $this->trips->findAnyLinkingOn($id, 'cost_centre_id');
		$named = $this->centres->namedOn($id);
		$default = $vehicle->getCostCentreId();
		$ids = [...array_map(static fn (Trip $trip): int => (int)$trip->getCostCentreId(), $trips), ...$named];
		if ($default !== null) {
			$ids[] = $default;
		}
		/** @var array<int, CostCentre> $copies by the id of the record copied */
		$copies = [];
		foreach ($this->centres->findAnyOf(array_values(array_unique($ids))) as $recordId => $record) {
			if ($record->getDeletedAt() === null && $record->getUserId() !== $newOwner) {
				$copies[$recordId] = $this->copyCentre($record, $newOwner);
			}
		}

		foreach ($trips as $trip) {
			$copy = $copies[(int)$trip->getCostCentreId()] ?? null;
			if ($copy !== null) {
				$this->tripService->relinkCostCentre(Audit::TRANSFERRED_BY, $vehicle, $trip, $copy);
			}
		}
		foreach ($named as $recordId) {
			if (isset($copies[$recordId])) {
				$this->centres->moveOn($id, $recordId, (int)$copies[$recordId]->getId());
			}
		}
		if ($default !== null && isset($copies[$default])) {
			$vehicle->linkCostCentre($copies[$default]);
		}
	}

	/**
	 * A record found in the new owner's list is held and read again, after the vehicle's hold as
	 * for an entry write (CostCentreLinks): a delete of it at the same time then either waits and
	 * finds it named, or has committed, and the lookup goes on as if it was never there. A new copy
	 * needs no hold, as nobody sees it before the commit.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function copyCentre(CostCentre $record, string $newOwner): CostCentre {
		foreach (['code' => $record->getCode(), 'name' => $record->getName()] as $column => $value) {
			$found = $value === null ? null : $this->centres->findBy($newOwner, $column, $value);
			if ($found === null) {
				continue;
			}
			$id = (int)$found->getId();
			$this->centres->hold($id);
			$found = $this->centres->findAnyOf([$id])[$id] ?? null;
			if ($found !== null && $found->getDeletedAt() === null) {
				return $found;
			}
		}
		$copy = new CostCentre();
		$copy->setUserId($newOwner);
		$copy->setCode($record->getCode());
		$copy->setName($record->getName());
		$copy->setArchived($record->getArchived());
		$copy->setCreatedBy(Audit::TRANSFERRED_BY);

		return $this->centres->insert($copy);
	}

	/** The owner is where reminders go (VehicleService::create()), unless they are there already. */
	private function remind(int $vehicleId, string $userId): void {
		foreach ($this->recipients->findByVehicle($vehicleId) as $recipient) {
			if ($recipient->getUserId() === $userId) {
				return;
			}
		}
		$row = new ReminderRecipient();
		$row->setVehicleId($vehicleId);
		$row->setUserId($userId);
		$row->setCreatedBy(Audit::TRANSFERRED_BY);
		$this->recipients->insert($row);
	}
}
