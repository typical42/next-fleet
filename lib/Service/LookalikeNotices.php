<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;

/**
 * The notice the admins get of an account the upgrade kept under an old pseudonym
 * (lib/Repair/ErasedPseudonyms.php). The upgrade's output scrolls by, often in a web updater
 * nobody reads; a notice stays until the account is deleted (lib/Notification/Notifier.php).
 */
class LookalikeNotices {
	/** The notification's object: the kept account. */
	public const OBJECT = 'lookalike';

	public function __construct(
		private Names $names,
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Tells each member of `admin`. The step runs again on every `occ maintenance:repair`, so the
	 * notice before goes first: one per admin, however often.
	 */
	public function tell(string $account): void {
		$this->withdraw($account);
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, $account)
			->setSubject(self::OBJECT, ['account' => $account]);
		foreach ($this->names->members('admin') as $admin) {
			$this->notifications->notify($notification->setUser($admin));
		}
	}

	public function withdraw(string $account): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)->setObject(self::OBJECT, $account);
		$this->notifications->markProcessed($notification);
	}
}
