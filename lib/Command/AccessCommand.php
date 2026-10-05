<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Who may use one vehicle. Read from the mappers, not GrantService::list(): that asks as the
 * owner, and a vehicle in the trash or an erased owner's would be refused.
 */
class AccessCommand extends Command {
	public function __construct(
		private VehicleMapper $vehicles,
		private AccessMapper $grants,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:access')
			->setDescription('List a vehicle\'s owner and the grants on it')
			->addArgument('vehicle', InputArgument::REQUIRED, 'the vehicle\'s uuid (nextfleet:vehicles)')
			->addOption('all', null, InputOption::VALUE_NONE, 'revoked grants too, with when they were revoked');
		Format::configure($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$format = Format::of($input);
		} catch (\InvalidArgumentException $e) {
			Format::error($output, $e->getMessage());

			return self::INVALID;
		}
		$uuid = (string)$input->getArgument('vehicle');
		try {
			$vehicle = $this->vehicles->findAnyByUuid($uuid);
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has the uuid ' . $uuid);

			return self::INVALID;
		}

		$grants = $input->getOption('all') === true
			? $this->grants->findAnyByVehicle((int)$vehicle->getId())
			: $this->grants->findByVehicle((int)$vehicle->getId());
		$format->rows($output, [
			// Always a row, so never the empty line. The owner holds no grant, hence no uuid, and
			// no date: the vehicle's own would be wrong after a transfer.
			['uuid' => null, 'grantee' => $vehicle->getUserId(), 'type' => Access::USER, 'role' => 'owner', 'granted_at' => null, 'deleted_at' => null],
			...array_map(static fn (Access $grant): array => [
				'uuid' => $grant->getUuid(),
				'grantee' => $grant->getGrantee(),
				'type' => $grant->getGranteeType(),
				'role' => $grant->getRole(),
				'granted_at' => Format::instant($grant->getCreatedAt()),
				'deleted_at' => Format::instant($grant->getDeletedAt()),
			], $grants),
		], '');

		return self::SUCCESS;
	}
}
