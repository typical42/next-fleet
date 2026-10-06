<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\LookalikeNotices;
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
 *
 * @psalm-type ReminderNotice = array{vehicle: string, plate: string, template_key: ?string, title: ?string, due_date: ?string, due_odo: ?int, occurrence?: int}
 */
class Notifier implements INotifier {
	/**
	 * What prepare() read, for the next notice: Nextcloud prepares a list of many notices of one
	 * vehicle one at a time. Kept for the notifier's life - a request, or one cron run, in which a
	 * notice prepared after a change made in that same run reads it as it was.
	 *
	 * @var array<string, ?Vehicle> by uuid, null for none
	 */
	private array $found = [];
	/** @var array<string, bool> by uid and vehicle id */
	private array $sees = [];
	/** @var array<int, list<string>> the uids on each vehicle's list, by vehicle id */
	private array $listed = [];
	/** @var array<int, array<int, Reminder>> each vehicle's live reminders by id, by vehicle id */
	private array $reminded = [];
	/** @var array<string, ?Access> by uuid, null for none */
	private array $granted = [];

	public function __construct(
		private IFactory $l10n,
		private IConfig $config,
		private IURLGenerator $urls,
		private VehicleMapper $vehicles,
		private VehicleAccess $access,
		private AccessMapper $grants,
		private IUserManager $users,
		private BookingMapper $bookings,
		private ReminderRecipientMapper $recipients,
		private ReminderMapper $reminders,
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
	 * @throws UnknownNotificationException if it is not a reminder, a grant, a booking or a lookalike of this app
	 * @throws AlreadyProcessedException if its vehicle, its grant, its cancel or its account is gone, its reminder has moved on from where it rang, or the reader is off the list
	 * @throws \OCP\DB\Exception
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$user = $notification->getUser();
		$locale = $this->config->getUserValue($user, 'core', 'locale', '');
		$l = $this->l10n->get(Application::APP_ID, $languageCode, $locale === '' ? null : $locale);
		$icon = $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
		// The one notice about an account rather than a vehicle (LookalikeNotices).
		if ($notification->getObjectType() === LookalikeNotices::OBJECT) {
			return $notification->setParsedSubject($this->lookalike($l, $notification->getObjectId()))->setIcon($icon);
		}
		/** @var ReminderNotice $p */
		$p = $notification->getSubjectParameters();
		if (!array_key_exists($p['vehicle'], $this->found)) {
			try {
				$this->found[$p['vehicle']] = $this->vehicles->findByUuid($p['vehicle']);
			} catch (DoesNotExistException) {
				$this->found[$p['vehicle']] = null;
			}
		}
		$vehicle = $this->found[$p['vehicle']] ?? throw new AlreadyProcessedException();

		$subject = match ($notification->getObjectType()) {
			GrantNotices::OBJECT => $this->granted($l, $user, $vehicle, $notification->getObjectId()),
			BookingNotices::OBJECT => $this->cancelled($l, $user, $vehicle, $notification->getObjectId()),
			default => $this->reminded($l, $user, $vehicle, (int)$notification->getObjectId(), $notification->getSubject(), $p),
		};
		$notification->setParsedSubject($subject)->setIcon($icon);
		// Being on the list grants nothing: the plate and title are told, the vehicle opens only
		// for someone who may see it.
		if ($this->sees($user, $vehicle)) {
			$notification->setLink($this->urls->linkToRouteAbsolute('nextfleet.page.index', ['vehicle' => $vehicle->getUuid()]));
		}

		return $notification;
	}

	/**
	 * Shown only while the reminder stands where it rang - same occurrence, same due date and km,
	 * still open - and the reader is on the vehicle's list. This catches a notice the sweep sent
	 * after the change that withdrew it (docs/architecture.md#reminder-engine).
	 *
	 * @param ReminderNotice $p
	 * @throws UnknownNotificationException for a point this app does not send
	 * @throws AlreadyProcessedException if the reminder is gone, over or moved, or the reader is off the list
	 * @throws \OCP\DB\Exception
	 */
	private function reminded(IL10N $l, string $user, Vehicle $vehicle, int $reminderId, string $point, array $p): string {
		$subject = $this->subject($l, $point, $p, $vehicle->getOdoUnit());
		$vehicleId = (int)$vehicle->getId();
		if (!isset($this->reminded[$vehicleId])) {
			$this->reminded[$vehicleId] = [];
			foreach ($this->reminders->findByVehicle($vehicleId) as $one) {
				$this->reminded[$vehicleId][(int)$one->getId()] = $one;
			}
		}
		$reminder = $this->reminded[$vehicleId][$reminderId] ?? null;
		if ($reminder === null || $reminder->getDeletedAt() !== null
			|| in_array($reminder->getState(), [Reminder::DONE, Reminder::DISMISSED, Reminder::SNOOZED], true)
			// A notice sent before 0.3.1 names no occurrence.
			|| (isset($p['occurrence']) && $p['occurrence'] !== $reminder->getOccurrence())
			|| $p['due_date'] !== $reminder->getDueDate()?->format('Y-m-d') || $p['due_odo'] !== $reminder->getDueOdo()) {
			throw new AlreadyProcessedException();
		}
		$this->listed[$vehicleId] ??= array_map(
			static fn (ReminderRecipient $recipient): string => $recipient->getUserId(),
			$this->recipients->findByVehicle($vehicleId),
		);
		if (in_array($user, $this->listed[$vehicleId], true)) {
			return $subject;
		}

		throw new AlreadyProcessedException();
	}

	/** @throws \OCP\DB\Exception */
	private function sees(string $user, Vehicle $vehicle): bool {
		return $this->sees[$user . "\0" . $vehicle->getId()] ??= $this->access->may($user, VehicleAccess::VIEW, $vehicle);
	}

	/**
	 * @param ReminderNotice $p
	 * @throws UnknownNotificationException for a point this app does not send
	 */
	private function subject(IL10N $l, string $point, array $p, string $unit): string {
		try {
			$line = ReminderWords::line($l, $point, $p, $unit);
		} catch (\UnexpectedValueException) {
			throw new UnknownNotificationException();
		}

		return $l->t('%1$s: %2$s', [$p['plate'], $line]);
	}

	/**
	 * An account the upgrade kept under an old pseudonym, named so the admin can look at it.
	 *
	 * @throws AlreadyProcessedException once the account is deleted: its rows are erased then
	 */
	private function lookalike(IL10N $l, string $account): string {
		if (!$this->users->userExists($account)) {
			throw new AlreadyProcessedException();
		}

		return $l->t('The account %s is named like an erased driver. If it was made to take their rows, delete it, which erases them; otherwise nothing is wrong.', [$account]);
	}

	/**
	 * Who gave which vehicle as what, as they stand now: a role changed since is the one named.
	 * One sentence per role, since a language may bend the rest around it.
	 *
	 * @throws AlreadyProcessedException if the grant is gone, or no longer reaches the reader
	 * @throws \OCP\DB\Exception
	 */
	private function granted(IL10N $l, string $user, Vehicle $vehicle, string $grantUuid): string {
		if (!array_key_exists($grantUuid, $this->granted)) {
			try {
				$this->granted[$grantUuid] = $this->grants->findOnVehicle((int)$vehicle->getId(), $grantUuid);
			} catch (DoesNotExistException) {
				$this->granted[$grantUuid] = null;
			}
		}
		$grant = $this->granted[$grantUuid] ?? throw new AlreadyProcessedException();
		// A group's grant stays when a member leaves the group, and their notice with it.
		if (!$this->sees($user, $vehicle)) {
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
	 * Which booking was cancelled, by its start where the booker planned it, and not by whom: a
	 * name in a notice is one an erasure cannot take back. A notice stored before 0.3.0 still
	 * carries `by`, and is not read for it. Shown only while the booking stays cancelled: one
	 * brought back is no longer news, and only while the booker may see the vehicle: the notice
	 * names it as it stands now, so a kept one would tell a former driver every later plate.
	 *
	 * @throws AlreadyProcessedException if the booking is gone or no longer cancelled, or the reader lost the vehicle
	 * @throws \OCP\DB\Exception
	 */
	private function cancelled(IL10N $l, string $user, Vehicle $vehicle, string $bookingUuid): string {
		if (!$this->sees($user, $vehicle)) {
			throw new AlreadyProcessedException();
		}
		try {
			$booking = $this->bookings->findOnVehicle((int)$vehicle->getId(), $bookingUuid);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
		if ($booking->getState() !== Booking::CANCELLED) {
			throw new AlreadyProcessedException();
		}
		$start = \DateTime::createFromImmutable(Jurisdictions::localTime($booking->getStartsAt(), $booking->getStartsAtOff()));

		return $l->t('Your booking of %1$s on %2$s at %3$s was cancelled', [
			VehicleWords::name($l, $vehicle),
			(string)$l->l('date', $start, ['width' => 'medium']),
			(string)$l->l('time', $start, ['width' => 'short']),
		]);
	}
}
