<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;

/**
 * Whether a vehicle has any amount recorded in its currency: a priced fill-up, a costed record or
 * an expense, voided ones included.
 */
class MoneyRows {
	public function __construct(
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
	) {
	}

	/** @throws \OCP\DB\Exception */
	public function exist(int $vehicleId): bool {
		return $this->expenses->hasMoney($vehicleId)
			|| $this->energy->hasMoney($vehicleId)
			|| $this->maintenance->hasMoney($vehicleId);
	}
}
