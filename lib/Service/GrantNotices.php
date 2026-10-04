<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\GrantNoticeJob;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The notice a grantee gets of a grant, sent and taken back. Apart from GrantService because
 * deleting a vehicle takes its notices back too, and VehicleService is what GrantService builds on.
 */
class GrantNotices {
	/** The notification's object: the grant, so revoking it finds what it sent. */
	public const OBJECT = 'grant';

	public function __construct(
		private AccessMapper $grants,
		private IGroupManager $groups,
		private IManager $notifications,
		private ITimeFactory $time,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Tells the grantee now, or queues telling a group's members (GrantNoticeJob): a group of
	 * thousands would hold the owner's request up.
	 */
	public function tell(Vehicle $vehicle, Access $grant): void {
		if ($grant->getGranteeType() === Access::GROUP) {
			$this->jobs->add(GrantNoticeJob::class, ['vehicle' => $vehicle->getUuid(), 'grant' => $grant->getUuid()]);
		} else {
			$this->send($vehicle, $grant, [$grant->getGrantee()]);
		}
	}

	/** Tells each member the group has now, for GrantNoticeJob. */
	public function tellMembers(Vehicle $vehicle, Access $grant): void {
		$this->send($vehicle, $grant, array_map(static fn (IUser $user): string => $user->getUID(), $this->groups->get($grant->getGrantee())?->getUsers() ?? []));
	}

	/**
	 * Never to the owner. Parameters only: the notifier names owner, vehicle and role when the
	 * list is read (lib/Notification/Notifier.php).
	 *
	 * @param list<string> $told
	 */
	private function send(Vehicle $vehicle, Access $grant, array $told): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, $grant->getUuid())
			->setSubject(self::OBJECT, ['vehicle' => $vehicle->getUuid()]);
		foreach (array_diff($told, [$vehicle->getUserId()]) as $uid) {
			// One member a notifier cannot reach leaves the rest told: the job is not run again.
			try {
				$this->notifications->notify($notification->setUser($uid));
			} catch (\Throwable $e) {
				$this->logger->error('A grant notice was not sent', ['app' => Application::APP_ID, 'user' => $uid, 'exception' => $e]);
			}
		}
	}

	/** Read or not, a grant taken back is no news any more. */
	public function withdraw(string $grantUuid): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)->setObject(self::OBJECT, $grantUuid);
		$this->notifications->markProcessed($notification);
	}

	/**
	 * Every live grant's notice on one vehicle. A revoked grant's went with the revoke.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function withdrawAll(Vehicle $vehicle): void {
		foreach ($this->grants->findByVehicle((int)$vehicle->getId()) as $grant) {
			$this->withdraw($grant->getUuid());
		}
	}
}
