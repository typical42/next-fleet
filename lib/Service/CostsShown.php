<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Vehicle;

/**
 * An Entry's money as the reader may see it (VehicleAccess::COSTS), for every door an Entry or its
 * receipt leaves by: the timeline, sync, the CSV files, the papers. Read off what the gate left on
 * the vehicle.
 */
class CostsShown {
	public function __construct(
		private VehicleAccess $access,
	) {
	}

	/** Whether the reader sees every amount on the vehicle, its sums included. */
	public static function all(Vehicle $vehicle): bool {
		return $vehicle->showsCosts();
	}

	/**
	 * Whose expenses the reader may read, null for everybody's. An expense is nothing but its
	 * amount, so one kept from the reader is no row at all; a query narrowed to the author keeps
	 * a page the length it says.
	 */
	public static function expensesBy(string $userId, Vehicle $vehicle): ?string {
		return self::all($vehicle) ? null : $userId;
	}

	/**
	 * A fill-up or record as the reader may see it: whole, or a copy without its amounts.
	 *
	 * @template E of Energy|Maintenance
	 * @param E $entry
	 * @return E
	 */
	public function amounts(string $userId, Vehicle $vehicle, Energy|Maintenance $entry): Energy|Maintenance {
		return $this->access->mayCost($userId, $vehicle, $entry->getCreatedBy()) ? $entry : $entry->withoutCost();
	}

	/** Whether the reader may see this expense at all. */
	public function showsExpense(string $userId, Vehicle $vehicle, Expense $expense): bool {
		return $this->access->mayCost($userId, $vehicle, $expense->getCreatedBy());
	}

	/**
	 * Whether the reader may see a paper on `$row`, null for one of the vehicle's own. A receipt
	 * shows what the entry cost, so a paper on an Entry follows that Entry's amounts; a handover
	 * photo or the vehicle's own papers carry no cost.
	 */
	public function showsPaper(string $userId, Vehicle $vehicle, ?BaseEntity $row): bool {
		return !($row instanceof Energy || $row instanceof Maintenance || $row instanceof Expense)
			|| $this->access->mayCost($userId, $vehicle, $row->getCreatedBy());
	}

	/**
	 * A list of one table's Entries as the reader may see them, in its order: fill-ups and
	 * records by amounts(), the expenses kept from the reader left out.
	 *
	 * @template E of Energy|Maintenance|Expense
	 * @param list<E> $entries
	 * @return list<E>
	 */
	public function entries(string $userId, Vehicle $vehicle, array $entries): array {
		$shown = [];
		foreach ($entries as $entry) {
			if (!$entry instanceof Expense) {
				$shown[] = $this->amounts($userId, $vehicle, $entry);
			} elseif ($this->showsExpense($userId, $vehicle, $entry)) {
				$shown[] = $entry;
			}
		}

		return $shown;
	}
}
