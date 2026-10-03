<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
	 * happened (docs/architecture.md#odometer-rules). Both counters, interleaved: this is the
	 * odometer as a list, and a rule that compares Readings reads findChain() instead.
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
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('read_at', $vehicleId, $from, $to));
	}

	/**
	 * Which of these vehicles had a Reading written, deleted or restored after `$since`. A sync
	 * sends such a vehicle's every live Reading, since settling a chain re-flags rows without
	 * moving their token (OdometerService::settle()).
	 *
	 * @param list<int> $vehicleIds
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function findVehiclesChangedSince(array $vehicleIds, int $since): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($vehicleIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gt('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}

	/**
	 * One counter's chain, in findAllForVehicle()'s order. Every rule that compares a Reading with
	 * its neighbours reads this, because a Reading in hours next to one in kilometres is no
	 * contradiction (rule 4).
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findChain(int $vehicleId, string $counter): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($this->onCounter($qb, $counter))
			->orderBy('read_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The condition that keeps a query on one counter. A null `counter` is `main`: every Reading
	 * from before M3 is on the only counter there was (OdoReading::getCounter()).
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 */
	private function onCounter(IQueryBuilder $qb, string $counter): ICompositeExpression|string {
		$named = $qb->expr()->eq('counter', $qb->createNamedParameter($counter));

		return $counter === OdoReading::MAIN
			? $qb->expr()->orX($qb->expr()->isNull('counter'), $named)
			: $named;
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
	 * The Readings a set of Entries of one kind left on the counter, so a timeline page asks once
	 * per kind rather than once per row. A source id is only unique within its kind's table, so the
	 * kind is part of the question.
	 *
	 * @param OdoReading::TRIP|OdoReading::ENERGY|OdoReading::MAINTENANCE $sourceType
	 * @param list<int> $sourceIds
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findForSources(int $vehicleId, string $sourceType, array $sourceIds): array {
		if ($sourceIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)))
			->andWhere($qb->expr()->in('source_id', $qb->createNamedParameter($sourceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			// Main before second, so a row's Readings come in the order its fields do.
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Hangs on each Reading the uuid of the Entry that wrote it, one query per thousand Readings
	 * rather than one per Reading. A thousand because Oracle refuses a longer `IN`. The Entry is
	 * found deleted or not: a Reading outlives its Entry only as a tombstone, which names it still.
	 *
	 * @param list<OdoReading> $readings
	 * @throws \OCP\DB\Exception
	 */
	public function nameSources(array $readings): void {
		$byId = [];
		foreach ($readings as $reading) {
			if ($reading->getSourceType() !== OdoReading::MANUAL) {
				$byId[(int)$reading->getId()] = $reading;
			}
		}

		$sources = ['t' => ['fleet_trips', OdoReading::TRIP], 'e' => ['fleet_energy', OdoReading::ENERGY], 'm' => ['fleet_maintenance', OdoReading::MAINTENANCE]];
		foreach (array_chunk(array_keys($byId), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('r.id')->from($this->tableName, 'r');
			foreach ($sources as $alias => [$table, $type]) {
				$qb->selectAlias($alias . '.uuid', $alias . '_uuid')
					->leftJoin('r', $table, $alias, $qb->expr()->andX(
						$qb->expr()->eq('r.source_type', $qb->createNamedParameter($type)),
						$qb->expr()->eq($alias . '.id', 'r.source_id'),
					));
			}
			$qb->where($qb->expr()->in('r.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$byId[(int)$row['id']]->setSourceUuid($row['t_uuid'] ?? $row['e_uuid'] ?? $row['m_uuid']);
			}
			$result->closeCursor();
		}
	}

	/**
	 * Every Reading one fill-up or Maintenance Record ever wrote, in any state, oldest first. At
	 * most one per counter is live, and it is the newest of its counter: an Entry writes a new one
	 * only while it has none standing (OdometerService::followEntry()).
	 *
	 * @param OdoReading::ENERGY|OdoReading::MAINTENANCE $sourceType
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyForSource(int $vehicleId, string $sourceType, int $sourceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)))
			->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The one Reading a trip left on the counter (rule 5), whatever state it is in. `deleted_at`
	 * is not filtered for the reason `findAnyByUuid` does not filter it: the caller is the one
	 * that voids the Reading with its trip and brings it back with it, so a voided one is
	 * precisely the row it is after.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyForTrip(int $vehicleId, int $tripId): ?OdoReading {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter(OdoReading::TRIP)))
			->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The reading a distance counts from: the newest one on that counter at or before that moment,
	 * in the same order. Null when the chain has none yet, which is a distance with nothing to add to.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @param ?int $except a Reading that is not a candidate: the one being restated
	 * @throws \OCP\DB\Exception
	 */
	public function findNewestAtOrBefore(int $vehicleId, string $counter, int $readAt, ?int $except = null): ?OdoReading {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('read_at', $qb->createNamedParameter($readAt, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($this->onCounter($qb, $counter))
			->orderBy('read_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		if ($except !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($except, IQueryBuilder::PARAM_INT)));
		}

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
