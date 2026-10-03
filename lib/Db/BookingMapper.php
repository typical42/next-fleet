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
 * @template-extends BaseMapper<Booking>
 */
class BookingMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_bookings', Booking::class);
	}

	/**
	 * The booker as well as the author: a booking names the person who plans to drive.
	 */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * One vehicle's bookings, whatever their state, that reach past `from` and, given `to`, start
	 * before it - by start, as the Bookings section lists them.
	 *
	 * @return list<Booking>
	 * @throws \OCP\DB\Exception
	 */
	public function findSpanning(int $vehicleId, int $from, ?int $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gt('ends_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->orderBy('starts_at')
			->addOrderBy('id');
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lt('starts_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * The booking the vehicle is out under, if any. Check-out refuses a second, so there is one at
	 * most; the caller holds the vehicle for the same reason findLiveOverlapping()'s does.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findOut(int $vehicleId): ?Booking {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Booking::OUT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id')
			->setMaxResults(1);

		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * What the fleet list says about the pool, for all its vehicles in one query: every booking that
	 * is `out`, and the user's own `booked` ones not over by `now` that start before `until` - by
	 * start, so the first of a vehicle's is the next.
	 *
	 * @param list<int> $vehicleIds
	 * @return list<Booking>
	 * @throws \OCP\DB\Exception
	 */
	public function findPooled(array $vehicleIds, string $userId, int $now, int $until): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('*')
			->from($this->tableName)
			->where($expr->in('vehicle_id', $qb->createNamedParameter($vehicleIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($expr->isNull('deleted_at'))
			->andWhere($expr->orX(
				$expr->eq('state', $qb->createNamedParameter(Booking::OUT)),
				$expr->andX(
					$expr->eq('state', $qb->createNamedParameter(Booking::BOOKED)),
					$expr->eq('user_id', $qb->createNamedParameter($userId)),
					$expr->gt('ends_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
					$expr->lt('starts_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('starts_at')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * One vehicle's live bookings that share a second with `[from, to)`, by start
	 * (docs/architecture.md#data-model). The caller holds the vehicle, or two bookings checked at
	 * once could both find the span free.
	 *
	 * @param int|null $exceptId the booking being changed, which cannot collide with itself
	 * @return list<Booking>
	 * @throws \OCP\DB\Exception
	 */
	public function findLiveOverlapping(int $vehicleId, int $from, int $to, ?int $exceptId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(Booking::LIVE, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->lt('starts_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('ends_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->orderBy('starts_at')
			->addOrderBy('id');
		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($qb);
	}
}
