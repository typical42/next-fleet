<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The owner's undo of a vehicle delete, for an owner who has lost the toast. It goes through
 * VehicleService::restore() as that owner, so it refuses whatever the owner's undo would.
 */
class RestoreCommand extends Command {
	public function __construct(
		private VehicleMapper $mapper,
		private VehicleService $vehicles,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:restore')
			->setDescription('Take a deleted vehicle out of the trash, as its owner\'s undo does')
			->addArgument('vehicle', InputArgument::REQUIRED, 'the vehicle\'s uuid');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uuid = (string)$input->getArgument('vehicle');
		try {
			$vehicle = $this->mapper->findAnyByUuid($uuid);
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has the uuid ' . $uuid);

			return self::INVALID;
		}
		if ($vehicle->getDeletedAt() === null) {
			Format::error($output, 'The vehicle ' . $uuid . ' is not deleted');

			return self::FAILURE;
		}
		try {
			$restored = $this->vehicles->restore($vehicle->getUserId(), $uuid, $vehicle->getUpdatedAt());
		} catch (AccessDeniedException) {
			// An erased owner's: the erasure closed it, and nobody is left who may decide over it.
			Format::error($output, 'Its owner ' . $vehicle->getUserId() . ' may not restore it');

			return self::FAILURE;
		} catch (StaleUpdateException) {
			Format::error($output, 'The vehicle ' . $uuid . ' changed while it was being restored; run the command again');

			return self::FAILURE;
		}
		$output->writeln('Restored ' . $restored->getUuid() . ' (' . $restored->getPlate() . ') for ' . $restored->getUserId() . '.');

		return self::SUCCESS;
	}
}
