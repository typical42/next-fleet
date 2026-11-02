<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<Expense>
 */
class ExpenseMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_expenses', Expense::class);
	}

	/**
	 * @return list<Expense>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('spent_at', $vehicleId, $from, $to));
	}

	/**
	 * @param list<int> $vehicleIds
	 * @throws \OCP\DB\Exception
	 */
	public function firstAmong(array $vehicleIds): ?int {
		return $this->earliest('spent_at', $vehicleIds);
	}

	/** @throws \OCP\DB\Exception */
	public function hasMoney(int $vehicleId): bool {
		return $this->anySet($vehicleId, ['amount']);
	}

	/**
	 * One page of the timeline's expenses, newest first (docs/architecture.md#the-timeline), all
	 * of them or one author's.
	 *
	 * @return list<Expense>
	 * @throws \OCP\DB\Exception
	 */
	public function findBefore(int $vehicleId, int $at, int $id, int $limit, ?string $createdBy = null): array {
		$qb = $this->pageBefore('spent_at', $vehicleId, $at, $id, $limit);
		if ($createdBy !== null) {
			$qb->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy)));
		}

		return $this->findEntities($qb);
	}
}
