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
 * @template-extends BaseMapper<ReminderReceipt>
 */
class ReminderReceiptMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_reminder_receipts', ReminderReceipt::class);
	}
}
