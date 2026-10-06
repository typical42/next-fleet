<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\IDBConnection;

/**
 * Every table whose rows hang off a vehicle by `vehicle_id`. `occ nextfleet:check` looks in each
 * for a row whose vehicle is gone, so a table added with that column joins here.
 */
class VehicleTables {
	/** @var list<BaseMapper> */
	private array $all;

	public function __construct(
		private IDBConnection $db,
		AccessMapper $access,
		OdoReadingMapper $readings,
		TripMapper $trips,
		EnergyMapper $energy,
		MaintenanceMapper $maintenance,
		ExpenseMapper $expenses,
		ReminderMapper $reminders,
		ReminderRecipientMapper $recipients,
		DocumentMapper $documents,
		BookingMapper $bookings,
	) {
		$this->all = [$access, $readings, $trips, $energy, $maintenance, $expenses, $reminders, $recipients, $documents, $bookings];
	}

	/** @return list<BaseMapper> */
	public function all(): array {
		return $this->all;
	}

	/**
	 * The rows whose `vehicle_id` names no vehicle at all, deleted rows included: there are no
	 * foreign keys to stop one.
	 *
	 * @return list<array{table: string, uuid: string, vehicle_id: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function orphans(): array {
		$orphans = [];
		foreach ($this->all as $mapper) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('c.uuid', 'c.vehicle_id')
				->from($mapper->getTableName(), 'c')
				->leftJoin('c', 'fleet_vehicles', 'v', $qb->expr()->eq('c.vehicle_id', 'v.id'))
				->where($qb->expr()->isNull('v.id'))
				->orderBy('c.id', 'ASC');
			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				$orphans[] = ['table' => $mapper->getTableName(), 'uuid' => (string)$row['uuid'], 'vehicle_id' => (int)$row['vehicle_id']];
			}
			$result->closeCursor();
		}

		return $orphans;
	}
}
