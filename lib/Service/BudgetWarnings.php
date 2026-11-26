<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Budget;
use OCA\NextFleet\Db\BudgetMapper;
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
 * The budget's warning (docs/architecture.md#reminder-engine): in the reminder round, the owner
 * and the managers hear when the year's costs reach 80 % and 100 % of the budget, once per
 * budget and share. The share told last lives on the budget (`warned`); an edit that leaves the
 * costs below it lowers it (rearm()), so that share is told again once reached again.
 */
class BudgetWarnings {
	use TTransactional;

	/** The notification's object: the budget, so an edit finds what it sent. */
	public const OBJECT = 'budget';

	/**
	 * The shares told, in percent, rising.
	 *
	 * @var list<int>
	 */
	public const SHARES = [80, 100];

	public function __construct(
		private BudgetMapper $budgets,
		private VehicleMapper $vehicles,
		private CostService $costs,
		private VehicleAccess $access,
		private UserZone $zones,
		private MailService $mail,
		private IManager $notifications,
		private IUserManager $users,
		private ITimeFactory $time,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The highest share of the amount the costs meet, 0 for none. In whole cents, so a share
	 * that falls between two cents is met only by the cent past it.
	 */
	public static function share(int $amount, int $spent): int {
		$reached = 0;
		foreach (self::SHARES as $share) {
			if ($spent * 100 >= $amount * $share) {
				$reached = $share;
			}
		}

		return $reached;
	}

	/**
	 * What the year cost as the budget counts it: energy, maintenance and expenses, net or gross as
	 * the budget says, over the calendar year in the owner's zone. The loss of value does not
	 * count. Every cost, whoever entered it: the reader's view plays no part.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function spent(Vehicle $vehicle, Budget $budget): int {
		$zone = $this->zones->of($vehicle->getUserId());
		$from = new \DateTimeImmutable(sprintf('%04d-01-01', $budget->getYear()), $zone);
		$to = new \DateTimeImmutable(sprintf('%04d-01-01', $budget->getYear() + 1), $zone);

		return $this->costs->total($vehicle, $from->getTimestamp(), $to->getTimestamp(), $budget->getNet());
	}

	/** The calendar year it is now in the owner's zone: the one whose budget the round checks. */
	public function currentYear(Vehicle $vehicle): int {
		return (int)$this->time->now()->setTimezone($this->zones->of($vehicle->getUserId()))->format('Y');
	}

	/**
	 * Lowers the share told to the one the edited budget reaches now, so each share above it is
	 * told again once reached. A cut lowers nothing: the costs are no further from it. For the
	 * caller that holds the vehicle and writes the budget after.
	 *
	 * @return bool whether it lowered the share, so a notice may be telling one no longer reached
	 * @throws \OCP\DB\Exception
	 */
	public function rearm(Vehicle $vehicle, Budget $budget): bool {
		$warned = $budget->getWarned();
		if ($warned === 0) {
			return false;
		}
		$share = min($warned, self::share($budget->getAmount(), $this->spent($vehicle, $budget)));
		if ($share === $warned) {
			return false;
		}
		$budget->setWarned($share);

		return true;
	}

	/**
	 * Puts the budget's notice where its share now stands, after an edit lowered it (rearm()): the
	 * notice of the share still reached in place of the one no longer, or none. No mail: the share
	 * was mailed when reached. After the commit, as a sweep's sends.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function retell(Vehicle $vehicle, Budget $budget): void {
		$share = $budget->getWarned();
		if ($share === 0) {
			$this->withdraw($budget);

			return;
		}
		foreach ($this->told($vehicle) as $userId) {
			$this->notify($vehicle, $budget, $share, $userId);
		}
	}

	/** Takes back the budget's notice, from everybody or from one user. */
	public function withdraw(Budget $budget, ?string $userId = null): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setObject(self::OBJECT, (string)$budget->getId());
		if ($userId !== null) {
			$notification->setUser($userId);
		}
		$this->notifications->markProcessed($notification);
	}

	/**
	 * Tells each budget of the current year with a share left to tell, one at a time under its
	 * vehicle's hold, as NotificationService::sweep() does the reminders. A budget or a send that
	 * fails is logged and the round goes on; a failed send is not tried again, since the others on
	 * the budget have been told.
	 */
	public function sweep(): void {
		// Every zone's current year is one of these.
		$year = (int)$this->time->now()->format('Y');
		try {
			$armed = $this->budgets->findArmed([$year - 1, $year, $year + 1], max(self::SHARES));
		} catch (\Throwable $e) {
			$this->logger->error('Budgets were not checked', ['exception' => $e]);

			return;
		}
		/** @var array<int, ?int> $years the owner's current year by vehicle id, null for none to check */
		$years = [];
		foreach ($armed as $budget) {
			try {
				// Asked before the hold, so a budget of another year takes no lock: most are.
				if (!array_key_exists($budget->getVehicleId(), $years)) {
					$years[$budget->getVehicleId()] = $this->yearOf($budget->getVehicleId());
				}
				if ($years[$budget->getVehicleId()] !== $budget->getYear()) {
					continue;
				}
				// Sent after the commit: a notice out before a rollback would go out again.
				$sends = $this->atomic(fn (): array => $this->sweepBudget($budget), $this->db);
			} catch (\Throwable $e) {
				$this->logger->error('Budget ' . $budget->getUuid() . ' was not checked', ['exception' => $e]);
				continue;
			}
			foreach ($sends as $send) {
				try {
					$send();
				} catch (\Throwable $e) {
					$this->logger->error('A warning of budget ' . $budget->getUuid() . ' was not sent', ['exception' => $e]);
				}
			}
		}
	}

	/**
	 * The current year in the owner's zone of a vehicle in the round, or null for one the round
	 * skips: deleted or disposed.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function yearOf(int $vehicleId): ?int {
		try {
			$vehicle = $this->vehicles->findAnyById($vehicleId);
		} catch (DoesNotExistException) {
			return null;
		}

		return $vehicle->getDeletedAt() !== null || $vehicle->getLifecycle() === Vehicle::DISPOSED ? null : $this->currentYear($vehicle);
	}

	/**
	 * Who hears a budget's warning: the owner and the managers, each an account that exists.
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	private function told(Vehicle $vehicle): array {
		return array_values(array_filter($this->access->holders($vehicle, VehicleAccess::EDIT), $this->users->userExists(...)));
	}

	/**
	 * @return list<\Closure(): void> what to send once the transaction has committed
	 * @throws \OCP\DB\Exception
	 */
	private function sweepBudget(Budget $budget): array {
		$vehicleId = $budget->getVehicleId();
		$this->vehicles->hold($vehicleId);
		// Read again under the hold: an edit, a delete or a disposal since the round began counts.
		try {
			$vehicle = $this->vehicles->findAnyById($vehicleId);
			$budget = $this->budgets->findOnVehicle($vehicleId, $budget->getUuid());
		} catch (DoesNotExistException) {
			return [];
		}
		if ($vehicle->getDeletedAt() !== null || $vehicle->getLifecycle() === Vehicle::DISPOSED) {
			return [];
		}
		if ($this->currentYear($vehicle) !== $budget->getYear()) {
			return [];
		}
		$share = self::share($budget->getAmount(), $this->spent($vehicle, $budget));
		if ($share <= $budget->getWarned()) {
			return [];
		}
		$this->budgets->warn($budget, $share);

		$sends = [];
		foreach ($this->told($vehicle) as $userId) {
			$sends[] = fn () => $this->notify($vehicle, $budget, $share, $userId);
			if ($vehicle->getReminderMail() !== Vehicle::MAIL_OFF) {
				$sends[] = fn () => $this->mail->budget($userId, $vehicle, $budget->getYear(), $share);
			}
		}

		return $sends;
	}

	/**
	 * In place of the share told before, so a budget has one notice. Parameters only: the notifier
	 * words it in the reader's language (lib/Notification/Notifier.php).
	 */
	private function notify(Vehicle $vehicle, Budget $budget, int $share, string $userId): void {
		$this->withdraw($budget, $userId);
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, (string)$budget->getId())
			->setSubject(self::OBJECT, [
				'vehicle' => $vehicle->getUuid(),
				'plate' => $vehicle->getPlate() ?? '',
				'year' => $budget->getYear(),
				'share' => $share,
			]);
		$this->notifications->notify($notification);
	}
}
