<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\OdometerService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fixes the drift `nextfleet:check` reports as `cache` and `flag` (OdometerService::recompute()).
 */
class RecomputeCommand extends Command {
	public function __construct(
		private VehicleMapper $mapper,
		private OdometerService $odometer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:recompute')
			->setDescription('Settle each vehicle\'s odometer again: the cached counters and the flags, as a write does')
			->addOption('vehicle', null, InputOption::VALUE_REQUIRED, 'only this vehicle, by uuid')
			->addOption('all', null, InputOption::VALUE_NONE, 'every vehicle, deleted ones included')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'print what would change and change nothing; exits 1 if anything would');
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
		$all = $input->getOption('all') === true;
		if (($uuid === null) === !$all) {
			Format::error($output, 'Name one vehicle with --vehicle, or take every vehicle with --all');

			return self::INVALID;
		}
		try {
			$vehicles = $all
				? $this->mapper->findForAdmin(null, null)
				: [$this->mapper->findAnyByUuid((string)$uuid)];
		} catch (DoesNotExistException) {
			Format::error($output, 'No vehicle has the uuid ' . (string)$uuid);

			return self::INVALID;
		}

		$dryRun = $input->getOption('dry-run') === true;
		$rows = [];
		$failed = null;
		foreach ($vehicles as $vehicle) {
			try {
				$changes = $this->odometer->recompute($vehicle, $dryRun);
			} catch (DoesNotExistException) {
				// Gone since it was listed: nothing left to settle.
				continue;
			} catch (\Exception $e) {
				// Each vehicle commits on its own, so the ones before stay settled and are printed.
				$failed = 'Stopped at vehicle ' . $vehicle->getUuid() . ': ' . $e->getMessage();
				break;
			}
			foreach ($changes as $change) {
				unset($change['counter']);
				$rows[] = ['vehicle' => $vehicle->getUuid()] + $change;
			}
		}
		$format->rows($output, $rows, $dryRun ? 'Nothing would change.' : 'Nothing changed.');
		if ($failed !== null) {
			Format::error($output, $failed);

			return self::FAILURE;
		}

		return $dryRun && $rows !== [] ? self::FAILURE : self::SUCCESS;
	}
}
