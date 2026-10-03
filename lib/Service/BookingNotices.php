<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;

/**
 * The notice a booker gets when somebody else cancels their booking, sent and taken back. Apart
 * from BookingService for the reason GrantNotices gives: deleting a vehicle takes its notices back.
 */
class BookingNotices {
	/** The notification's object: the booking, so whatever undoes the cancel finds what it sent. */
	public const OBJECT = 'booking';

	public function __construct(
		private BookingMapper $bookings,
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Tells the booker who cancelled. Parameters only: the notifier words it when the list is read
	 * (lib/Notification/Notifier.php).
	 */
	public function tellCancelled(Vehicle $vehicle, Booking $booking, string $by): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($booking->getUserId())
			->setDateTime(\DateTime::createFromImmutable($this->time->now()))
			->setObject(self::OBJECT, $booking->getUuid())
			->setSubject(self::OBJECT, ['vehicle' => $vehicle->getUuid(), 'by' => $by]);
		$this->notifications->notify($notification);
	}

	/**
	 * Every cancelled booking's notice on one vehicle, read or not.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function withdrawAll(Vehicle $vehicle): void {
		foreach ($this->bookings->findSpanning((int)$vehicle->getId(), 0, null) as $booking) {
			if ($booking->getState() === Booking::CANCELLED) {
				$notification = $this->notifications->createNotification();
				$notification->setApp(Application::APP_ID)->setObject(self::OBJECT, $booking->getUuid());
				$this->notifications->markProcessed($notification);
			}
		}
	}
}
