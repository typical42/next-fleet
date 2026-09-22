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
 * @template-extends BaseMapper<Reminder>
 */
class ReminderMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_reminders', Reminder::class);
	}

	/**
	 * One vehicle's live reminders, in the order they were made. Urgency is an evaluation at an
	 * instant, not a column, so it is not this query's to sort by.
	 *
	 * @return list<Reminder>
	 * @throws \OCP\DB\Exception
	 */
	public function findByVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * The reminders a vehicle's maintenance records closed, deleted ones too: `reminder_id` is an
	 * id, and the wire names a reminder by its uuid.
	 *
	 * @param list<int> $ids
	 * @return array<int, Reminder> by id
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyByIds(int $vehicleId, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$byId = [];
		foreach ($this->findEntities($qb) as $reminder) {
			$byId[(int)$reminder->getId()] = $reminder;
		}

		return $byId;
	}
}
