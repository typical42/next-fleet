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
use OCA\NextFleet\Notification\ReminderWords;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * The mail channel of the reminder engine (docs/architecture.md#reminder-engine, rule 3): one
 * digest per recipient per day, covering every vehicle whose cadence falls that day and has news.
 */
class MailService {
	use TTransactional;

	/** The recipient's local hour from which the day's digest goes. */
	public const HOUR = 7;

	public function __construct(
		private VehicleMapper $vehicles,
		private ReminderMapper $reminders,
		private ReminderRecipientMapper $recipients,
		private ReminderReceiptMapper $receipts,
		private OdoReadingMapper $readings,
		private VehicleAccess $access,
		private IMailer $mailer,
		private IUserManager $users,
		private IFactory $l10n,
		private IConfig $config,
		private IURLGenerator $urls,
		private ITimeFactory $time,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Mails every recipient whose day it is. A recipient that fails is logged and the round goes
	 * on; their receipts rolled back, so the news waits for the next mail.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function digest(): void {
		/** @var array<string, list<Vehicle>> $lists */
		$lists = [];
		foreach ($this->vehicles->findInService() as $vehicle) {
			if ($vehicle->getReminderMail() === Vehicle::MAIL_OFF || $vehicle->getLifecycle() !== Vehicle::ACTIVE) {
				continue;
			}
			foreach ($this->recipients->findByVehicle((int)$vehicle->getId()) as $recipient) {
				$lists[$recipient->getUserId()][] = $vehicle;
			}
		}

		foreach ($lists as $userId => $vehicles) {
			try {
				$this->digestFor((string)$userId, $vehicles);
			} catch (\Exception $e) {
				$this->logger->error('Reminder digest to ' . $userId . ' was not sent', ['exception' => $e]);
			}
		}
	}

	/**
	 * @param list<Vehicle> $vehicles
	 * @throws \Exception
	 */
	private function digestFor(string $userId, array $vehicles): void {
		$user = $this->users->get($userId);
		$address = $user?->getEMailAddress();
		if ($user === null || !$user->isEnabled() || $address === null || $address === '') {
			return;
		}
		$now = $this->time->now();
		$local = $now->setTimezone($this->zone($userId));
		if ((int)$local->format('G') < self::HOUR) {
			return;
		}
		if (($this->receipts->lastSent($userId, ReminderReceipt::MAIL, $now->getTimestamp()) ?? PHP_INT_MIN) >= $local->setTime(0, 0)->getTimestamp()) {
			return;
		}
		$vehicles = array_values(array_filter($vehicles, static fn (Vehicle $vehicle): bool => self::falls($vehicle->getReminderMail(), $local)));
		if ($vehicles === []) {
			return;
		}

		// Sent inside the transaction: a refused mail throws, and its receipts roll back with it.
		// The vehicles are held in id order, as findInService() gives them, so two runs cannot
		// hold them crosswise.
		$this->atomic(function () use ($user, $address, $vehicles): void {
			$news = [];
			foreach ($vehicles as $vehicle) {
				$points = $this->news($vehicle, $user->getUID());
				if ($points !== []) {
					$news[] = [$vehicle, $points];
				}
			}
			if ($news !== []) {
				$this->send($user, $address, $news);
			}
		}, $this->db);
	}

	/** Weekly on Monday, monthly on the 1st, in the recipient's own day. */
	private static function falls(string $cadence, \DateTimeImmutable $local): bool {
		return match ($cadence) {
			Vehicle::MAIL_DAILY => true,
			Vehicle::MAIL_WEEKLY => $local->format('N') === '1',
			Vehicle::MAIL_MONTHLY => $local->format('j') === '1',
			default => false,
		};
	}

	/** The recipient's own zone, else the server's. */
	private function zone(string $userId): \DateTimeZone {
		$zone = $this->config->getUserValue($userId, 'core', 'timezone', '');
		if ($zone === '') {
			$zone = $this->config->getSystemValueString('default_timezone', 'UTC');
		}
		try {
			return new \DateTimeZone($zone);
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * The points of one vehicle this user has not been mailed yet, each claimed as it is found.
	 * The state is the job's, at the server's day, as the notification's is.
	 *
	 * @return list<array{Reminder, string}>
	 * @throws \OCP\DB\Exception
	 */
	private function news(Vehicle $vehicle, string $userId): array {
		$vehicleId = (int)$vehicle->getId();
		$this->vehicles->hold($vehicleId);
		try {
			$vehicle = $this->vehicles->findByUuid($vehicle->getUuid());
		} catch (DoesNotExistException) {
			return [];
		}
		if ($vehicle->getLifecycle() !== Vehicle::ACTIVE) {
			return [];
		}
		$now = $this->time->now();
		$odo = $this->readings->findNewestAtOrBefore($vehicleId, OdoReading::MAIN, $now->getTimestamp())?->getValue();

		$points = [];
		foreach ($this->reminders->findByVehicle($vehicleId) as $reminder) {
			$point = ReminderEngine::evaluate($reminder, $now->format('Y-m-d'), $odo)['point'];
			if ($point !== null && $this->receipts->claim($reminder, $point, ReminderReceipt::MAIL, $userId, $now->getTimestamp())) {
				$points[] = [$reminder, $point];
			}
		}

		return $points;
	}

	/**
	 * In the recipient's language and locale, one block per vehicle. The vehicle links only for
	 * someone who may see it, as the notification does.
	 *
	 * @param non-empty-list<array{Vehicle, list<array{Reminder, string}>}> $news
	 * @throws \RuntimeException when the mail server refused it
	 */
	private function send(IUser $user, string $address, array $news): void {
		$language = $this->l10n->getUserLanguage($user);
		$locale = $this->config->getUserValue($user->getUID(), 'core', 'locale', '');
		$l = $this->l10n->get(Application::APP_ID, $language, $locale === '' ? null : $locale);

		$template = $this->mailer->createEMailTemplate('nextfleet.reminderDigest');
		$template->setSubject($l->t('Reminders for your vehicles'));
		$template->addHeader();
		$template->addHeading($l->t('Reminders for your vehicles'));
		foreach ($news as [$vehicle, $points]) {
			$plate = $vehicle->getPlate() ?? '';
			if ($this->access->may($user->getUID(), VehicleAccess::VIEW, $vehicle)) {
				$url = $this->urls->linkToRouteAbsolute('nextfleet.page.index', ['vehicle' => $vehicle->getUuid()]);
				$template->addBodyText('<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($plate) . '</a>', $plate . ' – ' . $url);
			} else {
				$template->addBodyText($plate);
			}
			foreach ($points as [$reminder, $point]) {
				$template->addBodyListItem(ReminderWords::line($l, $point, [
					'template_key' => $reminder->getTemplateKey(),
					'title' => $reminder->getTitle(),
					'due_date' => $reminder->getDueDate()?->format('Y-m-d'),
					'due_odo' => $reminder->getDueOdo(),
				]));
			}
		}
		$template->addFooter('', $language);

		$message = $this->mailer->createMessage();
		$message->setTo([$address => $user->getDisplayName()]);
		$message->useTemplate($template);
		// Nextcloud's mailer answers a refusal with the failed addresses rather than throwing.
		if ($this->mailer->send($message) !== []) {
			throw new \RuntimeException('The mail server refused the message');
		}
	}
}
