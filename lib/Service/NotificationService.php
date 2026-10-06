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
use OCP\IUserManager;
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
		private IUserManager $users,
	) {
	}

	/**
	 * Evaluates every reminder that can still ring, of every vehicle in service, at now
	 * (VehicleMapper::findReminded()), persists the state, and tells each recipient the newest
	 * point reached, once per point and occurrence. One vehicle at a time, each held, so the sheet
	 * and a second run wait rather than race. A vehicle or a send that fails is logged, its
	 * receipts rolled back or forgotten so the next run retries, and the round goes on. Nothing
	 * leaves the sweep, errors included: the digest comes after it (ReminderJob).
	 */
	public function sweep(): void {
		try {
			$round = $this->vehicles->findReminded(false);
		} catch (\Throwable $e) {
			$this->logger->error('Reminders were not evaluated', ['exception' => $e]);

			return;
		}
		foreach ($round as [$vehicle]) {
			try {
				// Sent after the commit: a notification out before a rollback would go out again.
				$sends = $this->atomic(fn (): array => $this->sweepVehicle($vehicle), $this->db);
			} catch (\Throwable $e) {
				$this->logger->error('Reminders of vehicle ' . $vehicle->getUuid() . ' were not evaluated', ['exception' => $e]);
				continue;
			}
			foreach ($sends as $send) {
				try {
					$send();
				} catch (\Throwable $e) {
					$this->logger->error('A reminder of vehicle ' . $vehicle->getUuid() . ' was not sent', ['exception' => $e]);
				}
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
	 * Lets the points an edit moved (ReminderEngine::moved()) ring again where they now fall
	 * (docs/architecture.md#reminder-engine). For the caller holding the vehicle; the withdrawals
	 * are the caller's to make after the commit.
	 *
	 * @return list<string> the users whose standing notice is for a moved point
	 * @throws \OCP\DB\Exception
	 */
	public function rearm(Reminder $was, Reminder $is): array {
		$moved = ReminderEngine::moved($was, $is);
		if ($moved === []) {
			return [];
		}
		$receipts = $this->receipts->findByOccurrence($is);
		// The sweep sends the newest point only, so that is the one a user's notice shows.
		$newest = [];
		foreach ($receipts as $receipt) {
			if ($receipt->getChannel() === ReminderReceipt::APP) {
				$newest[$receipt->getUserId()] = $receipt->getPoint();
			}
		}
		// A numeric uid comes back from the keys as an int.
		$told = array_map(strval(...), array_keys(array_filter($newest, static fn (string $point): bool => in_array($point, $moved, true))));

		$forget = [];
		$retire = [];
		foreach ($receipts as $receipt) {
			$point = $receipt->getPoint();
			if ($receipt->getChannel() === ReminderReceipt::APP) {
				// A withdrawn notice is told again from where the reminder now stands, even when that
				// is a point it reached before: unticking the day leaves "due on" standing.
				if (in_array($point, $moved, true) || in_array($receipt->getUserId(), $told, true)) {
					$forget[] = $receipt;
				}
			} elseif (in_array($point, $moved, true)) {
				$retire[] = $receipt;
			} elseif (str_starts_with($point, ReminderReceipt::MOVED) && in_array(substr($point, strlen(ReminderReceipt::MOVED)), $moved, true)) {
				// Its point is about to be retired again, by a later mail.
				$forget[] = $receipt;
			}
		}
		$this->receipts->forget($forget);
		foreach ($retire as $receipt) {
			$this->receipts->retire($receipt);
		}

		return $told;
	}

	/**
	 * Takes back what one vehicle's reminders sent one user: they are off its list. Their receipts
	 * stay, so being added again tells them the next point, not the one they were already told.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function withdrawFrom(int $vehicleId, string $userId): void {
		foreach ($this->reminders->findByVehicle($vehicleId) as $reminder) {
			$this->withdraw($reminder, $userId);
		}
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
		/** @var array<string, bool> $exists by uid, asked once a receipt is due */
		$exists = [];

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
				// An account deleted before its erasure held the vehicle is still listed, and a
				// receipt would name the uid for whoever takes it next.
				if (!($exists[$userId] ??= $this->users->userExists($userId))) {
					continue;
				}
				// A point already sent stays up, even as the state moves on: an unticked due date
				// leaves "due on" standing rather than nothing.
				$receipt = $this->receipts->claim($reminder, $point, ReminderReceipt::APP, $userId, $now->getTimestamp());
				if ($receipt !== null) {
					$sends[] = function () use ($vehicle, $reminder, $point, $userId, $receipt): void {
						try {
							$this->withdraw($reminder, $userId);
							$this->notify($vehicle, $reminder, $point, $userId);
						} catch (\Throwable $e) {
							$this->receipts->forget([$receipt]);
							throw $e;
						}
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
				// The notifier drops a notice whose reminder has moved on since (Notifier::reminded()).
				'occurrence' => $reminder->getOccurrence(),
			]);
		$this->notifications->notify($notification);
	}
}
