<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * What a deleted account leaves behind (docs/adr/0008-erasing-a-driver-pseudonymises.md): its
 * uid replaced on every row and no row deleted, except the reminder lists it was on; the vehicles
 * it owned closed. When it renamed a row, every sync cursor handed out before it starts over
 * (SyncService::EPOCH).
 */
class ErasureService {
	use TTransactional;

	/** `:` is outside what Nextcloud allows in a uid (IUserManager::validateUserId()), so no account can take a pseudonym. */
	public const PREFIX = 'erased:';
	private const OLD_PREFIX = 'erased-';

	/** @var list<BaseMapper> */
	private array $tables;

	public function __construct(
		private IDBConnection $db,
		private ISecureRandom $random,
		private ReminderRecipientMapper $recipients,
		private VehicleMapper $vehicles,
		AccountTables $tables,
		private SyncEpoch $epoch,
		private IUserManager $users,
		private GrantService $grants,
		private VehicleService $fleet,
		private GrantNotices $grantNotices,
		private Pending $pending,
		private LoggerInterface $logger,
	) {
		$this->tables = $tables->all();
	}

	/**
	 * One pseudonym per erasure, so the owner can still tell one former driver's trips from
	 * another's. Random rather than a hash of the uid: a uid is guessable, and a hash of it is
	 * the uid again to anyone who tries.
	 *
	 * The account's own vehicles close in the same transaction, each under its hold: nobody is
	 * left who may decide over them, so nobody keeps using them. Their rows stay for the
	 * retention docs/legal.md states; `occ nextfleet:transfer` hands a pool over before this.
	 *
	 * Pending until done (docs/architecture.md, "Both finish, whatever fails").
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function erase(string $uid): void {
		$this->pending->begin(Pending::ERASURE, $uid);
		$pseudonym = self::PREFIX . $this->random->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

		// Retried for the reason TripService::record() gives.
		/** @var array{list<Vehicle>, list<string>, int} $closed */
		$closed = $this->atomicRetry(function () use ($uid, $pseudonym): array {
			$owned = array_map(static fn (Vehicle $vehicle): int => (int)$vehicle->getId(), $this->vehicles->findAnyOwnedBy($uid));
			// The lists it is on are held so a recipient add or a sweep that asked userExists()
			// before the account went commits first, and its row is deleted or renamed here, not
			// left behind. All in id order, so two erasures at once never hold crosswise.
			$held = array_unique([...$owned, ...$this->recipients->findVehicleIds($uid)]);
			sort($held);
			foreach ($held as $vehicleId) {
				$this->vehicles->hold($vehicleId);
			}
			$vehicles = [];
			$grants = [];
			// Grants on a vehicle already in the trash go too: nobody is left to restore it.
			foreach ($owned as $vehicleId) {
				$vehicle = $this->vehicles->findAnyById($vehicleId);
				// A transfer that committed before the hold made it somebody else's.
				if ($vehicle->getUserId() !== $uid) {
					continue;
				}
				array_push($grants, ...$this->grants->revokeAll($vehicle));
				if ($vehicle->getDeletedAt() === null) {
					$vehicles[] = $this->vehicles->softDelete($vehicle, $vehicle->getUpdatedAt());
				}
			}
			// Most accounts never used the app, so a probe per table spares the rewrites. Asked
			// after the holds, so it sees what a sweep that held first wrote.
			$named = array_values(array_filter($this->tables, static fn (BaseMapper $table): bool => $table->names($uid)));
			if ($named === []) {
				return [$vehicles, $grants, 0];
			}
			$this->recipients->deleteAccount($uid);
			$renamed = 0;
			foreach ($named as $table) {
				$renamed += $table->pseudonymise($uid, $pseudonym);
			}

			return [$vehicles, $grants, $renamed];
		}, $this->db);

		[$vehicles, $grants, $renamed] = $closed;
		// After the commit: a client reset before it would be handed the uid again, and keep it.
		// Only a renamed row kept its token; a closed vehicle or grant moved its own.
		if ($renamed > 0) {
			$this->epoch->reset();
		}
		foreach ($vehicles as $vehicle) {
			$this->fleet->withdraw($vehicle);
		}
		foreach ($grants as $grantUuid) {
			$this->grantNotices->withdraw($grantUuid);
		}
		$this->pending->end(Pending::ERASURE, $uid);
	}

	/**
	 * Finishes every erasure a failure left pending (PendingJob), but none whose uid an account
	 * holds again (docs/architecture.md, "Both finish, whatever fails").
	 */
	public function finish(): void {
		foreach ($this->pending->of(Pending::ERASURE) as ['id' => $uid]) {
			try {
				if ($this->users->userExists($uid)) {
					$this->logger->warning('A pending erasure was dropped: an account of that uid exists again', ['app' => Application::APP_ID, 'account' => $uid]);
					$this->pending->end(Pending::ERASURE, $uid);
					continue;
				}
				$this->erase($uid);
			} catch (\Throwable $e) {
				$this->logger->error('A pending erasure failed again', ['app' => Application::APP_ID, 'account' => $uid, 'exception' => $e]);
			}
		}
	}

	/**
	 * Renames what an erasure before 0.3.0 wrote, `erased-` and the suffix, to `erased:` and the
	 * same suffix: `-` is allowed in a uid, so an account made under the old pseudonym took over
	 * the former driver's rows. The upgrade runs it (lib/Repair/ErasedPseudonyms.php); a second
	 * run finds nothing.
	 *
	 * A pseudonym a live account carries is kept: that account may be a real person, and renaming
	 * its rows would erase them for good. Deleting the account erases them the usual way.
	 *
	 * @return array{renamed: int, kept: list<string>}
	 * @throws \OCP\DB\Exception
	 */
	public function renameOld(): array {
		$found = [];
		foreach ($this->tables as $table) {
			foreach ($table->accountsStartingWith(self::OLD_PREFIX) as $account) {
				// The prefix alone matches a uid somebody chose, which is no pseudonym.
				if (preg_match('/^' . self::OLD_PREFIX . '[a-z0-9]{20}$/', $account) === 1) {
					$found[$account] = true;
				}
			}
		}
		$old = [];
		$kept = [];
		foreach (array_keys($found) as $account) {
			if ($this->users->userExists($account)) {
				$kept[] = $account;
			} else {
				$old[] = $account;
			}
		}
		if ($old === []) {
			return ['renamed' => 0, 'kept' => $kept];
		}

		$this->atomic(function () use ($old): void {
			foreach ($old as $account) {
				$pseudonym = self::PREFIX . substr($account, strlen(self::OLD_PREFIX));
				foreach ($this->tables as $table) {
					$table->pseudonymise($account, $pseudonym);
				}
			}
		}, $this->db);
		// Clients hold the rows under the old name, and kept their `updated_at`.
		$this->epoch->reset();

		return ['renamed' => count($old), 'kept' => $kept];
	}
}
