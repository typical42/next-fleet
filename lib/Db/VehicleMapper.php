<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCA\NextFleet\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<Vehicle>
 */
class VehicleMapper extends BaseMapper {
	public function __construct(
		IDBConnection $db,
		ITimeFactory $time,
		ISecureRandom $random,
		private IConfig $config,
	) {
		parent::__construct($db, $time, $random, 'fleet_vehicles', Vehicle::class);
	}

	/**
	 * Every vehicle read carries its owner's default, so Vehicle::needsApproval() answers alone.
	 *
	 * @return Vehicle
	 */
	protected function mapRowToEntity(array $row): Entity {
		$vehicle = parent::mapRowToEntity($row);
		$vehicle->setOwnerApproval($this->ownerApproval($vehicle->getUserId()));

		return $vehicle;
	}

	/** Reads the vehicle's owner's default again, as storedOwnerApproval() does. */
	public function refreshOwnerApproval(Vehicle $vehicle): void {
		$vehicle->setOwnerApproval($this->storedOwnerApproval($vehicle->getUserId()));
	}

	/**
	 * The owner's default, read from the database itself. IConfig keeps what it read once for the
	 * rest of the request, so a caller that holds a vehicle would otherwise miss the owner changing
	 * the default meanwhile (VehicleService::approveByDefault()).
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function storedOwnerApproval(string $owner): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(Vehicle::OWNER_APPROVAL)));
		$result = $qb->executeQuery();
		$stored = $result->fetchOne();
		$result->closeCursor();

		return $stored === '1';
	}

	/** The owner's default, as PreferencesService writes it; a vehicle not yet written asks here. */
	public function ownerApproval(string $owner): bool {
		return $this->config->getUserValue($owner, Application::APP_ID, Vehicle::OWNER_APPROVAL, '0') === '1';
	}

	/**
	 * The owner too: Nextcloud lets a deleted uid be taken again, and ownership is this column.
	 */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * Writes the odometer cache and nothing else. `odo_value` is recomputed from the Readings
	 * (docs/architecture.md#odometer-rules), so it is not what a client edited and must not move
	 * the row's concurrency token: a recompute that did would refuse every sheet that happened
	 * to be open when it ran.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function cacheOdoValue(int $vehicleId, ?int $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('odo_value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * cacheOdoValue() for the hour chain, into `second_value`, and for the same reasons.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function cacheSecondValue(int $vehicleId, ?int $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('second_value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Holds the vehicle's row until the caller's transaction ends, and changes nothing on it. A
	 * write that reads the vehicle's state before it writes - a Gap not yet closed, the chain a
	 * Reading settles - holds the vehicle before reading, so a second writer on the same vehicle
	 * waits and then sees the first one's answer. It works because Nextcloud runs its database at
	 * READ COMMITTED (.docker/compose.yml), and an UPDATE is the lock `OCP` offers on every major
	 * this app supports: `forUpdate()` is not in NC 31's query builder.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function hold(int $vehicleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('odo_value', 'odo_value')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Who owns the vehicle as the database has it now: under the vehicle's hold, a transfer has
	 * either committed or not begun (TransferService holds it too).
	 *
	 * @throws DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function ownerOf(int $vehicleId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$owner = $result->fetchOne();
		$result->closeCursor();
		if (!is_string($owner)) {
			throw new DoesNotExistException('vehicle ' . $vehicleId);
		}

		return $owner;
	}

	/**
	 * The default cost centre the vehicle names as the database has it now, as ownerOf() reads the
	 * owner: a transfer moves the default with the owner.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function costCentreOf(int $vehicleId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cost_centre_id')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($id) ? (int)$id : null;
	}

	/**
	 * Holds what stands for the owner's lists: their first vehicle by id, in any state. A write that
	 * adds a name to a list holds it, so a second writer on another of the owner's vehicles waits
	 * and then finds the name.
	 *
	 * The caller holds its own vehicle first, which comes no earlier than this one. Two such writes
	 * cannot wait on each other. A write that holds several of the owner's vehicles in id order can:
	 * the database breaks that deadlock, and `atomicRetry()` replays the side it rolled back.
	 *
	 * Asked again once held: a transfer that committed meanwhile can have moved the first vehicle
	 * away.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function holdOwner(string $userId): void {
		$held = null;
		while (($first = $this->firstOf($userId)) !== null && $first !== $held) {
			$this->hold($first);
			$held = $first;
		}
	}

	/**
	 * Holds the vehicle and holdOwner()'s vehicle of another account, the lower id first: for a
	 * write that adds names to the list of an account that does not own the vehicle yet.
	 *
	 * An account with no vehicle has nothing to hold, so two such writes to it at once can each
	 * add the same name, and the owner merges the two. A transfer that moves the account's first vehicle
	 * away meanwhile can break the id order; the database breaks that deadlock, as holdOwner()
	 * says.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function holdBeside(int $vehicleId, string $userId): void {
		$first = $this->firstOf($userId);
		if ($first !== null && $first < $vehicleId) {
			$this->holdOwner($userId);
			$this->hold($vehicleId);

			return;
		}
		$this->hold($vehicleId);
		$this->holdOwner($userId);
	}

	/** @throws \OCP\DB\Exception */
	private function firstOf(string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->min('id'))
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$first = $result->fetchOne();
		$result->closeCursor();

		return $first === null || $first === false ? null : (int)$first;
	}

	/**
	 * Every live vehicle not disposed of with a reminder that can still ring, whoever owns it,
	 * each with the uids on its list: the reminder job's round, in one query. A disposed one's
	 * reminders stop (docs/architecture.md#data-model); done and dismissed ones move only when
	 * somebody acts, and that write is what tells.
	 *
	 * @param bool $lists false for a caller that reads each list again under the vehicle's hold:
	 *                    the lists come back empty, and the vehicle rows once each
	 * @return list<array{Vehicle, list<string>}> by vehicle id, the list in the order it was made
	 * @throws \OCP\DB\Exception
	 */
	public function findReminded(bool $lists = true): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$live = $this->db->getQueryBuilder();
		$live->select('m.id')
			->from('fleet_reminders', 'm')
			->where($expr->eq('m.vehicle_id', 'v.id'))
			->andWhere($expr->isNull('m.deleted_at'))
			->andWhere(InList::notIn($qb, 'm.state', [Reminder::DONE, Reminder::DISMISSED], IQueryBuilder::PARAM_STR_ARRAY));
		$qb->select('v.*')
			->from($this->tableName, 'v')
			->where($expr->neq('v.lifecycle', $qb->createNamedParameter(Vehicle::DISPOSED)))
			->andWhere($expr->isNull('v.deleted_at'))
			->andWhere($qb->createFunction('EXISTS (' . $live->getSQL() . ')'))
			->orderBy('v.id', 'ASC');
		if ($lists) {
			$qb->selectAlias('r.user_id', 'recipient')
				->leftJoin('v', 'fleet_reminder_recipients', 'r', $expr->andX(
					$expr->eq('r.vehicle_id', 'v.id'),
					$expr->isNull('r.deleted_at'),
				))
				->addOrderBy('r.id', 'ASC');
		}

		/** @var array<int, Vehicle> $vehicles */
		$vehicles = [];
		/** @var array<int, list<string>> $named */
		$named = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$recipient = $row['recipient'] ?? null;
			unset($row['recipient']);
			$id = (int)$row['id'];
			$vehicles[$id] ??= $this->mapRowToEntity($row);
			$named[$id] ??= [];
			if ($recipient !== null) {
				$named[$id][] = (string)$recipient;
			}
		}
		$result->closeCursor();

		return array_map(static fn (int $id): array => [$vehicles[$id], $named[$id]], array_keys($vehicles));
	}

	/**
	 * Every vehicle one account owns, deleted ones too, by id: an erasure closes them all
	 * (ErasureService::erase()) and holds them in this order.
	 *
	 * @return list<Vehicle>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyOwnedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Every vehicle on the instance, for the admin's commands. `occ nextfleet:vehicles` lists live
	 * ones or the trash, never both, since a uuid from the trash takes other commands than a live
	 * one; check and recompute take both.
	 *
	 * @param string|null $owner null for every owner
	 * @param bool|null $deleted the trash, the live ones, or null for both
	 * @return list<Vehicle> by owner, then plate
	 * @throws \OCP\DB\Exception
	 */
	public function findForAdmin(?string $owner, ?bool $deleted): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->orderBy('user_id', 'ASC')
			->addOrderBy('plate', 'ASC')
			->addOrderBy('id', 'ASC');
		if ($deleted !== null) {
			$qb->andWhere($deleted ? $qb->expr()->isNotNull('deleted_at') : $qb->expr()->isNull('deleted_at'));
		}
		if ($owner !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * The vehicles one user reaches: the ones they own, and the ones they were granted, which
	 * VehicleAccess has already resolved to ids. Ordering is the overview's business - it sorts
	 * by urgency, which is not a column (docs/ui.md) - so this only makes the order stable.
	 *
	 * @param list<int> $grantedIds
	 * @return list<Vehicle>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllVisible(string $userId, array $grantedIds): array {
		$qb = $this->db->getQueryBuilder();
		$mine = $qb->expr()->eq('user_id', $qb->createNamedParameter($userId));
		// An empty IN () is not a predicate any of the three databases accepts.
		$reachable = $grantedIds === [] ? $mine : $qb->expr()->orX(
			$mine,
			InList::in($qb, 'id', $grantedIds, IQueryBuilder::PARAM_INT_ARRAY),
		);

		$qb->select('*')
			->from($this->tableName)
			->where($reachable)
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}
