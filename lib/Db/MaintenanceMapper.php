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

	/** @throws \OCP\DB\Exception */
	public function hasMoney(int $vehicleId): bool {
		return $this->anySet($vehicleId, ['cost']);
	}

	/**
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('done_at', $vehicleId, $from, $to));
	}

	/**
	 * @param list<int> $vehicleIds
	 * @throws \OCP\DB\Exception
	 */
	public function firstAmong(array $vehicleIds): ?int {
		return $this->earliest('done_at', $vehicleIds);
	}

	/**
	 * The live record with the smallest `closed_occurrence` above `$occurrence`, which can lie
	 * further up than the next one. Null when there is none.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findClosingAbove(int $vehicleId, int $reminderId, int $occurrence): ?Maintenance {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reminder_id', $qb->createNamedParameter($reminderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('closed_occurrence', $qb->createNamedParameter($occurrence, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('closed_occurrence', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(1);

		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * The live records other than `$exceptId` that closed that occurrence of the reminder.
	 *
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findClosingOthers(int $vehicleId, int $reminderId, int $exceptId, int $occurrence): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reminder_id', $qb->createNamedParameter($reminderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->eq('closed_occurrence', $qb->createNamedParameter($occurrence, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * The deleted records on the reminder whose delete came after this record's, newest first. Only
	 * a delete that stored what its take-back did (`took_back` set) counts: an undo can replay it. A
	 * delete comes after another by `deleted_at`. Within one second the order is a guess: the token
	 * it gave, then the id. Two rows last written before that second both get that second as their
	 * token, so their order is then the order they were made in.
	 *
	 * @return list<Maintenance>
	 * @throws \OCP\DB\Exception
	 */
	public function findDeletedAfter(Maintenance $record): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$deletedAt = $qb->createNamedParameter((int)$record->getDeletedAt(), IQueryBuilder::PARAM_INT);
		$token = $qb->createNamedParameter($record->getUpdatedAt(), IQueryBuilder::PARAM_INT);
		$qb->select('*')
			->from($this->tableName)
			->where($expr->eq('vehicle_id', $qb->createNamedParameter($record->getVehicleId(), IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('reminder_id', $qb->createNamedParameter((int)$record->getReminderId(), IQueryBuilder::PARAM_INT)))
			->andWhere($expr->isNotNull('took_back'))
			->andWhere($expr->orX(
				$expr->gt('deleted_at', $deletedAt),
				$expr->andX($expr->eq('deleted_at', $deletedAt), $expr->gt('updated_at', $token)),
				$expr->andX(
					$expr->eq('deleted_at', $deletedAt),
					$expr->eq('updated_at', $token),
					$expr->gt('id', $qb->createNamedParameter((int)$record->getId(), IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('deleted_at', 'DESC')
			->addOrderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Writes `deleted_at` alone, the token kept: an undo that replays a later delete takes it off
	 * and puts it back, and that record's own undo must still match the token its delete gave.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function writeDeletedAt(Maintenance $record): void {
		$this->writeOnly($record, ['deletedAt'])
			->executeStatement();
	}

	/**
	 * Writes what the record kept for its close, whom it handed that to and whether its delete took
	 * the close back, and nothing else, live or deleted. The token stays: these columns are off the
	 * wire, so a moved one would refuse the record's author a write for a change they cannot see.
	 * The other columns are not written, so no edit of them is lost.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function writeKept(Maintenance $record): void {
		$this->writeOnly($record, Maintenance::KEPT)
			->executeStatement();
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
