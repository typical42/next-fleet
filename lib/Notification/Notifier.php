<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns what the reminder job, a grant or a cancelled booking stored into words, in the recipient's
 * language, when the list is read (docs/architecture.md#reminder-engine). Each stores parameters
 * only, so a language changed later is the one shown.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10n,
		private IConfig $config,
		private IURLGenerator $urls,
		private VehicleMapper $vehicles,
		private VehicleAccess $access,
		private AccessMapper $grants,
		private IUserManager $users,
		private BookingMapper $bookings,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		// A product name, the same in every language.
		return 'NextFleet';
	}

	/**
	 * @throws UnknownNotificationException if it is not a reminder, a grant or a booking of this app
	 * @throws AlreadyProcessedException if its vehicle, its grant or its cancel is gone
	 * @throws \OCP\DB\Exception
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$user = $notification->getUser();
		$locale = $this->config->getUserValue($user, 'core', 'locale', '');
		$l = $this->l10n->get(Application::APP_ID, $languageCode, $locale === '' ? null : $locale);
		/** @var array{vehicle: string, plate: string, template_key: ?string, title: ?string, due_date: ?string, due_odo: ?int} $p */
		$p = $notification->getSubjectParameters();
		try {
			$vehicle = $this->vehicles->findByUuid($p['vehicle']);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}

		$subject = match ($notification->getObjectType()) {
			GrantNotices::OBJECT => $this->granted($l, $vehicle, $notification->getObjectId()),
			BookingNotices::OBJECT => $this->cancelled($l, $vehicle, $notification->getObjectId(), (string)($notification->getSubjectParameters()['by'] ?? '')),
			default => $this->subject($l, $notification->getSubject(), $p),
		};
		$notification->setParsedSubject($subject)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		// Being on the list grants nothing: the plate and title are told, the vehicle opens only
		// for someone who may see it.
		if ($this->access->may($user, VehicleAccess::VIEW, $vehicle)) {
			$notification->setLink($this->urls->linkToRouteAbsolute('nextfleet.page.index', ['vehicle' => $vehicle->getUuid()]));
		}

		return $notification;
	}

	/**
	 * @param array{vehicle: string, plate: string, template_key: ?string, title: ?string, due_date: ?string, due_odo: ?int} $p
	 * @throws UnknownNotificationException for a point this app does not send
	 */
	private function subject(IL10N $l, string $point, array $p): string {
		try {
			$line = ReminderWords::line($l, $point, $p);
		} catch (\UnexpectedValueException) {
			throw new UnknownNotificationException();
		}

		return $l->t('%1$s: %2$s', [$p['plate'], $line]);
	}

	/**
	 * Who gave which vehicle as what, as they stand now: a role changed since is the one named.
	 * One sentence per role, since a language may bend the rest around it.
	 *
	 * @throws AlreadyProcessedException if the grant is gone
	 * @throws \OCP\DB\Exception
	 */
	private function granted(IL10N $l, Vehicle $vehicle, string $grantUuid): string {
		try {
			$grant = $this->grants->findOnVehicle((int)$vehicle->getId(), $grantUuid);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
		$owner = $this->users->getDisplayName($vehicle->getUserId()) ?? $vehicle->getUserId();
		$name = VehicleWords::name($l, $vehicle);

		return match ($grant->getRole()) {
			'manager' => $l->t('%1$s gave you access to %2$s as a manager', [$owner, $name]),
			'driver' => $l->t('%1$s gave you access to %2$s as a driver', [$owner, $name]),
			default => $l->t('%1$s gave you access to %2$s as a viewer', [$owner, $name]),
		};
	}

	/**
	 * Who cancelled which booking, by its start where the booker planned it. Shown only while the
	 * booking stays cancelled: one brought back is no longer news.
	 *
	 * @throws AlreadyProcessedException if the booking is gone or no longer cancelled
	 * @throws \OCP\DB\Exception
	 */
	private function cancelled(IL10N $l, Vehicle $vehicle, string $bookingUuid, string $by): string {
		try {
			$booking = $this->bookings->findOnVehicle((int)$vehicle->getId(), $bookingUuid);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
		if ($booking->getState() !== Booking::CANCELLED) {
			throw new AlreadyProcessedException();
		}
		$start = \DateTime::createFromImmutable(Jurisdictions::localTime($booking->getStartsAt(), $booking->getStartsAtOff()));

		// The notice keeps the uid it was sent with, and erasure cannot reach it (docs/legal.md);
		// a gone account is at least not shown by it.
		return $l->t('%1$s cancelled your booking of %2$s on %3$s at %4$s', [
			// TRANSLATORS: who cancelled a booking, once their account is deleted; opens the sentence above as its %1$s
			$this->users->getDisplayName($by) ?? $l->t('A former user'),
			VehicleWords::name($l, $vehicle),
			(string)$l->l('date', $start, ['width' => 'medium']),
			(string)$l->l('time', $start, ['width' => 'short']),
		]);
	}
}
