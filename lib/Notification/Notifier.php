<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Notification;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns what the reminder job stored into words, in the recipient's language, when the list is
 * read (docs/architecture.md#reminder-engine). The job stores parameters only, so a language
 * changed later is the one shown.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10n,
		private IConfig $config,
		private IURLGenerator $urls,
		private VehicleMapper $vehicles,
		private VehicleAccess $access,
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
	 * @throws UnknownNotificationException if it is not a reminder of this app
	 * @throws AlreadyProcessedException if its vehicle is gone
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

		$notification->setParsedSubject($this->subject($l, $notification->getSubject(), $p))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app.svg')));

		try {
			$vehicle = $this->vehicles->findByUuid($p['vehicle']);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
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
}
