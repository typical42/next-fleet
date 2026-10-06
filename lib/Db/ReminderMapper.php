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
	 * findByVehicle() for many vehicles in one query, by vehicle.
	 *
	 * @param list<int> $vehicleIds
	 * @return array<int, list<Reminder>> a vehicle without a live reminder is missing
	 * @throws \OCP\DB\Exception
	 */
	public function findByVehicles(array $vehicleIds): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		$byVehicle = [];
		foreach ($this->findEntities($qb) as $reminder) {
			$byVehicle[$reminder->getVehicleId()][] = $reminder;
		}

		return $byVehicle;
	}
}
