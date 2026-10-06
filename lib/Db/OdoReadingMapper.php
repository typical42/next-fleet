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
	 * The main chains of these vehicles inside `[from, to]`, in findChain()'s order, by vehicle:
	 * what a reminder's pace reads (ReminderEngine::estimate()), for a whole fleet in one query
	 * off the `(vehicle_id, read_at)` index.
	 *
	 * @param list<int> $vehicleIds
	 * @return array<int, list<OdoReading>> a vehicle without a Reading there is missing
	 * @throws \OCP\DB\Exception
	 */
	public function findMainWithin(array $vehicleIds, int $from, int $to): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($this->onCounter($qb, OdoReading::MAIN))
			->andWhere($qb->expr()->gte('read_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('read_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->orderBy('read_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$byVehicle = [];
		foreach ($this->findEntities($qb) as $reading) {
			$byVehicle[$reading->getVehicleId()][] = $reading;
		}

		return $byVehicle;
	}

	/**
	 * Where a distance starts or ends (ConsumptionService::distance()): the newest Reading on the
	 * counter at or before `$readAt` that the chain does not question. One row off the
	 * `(vehicle_id, read_at)` index, however long the chain.
	 *
	 * With `$beforeId`, only one before that Reading, read at `$readAt`, in findChain()'s order:
	 * where the counter stood just before it was replaced.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @throws \OCP\DB\Exception
	 */
	public function findNewestStanding(int $vehicleId, string $counter, int $readAt, ?int $beforeId = null): ?OdoReading {
		$qb = $this->standing($vehicleId, $counter);
		$at = $qb->createNamedParameter($readAt, IQueryBuilder::PARAM_INT);
		$qb->andWhere($beforeId === null
			? $qb->expr()->lte('read_at', $at)
			: $qb->expr()->orX(
				$qb->expr()->lt('read_at', $at),
				$qb->expr()->andX(
					$qb->expr()->eq('read_at', $at),
					$qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('read_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->first($qb);
	}

	/**
	 * Where a distance starts when the counter was never read before the period: the oldest
	 * Reading at or after `$readAt` that the chain does not question.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @throws \OCP\DB\Exception
	 */
	public function findOldestStanding(int $vehicleId, string $counter, int $readAt): ?OdoReading {
		$qb = $this->standing($vehicleId, $counter);
		$qb->andWhere($qb->expr()->gte('read_at', $qb->createNamedParameter($readAt, IQueryBuilder::PARAM_INT)))
			->orderBy('read_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->first($qb);
	}

	/**
	 * The answered resets on one counter read from `$from` to `$to`, both included, in
	 * findChain()'s order: where its segments begin (rule 3). The database filters the period's
	 * index range by kind and hands back only the few resets; no index names `kind`.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findResets(int $vehicleId, string $counter, int $from, int $to): array {
		$qb = $this->live($vehicleId, $counter);
		$qb->andWhere($qb->expr()->gte('read_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('read_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(OdoReading::RESET)))
			->orderBy('read_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The live, unquestioned Readings of one counter. `flagged` is nullable, and null is unflagged
	 * (OdoReading::getFlagged()).
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 */
	private function standing(int $vehicleId, string $counter): IQueryBuilder {
		$qb = $this->live($vehicleId, $counter);
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->isNull('flagged'),
			$qb->expr()->eq('flagged', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
		));

		return $qb;
	}

	/**
	 * The live Readings of one counter.
	 *
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 */
	private function live(int $vehicleId, string $counter): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($this->onCounter($qb, $counter));

		return $qb;
	}

	/** @throws \OCP\DB\Exception */
	private function first(IQueryBuilder $qb): ?OdoReading {
		$qb->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The condition that keeps a query on one counter. A null `counter` is `main`
	 * (OdoReading::getCounter()).
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
	 * Marks a Reading as contradicted, or no longer contradicted. `flagged` is recomputed from the
	 * chain rather than edited, so the write checks no token: the caller holds the vehicle and has
	 * just read the row. It moves the token all the same, so a sync sends the rows whose flag
	 * changed and no others (docs/architecture.md#concurrency).
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function flag(OdoReading $reading, bool $flagged): void {
		$now = max($this->time->getTime(), $reading->getUpdatedAt() + 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('flagged', $qb->createNamedParameter($flagged, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$reading->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$reading->setFlagged($flagged);
		$reading->setUpdatedAt($now);
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
			->andWhere(InList::in($qb, 'source_id', $sourceIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'))
			// Main before second, so a row's Readings come in the order its fields do.
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Every live Reading one kind of Entry left on one counter, for a caller that wants them all
	 * (ConsumptionService::of()): one query off the `(vehicle_id, source_type, source_id)` index,
	 * where findForSources() would name every Entry of a long history.
	 *
	 * @param OdoReading::TRIP|OdoReading::ENERGY|OdoReading::MAINTENANCE $sourceType
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findOfSourceType(int $vehicleId, string $sourceType, string $counter): array {
		$qb = $this->live($vehicleId, $counter);
		$qb->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Hangs on each Reading the uuid of the Entry that wrote it, one query per InList chunk rather
	 * than one per Reading: a whole chain can come here. The Entry is found deleted or not: a
	 * Reading outlives its Entry only as a tombstone, which names it still.
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
		foreach (InList::chunks(array_keys($byId)) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('r.id')->from($this->tableName, 'r');
			foreach ($sources as $alias => [$table, $type]) {
				$qb->selectAlias($alias . '.uuid', $alias . '_uuid')
					->leftJoin('r', $table, $alias, $qb->expr()->andX(
						$qb->expr()->eq('r.source_type', $qb->createNamedParameter($type)),
						$qb->expr()->eq($alias . '.id', 'r.source_id'),
					));
			}
			$qb->where(InList::in($qb, 'r.id', $chunk, IQueryBuilder::PARAM_INT_ARRAY));

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
	 * Every Reading another Entry wrote on one vehicle, deleted ones included, by id: what
	 * `occ nextfleet:check` holds against the Entries (rule 5).
	 *
	 * @return list<OdoReading>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyFromEntries(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('source_type', $qb->createNamedParameter(OdoReading::MANUAL)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The live Entries of one kind on one vehicle that state a counter and have no Reading on it,
	 * live or deleted, though rule 5 has each write one: what findAnyFromEntries() cannot show.
	 *
	 * @param OdoReading::TRIP|OdoReading::ENERGY|OdoReading::MAINTENANCE $sourceType
	 * @param string $entries the Entries' table
	 * @param OdoReading::MAIN|OdoReading::SECOND $counter
	 * @param string|null $stated the Entry's column that states the counter; null where every Entry does, as a trip does `main`
	 * @return list<string> their uuids, by id
	 * @throws \OCP\DB\Exception
	 */
	public function findUnreadEntries(int $vehicleId, string $sourceType, string $entries, string $counter, ?string $stated): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$named = $expr->eq('r.counter', $qb->createNamedParameter($counter));
		$qb->select('e.uuid')
			->from($entries, 'e')
			->leftJoin('e', $this->tableName, 'r', $expr->andX(
				// Implied by the source, but it is what lets the (vehicle_id, source_type, source_id) index serve the join.
				$expr->eq('r.vehicle_id', 'e.vehicle_id'),
				$expr->eq('r.source_id', 'e.id'),
				$expr->eq('r.source_type', $qb->createNamedParameter($sourceType)),
				// As onCounter(), on the joined table.
				$counter === OdoReading::MAIN ? $expr->orX($expr->isNull('r.counter'), $named) : $named,
			))
			->where($expr->eq('e.vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->isNull('e.deleted_at'))
			->andWhere($expr->isNull('r.id'))
			->orderBy('e.id', 'ASC');
		if ($stated !== null) {
			$qb->andWhere($expr->isNotNull('e.' . $stated));
		}
		$result = $qb->executeQuery();
		$uuids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $uuids;
	}

	/**
	 * The one Reading a trip left on the counter (rule 5), deleted or not: the caller voids it with
	 * its trip and brings it back with it.
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
	 * in findChain()'s order. Null when the chain has none yet.
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
