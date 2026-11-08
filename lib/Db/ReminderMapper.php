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
	/** What a maintenance record's close or its take-back moves on a reminder. */
	public const COURSE = ['dueDate', 'dueOdo', 'occurrence', 'snoozedUntil', 'state'];

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

	/**
	 * Writes where a maintenance record's close leaves a deleted reminder (COURSE), and nothing
	 * else. The token stays, so the reminder's undo still matches the token its delete gave. A
	 * sync client sees the change once that undo moves the token. Until then the row is deleted
	 * for it anyway.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function writeWhileDeleted(Reminder $reminder): void {
		$qb = $this->writeOnly($reminder, self::COURSE);
		$qb->andWhere($qb->expr()->isNotNull('deleted_at'));
		$qb->executeStatement();
	}

	/**
	 * Writes the same as writeWhileDeleted() on a live reminder, the token kept: an undo that
	 * replays later deletes closes and reopens it on the way (ReminderService::replay()). The
	 * replay moves the token once at the end if the reminder ended elsewhere, so a sheet open on
	 * it is refused only for a change it can see.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function writeOnTheWay(Reminder $reminder): void {
		$qb = $this->writeOnly($reminder, self::COURSE);
		$qb->andWhere($qb->expr()->isNull('deleted_at'));
		$qb->executeStatement();
	}
}
