<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<CostCentre>
 */
class CostCentreMapper extends BaseMapper {
	/** The tables whose rows name a cost centre (CostCentred). */
	private const NAMING = ['fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_vehicles'];
	/** The entry tables whose rows are on no trail. */
	private const UNAUDITED = ['fleet_energy', 'fleet_maintenance', 'fleet_expenses'];

	/** What an erased record keeps for a name, for the reason Partner::ERASED gives. */
	public const ERASED = '-';

	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_cost_centres', CostCentre::class);
	}

	/** `user_id` is the owner whose list it is. */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * These owners' lists, archived records too, by name.
	 *
	 * @param list<string> $userIds
	 * @return list<CostCentre>
	 * @throws \OCP\DB\Exception
	 */
	public function findOf(array $userIds): array {
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('user_id')
			->addOrderBy('name')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * The live record in the owner's list whose column holds this value, archived ones included.
	 * The value and the owner match byte for byte, as PartnerMapper::findByName() matches a name.
	 *
	 * @param 'code'|'name' $column
	 * @throws \OCP\DB\Exception
	 */
	public function findBy(string $userId, string $column, string $value): ?CostCentre {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		foreach ($this->findEntities($qb) as $centre) {
			if (($column === 'code' ? $centre->getCode() : $centre->getName()) === $value && $centre->getUserId() === $userId) {
				return $centre;
			}
		}

		return null;
	}

	/**
	 * Holds the record's row until the caller's transaction ends, and changes nothing on it, as
	 * VehicleMapper::hold() holds a vehicle: a delete and a row that comes to name the record wait
	 * for each other, so the delete sees every row that names it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function hold(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('name', 'name')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Whether a row of any state names the record: a voided trip or an expense in the trash comes
	 * back with it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function isNamed(int $id): bool {
		foreach (self::NAMING as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($table)
				->where($qb->expr()->eq('cost_centre_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$found = $result->fetchOne();
			$result->closeCursor();
			if ($found !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The records the vehicle's fill-ups, maintenance records and expenses name, in any state.
	 * Its trips are read as rows, since each move of theirs is audited (TripService).
	 *
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function namedOn(int $vehicleId): array {
		$ids = [];
		foreach (self::UNAUDITED as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('cost_centre_id')
				->from($table)
				->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNotNull('cost_centre_id'));
			$result = $qb->executeQuery();
			while (($id = $result->fetchOne()) !== false) {
				$ids[] = (int)$id;
			}
			$result->closeCursor();
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Moves what the vehicle's fill-ups, maintenance records and expenses name from one record to
	 * another, in any state. Their tokens stay: the caller resets the sync epoch, and none of them
	 * is on a trail.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function moveOn(int $vehicleId, int $from, int $to): void {
		foreach (self::UNAUDITED as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('cost_centre_id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('cost_centre_id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/**
	 * Records by id, deleted ones too, keyed by id.
	 *
	 * @param list<int> $ids
	 * @return array<int, CostCentre>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyOf(array $ids): array {
		$byId = [];
		foreach (InList::chunks($ids) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->tableName)
				->where(InList::in($qb, 'id', $chunk, IQueryBuilder::PARAM_INT_ARRAY));
			foreach ($this->findEntities($qb) as $centre) {
				$byId[(int)$centre->getId()] = $centre;
			}
		}

		return $byId;
	}

	/**
	 * What a sync hands over of these owners' lists, as PartnerMapper::findChangedOf() does.
	 *
	 * @param list<string> $userIds
	 * @return list<CostCentre>
	 * @throws \OCP\DB\Exception
	 */
	public function findChangedOf(array $userIds, ?int $since): array {
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($since === null
				? $qb->expr()->isNull('deleted_at')
				: $qb->expr()->gt('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Deletes the owner's whole list as PartnerMapper::eraseListOf() does: the rows stay as
	 * tombstones each entry keeps as its mark, without code or name.
	 *
	 * @return int how many live records it deleted
	 * @throws \OCP\DB\Exception
	 */
	public function eraseListOf(string $userId): int {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$deleted = $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('name', $qb->createNamedParameter(self::ERASED))
			->set('code', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		return $deleted;
	}
}
