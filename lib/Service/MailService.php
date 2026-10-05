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
use OCP\Mail\IEMailTemplate;
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

	/**
	 * The user value naming the local day a recipient's digest was last checked for, and the
	 * vehicles it covered (checkedMark()). Once a day is checked, news found later that day on those
	 * vehicles goes with the next mail: the job need not evaluate every vehicle of every recipient
	 * each hour to find a day with nothing new. A vehicle that joins the list was never checked,
	 * so it is that day.
	 */
	public const CHECKED = 'digest_checked';

	public function __construct(
		private VehicleMapper $vehicles,
		private ReminderMapper $reminders,
		private ReminderReceiptMapper $receipts,
		private OdoReadingMapper $readings,
		private VehicleAccess $access,
		private IMailer $mailer,
		private IUserManager $users,
		private IFactory $l10n,
		private IConfig $config,
		private UserZone $zones,
		private IURLGenerator $urls,
		private ITimeFactory $time,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Mails every recipient whose day it is. A recipient that fails, by exception or error, is
	 * logged and the round goes on; their receipts are forgotten, so the next run tries again.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function digest(): void {
		/** @var array<string, list<Vehicle>> $lists */
		$lists = [];
		foreach ($this->vehicles->findReminded() as [$vehicle, $recipients]) {
			if ($vehicle->getReminderMail() === Vehicle::MAIL_OFF || $vehicle->getLifecycle() !== Vehicle::ACTIVE) {
				continue;
			}
			foreach ($recipients as $userId) {
				$lists[$userId][] = $vehicle;
			}
		}
		// A numeric uid comes back from the keys as an int.
		$checked = $this->config->getUserValueForUsers(Application::APP_ID, self::CHECKED, array_map(strval(...), array_keys($lists)));

		foreach ($lists as $userId => $vehicles) {
			try {
				$this->digestFor((string)$userId, $vehicles, $checked[$userId] ?? null);
			} catch (\Throwable $e) {
				$this->logger->error('Reminder digest to ' . $userId . ' was not sent', ['exception' => $e]);
			}
		}
	}

	/**
	 * Mails the user one short test message at the address their digest goes to. It leaves the
	 * digest's day mark alone, so the day's digest still goes. It does not ask whether a digest
	 * is due them at all, which takes a place on a reminder list of a vehicle that mails.
	 *
	 * @return string the address
	 * @throws \DomainException for an account the digest skips (unknown, disabled, no address), saying why
	 * @throws \Exception when the mailer failed or the server refused the message
	 */
	public function test(string $userId): string {
		$user = $this->users->get($userId);
		if ($user === null) {
			throw new \DomainException('No such user: ' . $userId);
		}
		if (!$user->isEnabled()) {
			throw new \DomainException($userId . ' is disabled; the digest skips them');
		}
		$address = self::addressOf($user);
		if ($address === null) {
			throw new \DomainException($userId . ' has no email address; the digest skips them');
		}

		$template = $this->mailer->createEMailTemplate('nextfleet.testMail');
		$template->setSubject('NextFleet test mail');
		$template->addHeader();
		$template->addHeading('NextFleet test mail');
		$template->addBodyText('Your administrator sent this to check that NextFleet can mail you. NextFleet sends its reminder mails to this address.');
		$template->addFooter('', 'en');
		$this->deliver($user, $address, $template);

		return $address;
	}

	/** Where the digest goes: the account's address, or null for none. */
	private static function addressOf(IUser $user): ?string {
		$address = $user->getEMailAddress();

		return $address === null || $address === '' ? null : $address;
	}

	/**
	 * @param list<Vehicle> $vehicles
	 * @param ?string $checked the recipient's CHECKED value
	 * @throws \Exception
	 */
	private function digestFor(string $userId, array $vehicles, ?string $checked): void {
		$user = $this->users->get($userId);
		$address = $user === null ? null : self::addressOf($user);
		if ($user === null || !$user->isEnabled() || $address === null) {
			return;
		}
		$now = $this->time->now();
		$local = $now->setTimezone($this->zones->of($userId));
		$mark = self::checkedMark($local, $vehicles);
		if ((int)$local->format('G') < self::HOUR || $checked === $mark) {
			return;
		}
		$this->check($user, $address, $vehicles, $now, $local);
		// After the check went through: one that failed is tried again within the hour.
		$this->config->setUserValue($userId, Application::APP_ID, self::CHECKED, $mark);
	}

	/**
	 * The recipient's local day and a digest of the ids of the vehicles on their list.
	 *
	 * @param list<Vehicle> $vehicles
	 */
	private static function checkedMark(\DateTimeImmutable $local, array $vehicles): string {
		return $local->format('Y-m-d') . ' ' . hash('xxh3', implode(',', array_map(static fn (Vehicle $vehicle): int => (int)$vehicle->getId(), $vehicles)));
	}

	/**
	 * The day's digest for one recipient, if it is the day of any of their vehicles and they
	 * have not had one today.
	 *
	 * @param list<Vehicle> $vehicles
	 * @throws \Exception
	 */
	private function check(IUser $user, string $address, array $vehicles, \DateTimeImmutable $now, \DateTimeImmutable $local): void {
		$userId = $user->getUID();
		if (($this->receipts->lastSent($userId, ReminderReceipt::MAIL, $now->getTimestamp()) ?? PHP_INT_MIN) >= $local->setTime(0, 0)->getTimestamp()) {
			return;
		}
		$vehicles = array_values(array_filter($vehicles, static fn (Vehicle $vehicle): bool => self::falls($vehicle->getReminderMail(), $local)));
		if ($vehicles === []) {
			return;
		}

		// Claimed one vehicle at a time and sent after the commits: the fleet does not wait on the
		// mail server. What a failed mail claimed is forgotten, so its news goes with the next one.
		$news = [];
		$claimed = [];
		try {
			foreach ($vehicles as $vehicle) {
				$points = $this->atomic(fn (): array => $this->news($vehicle, $user->getUID()), $this->db);
				if ($points !== []) {
					$news[] = [$vehicle, $points];
					array_push($claimed, ...array_column($points, 2));
				}
			}
			if ($news !== []) {
				$this->send($user, $address, $news);
			}
		} catch (\Throwable $e) {
			$this->receipts->forget($claimed);
			throw $e;
		}
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

	/**
	 * The points of one vehicle this user has not been mailed yet, each claimed as it is found,
	 * with its receipt. The state is the job's, at the server's day, as the notification's is.
	 *
	 * @return list<array{Reminder, string, ReminderReceipt}>
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
			$receipt = $point === null ? null : $this->receipts->claim($reminder, $point, ReminderReceipt::MAIL, $userId, $now->getTimestamp());
			if ($receipt !== null) {
				$points[] = [$reminder, (string)$point, $receipt];
			}
		}

		return $points;
	}

	/**
	 * In the recipient's language and locale, one block per vehicle. The vehicle links only for
	 * someone who may see it, as the notification does.
	 *
	 * @param non-empty-list<array{Vehicle, list<array{Reminder, string, ReminderReceipt}>}> $news
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
		$this->deliver($user, $address, $template);
	}

	/** @throws \RuntimeException when the mail server refused it */
	private function deliver(IUser $user, string $address, IEMailTemplate $template): void {
		$message = $this->mailer->createMessage();
		$message->setTo([$address => $user->getDisplayName()]);
		$message->useTemplate($template);
		// Nextcloud's mailer answers a refusal with the failed addresses rather than throwing.
		if ($this->mailer->send($message) !== []) {
			throw new \RuntimeException('The mail server refused the message');
		}
	}
}
