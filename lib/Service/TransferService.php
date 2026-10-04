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
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
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
		private GrantNotices $notices,
		private SyncEpoch $epoch,
		private IUserManager $users,
		private IDBConnection $db,
	) {
	}

	/**
	 * Every grant stands but the new owner's own, which owning makes spent. The former owner keeps a
	 * viewer grant while their account exists, so what they entered stays readable to them.
	 *
	 * @return array{vehicle: Vehicle, from: string} the vehicle as it now stands, and whose it was
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if no live vehicle has this uuid
	 * @throws \InvalidArgumentException if the new owner does not exist or owns it already
	 * @throws \OCP\DB\Exception
	 */
	public function transfer(string $vehicleUuid, string $newOwner): array {
		// As the instance spells it, for the reason GrantService::grantee() gives.
		$newOwner = $this->users->get($newOwner)?->getUID() ?? throw new \InvalidArgumentException('No such user: ' . $newOwner);

		/** @var array{vehicle: Vehicle, from: string, spent: list<string>} $done */
		$done = $this->atomicRetry(function () use ($vehicleUuid, $newOwner): array {
			$id = (int)$this->vehicles->findByUuid($vehicleUuid)->getId();
			$this->vehicles->hold($id);
			// Read again under the hold, for a token nobody moved since.
			$vehicle = $this->vehicles->findByUuid($vehicleUuid);
			$from = $vehicle->getUserId();
			if ($from === $newOwner) {
				throw new \InvalidArgumentException($newOwner . ' owns this vehicle already');
			}

			$spent = [];
			foreach ($this->grants->findByVehicle($id) as $grant) {
				if ($grant->getGranteeType() === Access::USER && $grant->getGrantee() === $newOwner) {
					$this->grants->softDelete($grant, $grant->getUpdatedAt());
					$spent[] = $grant->getUuid();
				}
			}
			$vehicle->setUserId($newOwner);
			$vehicle = $this->vehicles->updateChecked($vehicle, $vehicle->getUpdatedAt());
			if ($this->users->userExists($from)) {
				$this->viewer($id, $from);
			}
			$this->remind($id, $newOwner);

			$row = new Audit();
			$row->setCreatedBy(Audit::TRANSFERRED_BY);
			$row->setEntity(Audit::VEHICLE);
			$row->setEntityId($id);
			$row->setDiffJson(['change' => self::TRANSFERRED, 'fields' => ['user_id' => [$from, $newOwner]]]);
			$this->audit->insert($row);

			return ['vehicle' => $vehicle, 'from' => $from, 'spent' => $spent];
		}, $this->db);

		// After the commit, the reason GrantService::grant() gives. The new owner's client read no
		// grants of a vehicle it did not own, and none of them moved.
		foreach ($done['spent'] as $grantUuid) {
			$this->notices->withdraw($grantUuid);
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
