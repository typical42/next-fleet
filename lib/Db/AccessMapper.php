<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<Access>
 */
class AccessMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_access', Access::class);
	}

	/**
	 * A grant to the account as well, for the reason VehicleMapper gives. Only a user grant: a
	 * group may carry the same name and is not the one being erased.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function pseudonymise(string $uid, string $pseudonym): int {
		$changed = parent::pseudonymise($uid, $pseudonym);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('grantee', $qb->createNamedParameter($pseudonym))
			->where($qb->expr()->eq('grantee', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter(Access::USER)));

		return $changed + $qb->executeStatement();
	}

	/** A grant to the account as well, for the reason pseudonymise() gives. */
	protected function naming(IQueryBuilder $qb, string $uid): array {
		return [...parent::naming($qb, $uid), $qb->expr()->andX(
			$qb->expr()->eq('grantee', $qb->createNamedParameter($uid)),
			$qb->expr()->eq('grantee_type', $qb->createNamedParameter(Access::USER)),
		)];
	}

	/**
	 * A user grantee as well, for the reason pseudonymise() gives.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	public function accountsStartingWith(string $prefix): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('grantee')
			->from($this->tableName)
			->where($qb->expr()->like('grantee', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')))
			->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter(Access::USER)));

		return array_values(array_unique([...parent::accountsStartingWith($prefix), ...$this->accounts($qb)]));
	}

	/**
	 * Removes one grant for good, where softDelete() would keep it. Only for a grant that never
	 * took effect - its grantee was gone the moment it committed - so there is no history to keep.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function discard(Access $grant): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($grant->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Every live grant on one vehicle, in the order they were given: the owner's list of who
	 * else may use it.
	 *
	 * @return list<Access>
	 * @throws \OCP\DB\Exception
	 */
	public function findByVehicle(int $vehicleId): array {
		$qb = $this->onVehicle($vehicleId);
		$qb->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntities($qb);
	}

	/**
	 * findByVehicle() with the revoked grants among them: who had access when.
	 *
	 * @return list<Access>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyByVehicle(int $vehicleId): array {
		return $this->findEntities($this->onVehicle($vehicleId));
	}

	private function onVehicle(int $vehicleId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');

		return $qb;
	}

	/**
	 * Every live grant to one group, on any vehicle, one in the trash included: a vehicle
	 * restored brings its grants back with it. With `$liveAt`, also those revoked since that
	 * moment: the grants that were live then.
	 *
	 * @return list<Access>
	 * @throws \OCP\DB\Exception
	 */
	public function findByGroup(string $groupId, ?int $liveAt = null): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('*')
			->from($this->tableName)
			->where($expr->eq('grantee_type', $qb->createNamedParameter(Access::GROUP)))
			->andWhere($expr->eq('grantee', $qb->createNamedParameter($groupId)))
			->andWhere($liveAt === null ? $expr->isNull('deleted_at') : $expr->orX(
				$expr->isNull('deleted_at'),
				$expr->gte('deleted_at', $qb->createNamedParameter($liveAt, IQueryBuilder::PARAM_INT)),
			))
			->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Whether anybody was ever given access to one vehicle, revoked grants included: the trips
	 * they entered stay on it after the revoke.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function everGranted(int $vehicleId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetch() !== false;
		$result->closeCursor();

		return $found;
	}

	/**
	 * everGranted() for a whole list in one query: the ids of those anybody was ever given access to.
	 *
	 * @param list<int> $vehicleIds
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function everGrantedAmong(array $vehicleIds): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY));
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * What one user holds on one vehicle, their groups' grants included. More than one row can
	 * come back - a user granted in person and again through a group - and the widest of them
	 * decides (VehicleAccess).
	 *
	 * @param list<string> $groupIds
	 * @return list<Access>
	 * @throws \OCP\DB\Exception
	 */
	public function findGrants(int $vehicleId, string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($this->grantedTo($qb, $userId, $groupIds))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntities($qb);
	}

	/**
	 * Every vehicle one user reaches through a grant in one of the given roles, with the roles
	 * they hold on it, so the overview can widen from what they own to what they may see and say
	 * what they may do there. Ids, not vehicles: the rows live in the other table. Which roles
	 * count is VehicleAccess's to say - the column takes any word.
	 *
	 * @param list<string> $groupIds
	 * @param list<string> $roles
	 * @return array<int, list<string>> roles by vehicle id
	 * @throws \OCP\DB\Exception
	 */
	public function findReachable(string $userId, array $groupIds, array $roles): array {
		// No role covers the operation, so no grant can - and an empty IN () would not parse.
		if ($roles === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['vehicle_id', 'role'])
			->from($this->tableName)
			->where($this->grantedTo($qb, $userId, $groupIds))
			->andWhere(InList::in($qb, 'role', $roles, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'));

		$result = $qb->executeQuery();
		$reachable = [];
		while (($row = $result->fetch()) !== false) {
			$reachable[(int)$row['vehicle_id']][] = (string)$row['role'];
		}
		$result->closeCursor();

		return $reachable;
	}

	/**
	 * The grantee half of both queries: this user by name, or any group they are in.
	 *
	 * @param list<string> $groupIds
	 */
	private function grantedTo(IQueryBuilder $qb, string $userId, array $groupIds): string|ICompositeExpression {
		$mine = $qb->expr()->andX(
			$qb->expr()->eq('grantee_type', $qb->createNamedParameter(Access::USER)),
			$qb->expr()->eq('grantee', $qb->createNamedParameter($userId)),
		);
		// An empty IN () is not a predicate any of the three databases accepts.
		if ($groupIds === []) {
			return $mine;
		}

		return $qb->expr()->orX($mine, $qb->expr()->andX(
			$qb->expr()->eq('grantee_type', $qb->createNamedParameter(Access::GROUP)),
			InList::in($qb, 'grantee', $groupIds, IQueryBuilder::PARAM_STR_ARRAY),
		));
	}
}
