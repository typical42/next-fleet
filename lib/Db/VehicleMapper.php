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
 * @template-extends BaseMapper<Vehicle>
 */
class VehicleMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_vehicles', Vehicle::class);
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
			$qb->expr()->in('id', $qb->createNamedParameter($grantedIds, IQueryBuilder::PARAM_INT_ARRAY)),
		);

		$qb->select('*')
			->from($this->tableName)
			->where($reachable)
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}
