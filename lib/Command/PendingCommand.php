<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\Pending;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What a failure left marked (Service\Pending), and PendingJob's run on demand.
 */
class PendingCommand extends Command {
	public function __construct(
		private Pending $pending,
		private ErasureService $erasure,
		private GrantService $grants,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:pending')
			->setDescription('List the erasures and group revokes a failure left unfinished')
			->addOption('finish', null, InputOption::VALUE_NONE, 'finish them now, as the hourly job does, then list what is still marked');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}

		// An erasure whose uid an account carries again is dropped, not run: the new account keeps
		// what the old one owned. The job only logs it, so the admin reads it here, before and after.
		$finish = $input->getOption('finish') === true;
		foreach ($this->pending->of(Pending::ERASURE) as ['id' => $uid]) {
			if ($this->users->userExists($uid)) {
				Format::error($output, 'erasure ' . $uid . ': an account of that uid exists again, so the erasure '
					. ($finish ? 'is dropped' : 'will be dropped') . ' and that account keeps the deleted one\'s vehicles and rows');
			}
		}

		$failed = false;
		if ($finish) {
			// Each on its own, as in PendingJob: one that throws does not hold the other up.
			foreach ([Pending::ERASURE => $this->erasure->finish(...), Pending::GROUP => $this->grants->finish(...)] as $kind => $run) {
				try {
					foreach ($run() as ['id' => $id, 'error' => $error]) {
						Format::error($output, $kind . ' ' . $id . ': ' . $error->getMessage());
						$failed = true;
					}
				} catch (\Throwable $e) {
					Format::error($output, $kind . ': ' . $e->getMessage());
					$failed = true;
				}
			}
		}

		$rows = [];
		foreach ([Pending::ERASURE, Pending::GROUP] as $kind) {
			foreach ($this->pending->of($kind) as ['id' => $id, 'since' => $since]) {
				$rows[] = ['kind' => $kind, 'id' => $id, 'marked_at' => Format::instant($since)];
			}
		}
		$format->rows($output, $rows, 'Nothing is pending.');

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
