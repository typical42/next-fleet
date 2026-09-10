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
 * @template-extends BaseMapper<Trip>
 */
class TripMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_trips', Trip::class);
	}

	/**
	 * One vehicle's trips in the order they happened, never the order they were entered, with
	 * `id` breaking a tie - the rule the readings follow
	 * (docs/architecture.md#odometer-rules), and what the index is on.
	 *
	 * A voided trip is not in it. It is soft-deleted like anything else, and the Fahrtenbuch
	 * export, which has to list it as voided, asks its own question.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllForVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('started_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One page of the timeline's trips, newest first (docs/architecture.md#the-timeline). A trip is
	 * dated by when it set off, which is the index the table carries and the date the row shows.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findBefore(int $vehicleId, int $at, int $id, int $limit): array {
		return $this->findEntities($this->pageBefore('started_at', $vehicleId, $at, $id, $limit));
	}
}
