<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\BackgroundJob;

use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly: every reminder evaluated, its state persisted, its recipients told, and the day's mail
 * digests sent (docs/architecture.md#reminder-engine). `occ background-job:execute <id> --force-execute` runs
 * it on demand.
 */
class ReminderJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private NotificationService $notifications,
		private MailService $mail,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	/**
	 * @param mixed $argument
	 * @throws \OCP\DB\Exception
	 */
	protected function run($argument): void {
		$this->notifications->sweep();
		// After the sweep, so the states are persisted; on its own, so a refusing mail server
		// cannot silence the notifications.
		$this->mail->digest();
	}
}
