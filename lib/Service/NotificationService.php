<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceipt;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The in-app channel of the reminder engine (docs/architecture.md#reminder-engine, rule 3): the
 * job's round, and taking back a notification its reminder has left behind.
 */
class NotificationService {
	use TTransactional;

	/** The notification's object: the reminder, so moving it on finds what it sent. */
	public const OBJECT = 'reminder';

	public function __construct(
		private VehicleMapper $vehicles,
		private ReminderMapper $reminders,
		private ReminderRecipientMapper $recipients,
		private ReminderReceiptMapper $receipts,
		private OdoReadingMapper $readings,
		private IManager $notifications,
		private ITimeFactory $time,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Evaluates every reminder of every vehicle in service at now, persists the state, and tells
	 * each recipient about the newest point reached, once per point and occurrence. One vehicle at
	 * a time, each held, so the sheet and a second run wait rather than race. A vehicle that fails
	 * is logged and the round goes on; its receipts rolled back with it, so the next run retries.
	 */
	public function sweep(): void {
		foreach ($this->vehicles->findInService() as $vehicle) {
			try {
				// Sent after the commit: a notification out before a rollback would go out again.
				foreach ($this->atomic(fn (): array => $this->sweepVehicle($vehicle), $this->db) as $send) {
					$send();
				}
			} catch (\Exception $e) {
				$this->logger->error('Reminders of vehicle ' . $vehicle->getUuid() . ' were not evaluated', ['exception' => $e]);
			}
		}
	}

	/**
	 * Takes back what the reminder has sent, to everybody or to one user: it moved on, or is done,
	 * snoozed, dismissed or deleted, and a notification left standing would still ring.
	 */
	public function withdraw(Reminder $reminder, ?string $userId = null): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setObject(self::OBJECT, (string)$reminder->getId());
		if ($userId !== null) {
			$notification->setUser($userId);
		}
		$this->notifications->markProcessed($notification);
	}

	/**
	 * @return list<\Closure(): void> what to send once the transaction has committed
	 * @throws \OCP\DB\Exception
	 */
	private function sweepVehicle(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		$this->vehicles->hold($vehicleId);
		// Read again under the hold: laid up, disposed or deleted since the round began is what
		// counts.
		try {
			$vehicle = $this->vehicles->findByUuid($vehicle->getUuid());
		} catch (DoesNotExistException) {
			return [];
		}
		if ($vehicle->getLifecycle() === Vehicle::DISPOSED) {
			return [];
		}
		$now = $this->time->now();
		$today = $now->format('Y-m-d');
		$odo = $this->readings->findNewestAtOrBefore($vehicleId, OdoReading::MAIN, $now->getTimestamp())?->getValue();
		// Laid up evaluates and sends nothing; back in service, the point it stands at is unreceipted
		// and so sends once.
		$recipients = $vehicle->getLifecycle() === Vehicle::LAID_UP ? [] : $this->recipients->findByVehicle($vehicleId);

		$sends = [];
		foreach ($this->reminders->findByVehicle($vehicleId) as $reminder) {
			['state' => $state, 'point' => $point] = ReminderEngine::evaluate($reminder, $today, $odo);
			$moved = $state !== $reminder->getState();
			if ($moved) {
				$reminder->setState($state);
				$this->reminders->updateChecked($reminder, $reminder->getUpdatedAt());
			}
			if ($point === null) {
				// Moved to where nothing is to be told - planned again after an edit, or snoozed - so
				// nothing may still be ringing.
				if ($moved) {
					$sends[] = fn () => $this->withdraw($reminder);
				}
				continue;
			}
			foreach ($recipients as $recipient) {
				$userId = $recipient->getUserId();
				// A point already sent stays up, even as the state moves on: an unticked due date
				// leaves "due on" standing rather than nothing.
				if ($this->receipts->claim($reminder, $point, ReminderReceipt::APP, $userId, $now->getTimestamp())) {
					$sends[] = function () use ($vehicle, $reminder, $point, $userId): void {
						$this->withdraw($reminder, $userId);
						$this->notify($vehicle, $reminder, $point, $userId);
					};
				}
			}
		}

		return $sends;
	}

	/**
	 * Parameters only: the notifier translates in the recipient's language when the list is read
	 * (lib/Notification/Notifier.php).
	 */
	private function notify(Vehicle $vehicle, Reminder $reminder, string $point, string $userId): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, (string)$reminder->getId())
			->setSubject($point, [
				'vehicle' => $vehicle->getUuid(),
				'plate' => $vehicle->getPlate() ?? '',
				'template_key' => $reminder->getTemplateKey(),
				'title' => $reminder->getTitle(),
				'due_date' => $reminder->getDueDate()?->format('Y-m-d'),
				'due_odo' => $reminder->getDueOdo(),
			]);
		$this->notifications->notify($notification);
	}
}
