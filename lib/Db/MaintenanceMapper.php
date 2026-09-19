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
 * @template-extends BaseMapper<Maintenance>
 */
class MaintenanceMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_maintenance', Maintenance::class);
	}

	/**
	 * One vehicle's records that name a vendor, the latest first - the history the sheet's vendor
	 * field completes from (docs/ui.md). Bounded for the reason EnergyMapper::findLatestAtStations()
	 * gives.
	 *
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findLatestWithVendor(int $vehicleId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->isNotNull('vendor'))
			->orderBy('done_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('done_at', $vehicleId, $from, $to));
	}

	/**
	 * One page of the timeline's Maintenance Records, newest first
	 * (docs/architecture.md#the-timeline).
	 *
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findBefore(int $vehicleId, int $at, int $id, int $limit): array {
		return $this->findEntities($this->pageBefore('done_at', $vehicleId, $at, $id, $limit));
	}
}
