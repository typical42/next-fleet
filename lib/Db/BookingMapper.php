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
	 * before it - by start, as the Bookings section lists them. A car still `out` and one `returned`
	 * without its trip come whatever the window: the one is still to be given back, the other to be
	 * logged, however long ago it ended.
	 *
	 * @return list<Booking>
	 * @throws \OCP\DB\Exception
	 */
	public function findSpanning(int $vehicleId, int $from, ?int $to): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$window = $expr->andX($expr->gt('ends_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
		if ($to !== null) {
			$window->add($expr->lt('starts_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		}
		$qb->select('*')
			->from($this->tableName)
			->where($expr->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->isNull('deleted_at'))
			->andWhere($expr->orX(
				$window,
				$expr->eq('state', $qb->createNamedParameter(Booking::OUT)),
				$expr->andX(
					$expr->eq('state', $qb->createNamedParameter(Booking::RETURNED)),
					$expr->isNull('trip_id'),
				),
			))
			->orderBy('starts_at')
			->addOrderBy('id');

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
	 * For each booking taken, the main counter the car stood at when it was taken: the larger of the
	 * newest Reading at or before `out_at` - newest in OdoReadingMapper::findNewestAtOrBefore()'s
	 * order - and the counter of the newest return by then whose trip is not logged yet, which no
	 * Reading holds. A logged trip is the record and overrides the check-in it came from.
	 *
	 * Three queries for any number of bookings: the two instants per booking, each a MAX in the
	 * SELECT list that runs once per booking off the `(vehicle_id, read_at)` and
	 * `(vehicle_id, state)` indexes; then the rows at those instants by key. A MAX in a join
	 * condition would run once per candidate row and grow with the square of a vehicle's bookings.
	 *
	 * @param list<Booking> $bookings
	 * @return array<int, int> by booking id; one not taken, or with neither to go by, is left out
	 * @throws \OCP\DB\Exception
	 */
	public function findCountersAtOut(array $bookings): array {
		$ids = [];
		foreach ($bookings as $booking) {
			if ($booking->getOutAt() !== null) {
				$ids[] = (int)$booking->getId();
			}
		}
		if ($ids === []) {
			return [];
		}
		$instants = $this->instantsAtOut($ids);
		$readings = $this->atInstants('fleet_odo_readings', 'read_at', $instants);
		$returns = $this->atInstants($this->tableName, 'in_at', $instants);

		$counters = [];
		foreach ($instants as $at) {
			$id = $at['id'];
			// Rows sharing an instant come by id, so the last one read wins, as it does there.
			$reading = end($readings[$id]);
			$return = end($returns[$id]);
			if ($reading !== false || $return !== false) {
				$counters[$id] = max($reading === false ? PHP_INT_MIN : $reading, $return === false ? PHP_INT_MIN : $return);
			}
		}

		return $counters;
	}

	/**
	 * Per booking, the instant of the newest main Reading and of the newest unlogged return at or
	 * before it was taken.
	 *
	 * @param non-empty-list<int> $ids
	 * @return list<array{id: int, vehicle_id: int, read_at: ?int, in_at: ?int}>
	 * @throws \OCP\DB\Exception
	 */
	private function instantsAtOut(array $ids): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$newestReading = $this->db->getQueryBuilder();
		$newestReading->select($newestReading->func()->max('x.read_at'))
			->from('fleet_odo_readings', 'x')
			->where($expr->eq('x.vehicle_id', 'b.vehicle_id'))
			->andWhere($expr->lte('x.read_at', 'b.out_at'))
			->andWhere($expr->isNull('x.deleted_at'))
			->andWhere($expr->orX($expr->isNull('x.counter'), $expr->eq('x.counter', $qb->createNamedParameter(OdoReading::MAIN))));
		$newestReturn = $this->db->getQueryBuilder();
		$newestReturn->select($newestReturn->func()->max('y.in_at'))
			->from($this->tableName, 'y')
			->where($expr->eq('y.vehicle_id', 'b.vehicle_id'))
			->andWhere($expr->eq('y.state', $qb->createNamedParameter(Booking::RETURNED)))
			->andWhere($expr->lte('y.in_at', 'b.out_at'))
			->andWhere($expr->neq('y.id', 'b.id'))
			->andWhere($expr->isNull('y.trip_id'))
			->andWhere($expr->isNull('y.deleted_at'));
		$qb->select('b.id', 'b.vehicle_id')
			->selectAlias($qb->createFunction('(' . $newestReading->getSQL() . ')'), 'read_at')
			->selectAlias($qb->createFunction('(' . $newestReturn->getSQL() . ')'), 'in_at')
			->from($this->tableName, 'b')
			->where(InList::in($qb, 'b.id', $ids, IQueryBuilder::PARAM_INT_ARRAY));

		$instants = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$instants[] = [
				'id' => (int)$row['id'],
				'vehicle_id' => (int)$row['vehicle_id'],
				'read_at' => $row['read_at'] === null ? null : (int)$row['read_at'],
				'in_at' => $row['in_at'] === null ? null : (int)$row['in_at'],
			];
		}
		$result->closeCursor();

		return $instants;
	}

	/**
	 * The counters at the instants instantsAtOut() found, by booking, oldest id first: a main
	 * Reading's value, or an unlogged return's `in_odo` - never the booking's own.
	 *
	 * @param 'fleet_odo_readings'|'fleet_bookings' $table
	 * @param 'read_at'|'in_at' $instant
	 * @param list<array{id: int, vehicle_id: int, read_at: ?int, in_at: ?int}> $bookings
	 * @return array<int, list<int>> every booking id, its list empty when nothing stood there
	 * @throws \OCP\DB\Exception
	 */
	private function atInstants(string $table, string $instant, array $bookings): array {
		$found = array_fill_keys(array_column($bookings, 'id'), []);
		$at = array_values(array_unique(array_filter(array_column($bookings, $instant), static fn (?int $at): bool => $at !== null)));
		if ($at === []) {
			return $found;
		}
		// An instant came from a booking, so there is one.
		/** @var non-empty-list<int> $vehicleIds */
		$vehicleIds = array_values(array_unique(array_column($bookings, 'vehicle_id')));
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('id', 'vehicle_id', $instant)
			->selectAlias($table === $this->tableName ? 'in_odo' : 'value', 'odo')
			->from($table)
			->where(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere(InList::in($qb, $instant, $at, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($expr->isNull('deleted_at'))
			->orderBy('id');
		if ($table === $this->tableName) {
			$qb->andWhere($expr->eq('state', $qb->createNamedParameter(Booking::RETURNED)))
				->andWhere($expr->isNull('trip_id'));
		} else {
			$qb->andWhere($expr->orX($expr->isNull('counter'), $expr->eq('counter', $qb->createNamedParameter(OdoReading::MAIN))));
		}

		$byKey = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$byKey[$row['vehicle_id'] . '@' . $row[$instant]][] = [(int)$row['id'], $row['odo'] === null ? null : (int)$row['odo']];
		}
		$result->closeCursor();

		foreach ($bookings as $booking) {
			$key = $booking['vehicle_id'] . '@' . ($booking[$instant] ?? '');
			foreach ($byKey[$key] ?? [] as [$id, $odo]) {
				if ($odo !== null && !($table === $this->tableName && $id === $booking['id'])) {
					$found[$booking['id']][] = $odo;
				}
			}
		}

		return $found;
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
			->where(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY))
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
	 * (docs/architecture.md#data-model). An `out` one holds `[min(starts_at, out_at), max(ends_at,
	 * now))`: the car is gone from the moment it was taken early until it is back, however overdue.
	 * The caller holds the vehicle, or two bookings checked at once could both find the span free.
	 *
	 * @param int|null $exceptId the booking being changed, which cannot collide with itself
	 * @return list<Booking>
	 * @throws \OCP\DB\Exception
	 */
	public function findLiveOverlapping(int $vehicleId, int $from, int $to, int $now, ?int $exceptId = null): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$before = $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT);
		$after = $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT);
		// min() and max() spelled out, since SQLite has no LEAST() or GREATEST().
		$taken = $expr->andX(
			$expr->eq('state', $qb->createNamedParameter(Booking::OUT)),
			$expr->orX($expr->lt('starts_at', $before), $expr->lt('out_at', $before)),
		);
		if ($now <= $from) {
			$taken->add($expr->gt('ends_at', $after));
		}
		$qb->select('*')
			->from($this->tableName)
			->where($expr->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere(InList::in($qb, 'state', Booking::LIVE, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($expr->isNull('deleted_at'))
			->andWhere($expr->orX(
				$expr->andX($expr->lt('starts_at', $before), $expr->gt('ends_at', $after)),
				$taken,
			))
			->orderBy('starts_at')
			->addOrderBy('id');
		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities($qb);
	}
}
