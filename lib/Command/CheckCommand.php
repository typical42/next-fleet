<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Service\CheckService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds what no foreign key or constraint stops, without changing it (CheckService).
 */
class CheckCommand extends Command {
	public function __construct(
		private CheckService $checks,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:check')
			->setDescription('Find rows that break a rule of the data model; changes nothing, exits 1 on a finding')
			->addOption('vehicle', null, InputOption::VALUE_REQUIRED, 'only this vehicle\'s rows, by uuid; skips the checks that are about the whole instance');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}
		$uuid = $input->getOption('vehicle');
		try {
			$only = $uuid === null ? null : $this->checks->vehicle((string)$uuid);
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has the uuid ' . (string)$uuid);

			return self::INVALID;
		}

		$found = $this->checks->run($only);
		if ($format->isJson()) {
			$format->json($output, $found);
		} else {
			$format->rows($output, $found['findings'], 'No findings.');
			foreach ($found['warnings'] as $warning) {
				$output->writeln('<comment>Warning: ' . Format::safe($warning['reason']) . '</comment>');
			}
		}

		return $found['findings'] === [] ? self::SUCCESS : self::FAILURE;
	}
}
