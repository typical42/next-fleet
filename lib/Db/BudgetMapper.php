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
 * @template-extends BaseMapper<Budget>
 */
class BudgetMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_budgets', Budget::class);
	}

	/**
	 * One vehicle's live budgets, by year.
	 *
	 * @return list<Budget>
	 * @throws \OCP\DB\Exception
	 */
	public function findOf(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('year')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Whether the vehicle has a live budget. A deleted one does not count: no undo brings it back.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function hasMoney(int $vehicleId): bool {
		return $this->findOf($vehicleId) !== [];
	}

	/**
	 * The vehicle's live budget for the year, if it has one.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findYear(int $vehicleId, int $year): ?Budget {
		foreach ($this->findOf($vehicleId) as $budget) {
			if ($budget->getYear() === $year) {
				return $budget;
			}
		}

		return null;
	}

	/**
	 * The live budgets of these years with a share left to warn of, of every vehicle, by vehicle.
	 *
	 * @param non-empty-list<int> $years
	 * @return list<Budget>
	 * @throws \OCP\DB\Exception
	 */
	public function findArmed(array $years, int $below): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'year', $years, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->lt('warned', $qb->createNamedParameter($below, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('vehicle_id')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Stores the share the warning has told. The token stays: the share is no field of the
	 * budget's answer, so a client holds nothing it changed, and an edit open meanwhile is not
	 * refused as stale for it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function warn(Budget $budget, int $warned): void {
		$budget->setWarned($warned);
		$this->writeOnly($budget, ['warned'])->executeStatement();
		$budget->resetUpdatedFields();
	}
}
