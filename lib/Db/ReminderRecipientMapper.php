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
 * @template-extends BaseMapper<ReminderRecipient>
 */
class ReminderRecipientMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_reminder_recipients', ReminderRecipient::class);
	}

	/** `user_id` for the export; an erasure finds none to rewrite, as deleteAccount() went first. */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * Every vehicle whose list names somebody, or `$userId` when given. The vehicle's own
	 * `deleted_at` is not asked: a vehicle in the trash keeps its list for an undo.
	 *
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function findVehicleIds(?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where($qb->expr()->isNull('deleted_at'))
			->orderBy('vehicle_id');
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Takes one user off a vehicle's list for good. Not a soft delete: nothing undoes it, and the
	 * unique index on (vehicle_id, user_id) would refuse adding them again beside a hidden row.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function deleteByUser(int $vehicleId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Takes one account off every vehicle's list, as deleteByUser() does for one. A deleted
	 * account receives nothing, so an erasure removes these rather than renaming them.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function deleteAccount(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
