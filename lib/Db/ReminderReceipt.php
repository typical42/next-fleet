<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * That one warning point of one occurrence reached one recipient on one channel, one property
 * per column of `fleet_reminder_receipts` (docs/architecture.md#reminder-engine).
 *
 * @method int getReminderId()
 * @method void setReminderId(int $reminderId)
 * @method int getOccurrence()
 * @method void setOccurrence(int $occurrence)
 * @method string getPoint()
 * @method void setPoint(string $point)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getSentAt()
 * @method void setSentAt(int $sentAt)
 */
class ReminderReceipt extends BaseEntity {
	public const APP = 'app';
	public const MAIL = 'mail';

	protected int $reminderId = 0;
	protected int $occurrence = 0;
	protected string $point = '';
	protected string $channel = '';
	protected string $userId = '';
	protected int $sentAt = 0;

	public function __construct() {
		parent::__construct();
		$this->addType('reminderId', Types::BIGINT);
		$this->addType('occurrence', Types::INTEGER);
		$this->addType('point', Types::STRING);
		$this->addType('channel', Types::STRING);
		$this->addType('userId', Types::STRING);
		$this->addType('sentAt', Types::BIGINT);
	}
}
