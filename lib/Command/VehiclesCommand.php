<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The uuids every other admin command takes; nothing else in `occ` shows one.
 */
class VehiclesCommand extends Command {
	public function __construct(
		private VehicleMapper $mapper,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:vehicles')
			->setDescription('List every vehicle with its uuid, owner and lifecycle')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'only the vehicles this uid owns')
			->addOption('deleted', null, InputOption::VALUE_NONE, 'list the deleted vehicles instead of the live ones');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}
		$owner = $input->getOption('user');
		// The uid as stored, for the reason SeedCommand gives. A name no account holds, such as an
		// erased owner's pseudonym (ErasureService), is taken as typed.
		$owner = $owner === null ? null : $this->users->get((string)$owner)?->getUID() ?? (string)$owner;
		$vehicles = $this->mapper->findForAdmin($owner, $input->getOption('deleted') === true);

		$format->rows($output, array_map(static fn (Vehicle $vehicle): array => [
			'uuid' => $vehicle->getUuid(),
			'plate' => $vehicle->getPlate(),
			'name' => trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? '')),
			'owner' => $vehicle->getUserId(),
			'lifecycle' => $vehicle->getLifecycle(),
			'deleted_at' => Format::instant($vehicle->getDeletedAt()),
		], $vehicles), 'No vehicles.');

		return self::SUCCESS;
	}
}
