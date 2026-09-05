<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
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
	 * The vehicles one user owns. Ordering is the overview's business - it sorts by urgency,
	 * which is not a column (docs/ui.md) - so this only makes the order stable.
	 *
	 * @return list<Vehicle>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllForOwner(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}
