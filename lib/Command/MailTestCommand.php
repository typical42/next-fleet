<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Service\MailService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One test mail to where a user's digest goes (MailService::test()). Not a forced digest: that
 * would spend the day's digest mark.
 */
class MailTestCommand extends Command {
	public function __construct(
		private MailService $mail,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:mail-test')
			->setDescription('Send a test mail to the address a user\'s reminder digest goes to')
			->addArgument('uid', InputArgument::REQUIRED, 'the user to mail');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		if (!$this->users->userExists($uid)) {
			Format::error($output, 'No such user: ' . $uid);

			return self::INVALID;
		}
		try {
			$address = $this->mail->test($uid);
		} catch (\Throwable $e) {
			Format::error($output, $e->getMessage());

			return self::FAILURE;
		}
		$output->writeln('Sent a test mail to ' . $address . '.');

		return self::SUCCESS;
	}
}
