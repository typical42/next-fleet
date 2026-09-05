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
	 * Every vehicle one user reaches through a grant, so the overview can widen from what they
	 * own to what they may see. Ids, not vehicles: the rows live in the other table.
	 *
	 * @param list<string> $groupIds
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function findVehicleIds(string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where($this->grantedTo($qb, $userId, $groupIds))
			->andWhere($qb->expr()->isNull('deleted_at'));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['vehicle_id'];
		}
		$result->closeCursor();

		return $ids;
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
			$qb->expr()->in('grantee', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
		));
	}
}
