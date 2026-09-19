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
 * @template-extends BaseMapper<Energy>
 */
class EnergyMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_energy', Energy::class);
	}

	/**
	 * One vehicle's fill-ups that name a station, the latest first - the history the sheet's
	 * station field completes from (docs/ui.md). Bounded, because a suggestion list is read from
	 * the recent past and a vehicle's whole life is not needed to find it.
	 *
	 * @return list<Energy>
	 * @throws \OCP\DB\Exception
	 */
	public function findLatestAtStations(int $vehicleId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->isNotNull('station'))
			->orderBy('filled_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Every live fill-up of one vehicle, oldest first - the order a full-to-full segment is read in
	 * (docs/architecture.md#numbers-consumption-cost-emissions). Not bounded: the first fill-up
	 * decides whether the second one closes a segment.
	 *
	 * @return list<Energy>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllForVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('filled_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return list<Energy>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('filled_at', $vehicleId, $from, $to));
	}

	/**
	 * One page of the timeline's fill-ups, newest first (docs/architecture.md#the-timeline).
	 *
	 * @return list<Energy>
	 * @throws \OCP\DB\Exception
	 */
	public function findBefore(int $vehicleId, int $at, int $id, int $limit): array {
		return $this->findEntities($this->pageBefore('filled_at', $vehicleId, $at, $id, $limit));
	}
}
