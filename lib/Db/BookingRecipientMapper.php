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
 * Rows go for good rather than softly, for the reason ReminderRecipientMapper::deleteByUser()
 * gives.
 *
 * @template-extends BaseMapper<BookingRecipient>
 */
class BookingRecipientMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_booking_recipients', BookingRecipient::class);
	}

	/** `user_id` for the export; an erasure finds none to rewrite, as deleteEverywhere() went first. */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * Every vehicle whose list names the account.
	 *
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function findVehicleIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('vehicle_id');
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Takes one user or group off one vehicle's list.
	 *
	 * @param Access::USER|Access::GROUP $type
	 * @throws \OCP\DB\Exception
	 */
	public function deleteOne(int $vehicleId, string $type, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq($type === Access::GROUP ? 'group_id' : 'user_id', $qb->createNamedParameter($name)));
		$qb->executeStatement();
	}

	/**
	 * Takes one account or one group off every list: a deleted one receives nothing, so it is
	 * removed rather than renamed.
	 *
	 * @param Access::USER|Access::GROUP $type
	 * @param int|null $until only the places given by then, as GrantService::forgetGroup() takes
	 * @throws \OCP\DB\Exception
	 */
	public function deleteEverywhere(string $type, string $name, ?int $until = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq($type === Access::GROUP ? 'group_id' : 'user_id', $qb->createNamedParameter($name)));
		if ($until !== null) {
			$qb->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT)));
		}
		$qb->executeStatement();
	}
}
