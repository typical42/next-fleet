<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Service\TransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hands a vehicle to another account before its owner's is deleted, which would close it
 * (docs/legal.md). The admin's tool, so no route offers it.
 */
class TransferCommand extends Command {
	public function __construct(
		private TransferService $transfers,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:transfer')
			->setDescription('Hand a vehicle to another account; its grants stay, the former owner keeps viewing it')
			->addArgument('vehicle', InputArgument::REQUIRED, 'the vehicle\'s uuid')
			->addArgument('owner', InputArgument::REQUIRED, 'the uid of the new owner');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$done = $this->transfers->transfer((string)$input->getArgument('vehicle'), (string)$input->getArgument('owner'));
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::FAILURE;
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has this uuid, or it is deleted');

			return self::FAILURE;
		}

		Format::line($output, 'The vehicle now belongs to ' . $done['vehicle']->getUserId() . ', no longer to ' . $done['from'] . '.');

		return self::SUCCESS;
	}
}
