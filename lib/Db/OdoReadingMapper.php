<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<OdoReading>
 */
class OdoReadingMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_odo_readings', OdoReading::class);
	}

	/**
	 * One vehicle's readings in the only order they mean anything: by when they were read, never
	 * by value, with `id` breaking a tie so a trip entered three days late lands where it
	 * happened (docs/architecture.md#odometer-rules).
	 *
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllForVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('read_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Marks a Reading as contradicted, or no longer contradicted. Like `odo_value` on the
	 * vehicle, `flagged` is recomputed from the chain rather than edited, so writing it neither
	 * re-dates the row nor takes a concurrency token: two entries landing at once would both
	 * recompute the same answer, and a checked write would refuse the second of them after its
	 * Reading was already in.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function flag(OdoReading $reading, bool $flagged): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('flagged', $qb->createNamedParameter($flagged, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$reading->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$reading->setFlagged($flagged);
		$reading->resetUpdatedFields();
	}

	/**
	 * One page of the timeline's Odometer Entries, newest first
	 * (docs/architecture.md#the-timeline). Only the Entries: every other Reading was written by an
	 * Entry with content of its own (CONTEXT.md), and that Entry is the row - listing both would
	 * show every trip twice.
	 *
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findEntriesBefore(int $vehicleId, int $at, int $id, int $limit): array {
		$qb = $this->pageBefore('read_at', $vehicleId, $at, $id, $limit);
		$qb->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter(OdoReading::MANUAL)));

		return $this->findEntities($qb);
	}

	/**
	 * The Readings a set of trips left on the counter, so a timeline page asks once for all of
	 * them rather than once per row.
	 *
	 * @param list<int> $tripIds
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findForTrips(int $vehicleId, array $tripIds): array {
		if ($tripIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter(OdoReading::TRIP)))
			->andWhere($qb->expr()->in('source_id', $qb->createNamedParameter($tripIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntities($qb);
	}

	/**
	 * The reading a distance counts from: the newest one at or before that moment, in the same
	 * order. Null when the vehicle has none yet, which is a distance with nothing to add to.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findNewestAtOrBefore(int $vehicleId, int $readAt): ?OdoReading {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('read_at', $qb->createNamedParameter($readAt, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('read_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
