<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One user a vehicle's reminders go to, one property per column of `fleet_reminder_recipients`.
 * Being on the list grants nothing: Vehicle Access decides what the link opens.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 */
class ReminderRecipient extends BaseEntity {
	protected int $vehicleId = 0;
	protected string $userId = '';

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('userId', Types::STRING);
	}
}
