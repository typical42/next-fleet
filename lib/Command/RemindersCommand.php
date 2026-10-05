<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Notification\ReminderWords;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\ReminderService;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A user's due reminders as their dashboard reads them (ReminderService::due()), and ReminderJob's
 * run on demand.
 */
class RemindersCommand extends Command {
	public function __construct(
		private ReminderService $reminders,
		private NotificationService $notifications,
		private MailService $mail,
		private IFactory $l10n,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:reminders')
			->setDescription('List the reminders due for a user, or run the hourly reminder job now')
			// Optional: --send covers every user, so it needs no uid.
			->addArgument('uid', InputArgument::OPTIONAL, 'the user whose reminders to list')
			->addOption('send', null, InputOption::VALUE_NONE, 'run the hourly reminder job now, for every user: notify and send the mail digests. '
				. 'The digest keeps its once-a-day mark, so this mails nobody a second time that day, unless the hourly job runs at the same moment. '
				. 'A recipient it fails for is logged, not printed');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}
		$uid = $input->getArgument('uid');
		$send = $input->getOption('send') === true;
		if ($uid === null && !$send) {
			Format::error($output, 'Name a uid, or pass --send.');

			return self::INVALID;
		}
		$userId = (string)$uid;
		if ($uid !== null && !$this->users->userExists($userId)) {
			Format::error($output, 'No such user: ' . $userId);

			return self::INVALID;
		}

		$failed = false;
		if ($send) {
			// In ReminderJob's order and with its stop: the digest reads the states the sweep persists.
			foreach (['notifications' => $this->notifications->sweep(...), 'digest' => $this->mail->digest(...)] as $step => $run) {
				try {
					$run();
				} catch (\Throwable $e) {
					Format::error($output, $step . ': ' . $e->getMessage());
					$failed = true;
					break;
				}
			}
		}

		if ($uid !== null) {
			// English, as the rest of this command speaks.
			$l = $this->l10n->get(Application::APP_ID, 'en');
			$rows = [];
			foreach ($this->reminders->due($userId) as ['vehicle' => $vehicle, 'reminder' => $reminder]) {
				$rows[] = [
					'vehicle' => $vehicle->getUuid(),
					'plate' => $vehicle->getPlate(),
					'reminder' => $reminder['uuid'],
					'title' => ReminderWords::title($l, $reminder),
					'due_date' => $reminder['due_date'],
					'due_odo' => $reminder['due_odo'],
					'estimate' => $reminder['estimate'],
					'state' => $reminder['state'],
					'snoozed_until' => $reminder['snoozed_until'],
				];
			}
			$format->rows($output, $rows, 'No reminders are due.');
		} elseif ($format->isJson()) {
			// --output promises one document on stdout, so a script piping to jq reads one.
			$format->json($output, ['sent' => !$failed]);
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
