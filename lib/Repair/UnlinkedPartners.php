<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Repair;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\PartnerLinks;
use OCA\NextFleet\Service\TripService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Links the trips `nextfleet:check` reports as `partner`, on `occ maintenance:repair` and on each
 * upgrade after 1.0.0. The migration step that links them in bulk can only run again with `debug`
 * on, and it is written for a server in maintenance mode.
 *
 * This one runs on a live server, so it links as a trip written in the app does: under the
 * vehicle's hold, by PartnerLinks (a spelling merged away finds its record, a new name waits for
 * the owner's hold), and through TripService, which moves the token so sync hands the link out.
 */
class UnlinkedPartners implements IRepairStep {
	use TTransactional;

	public function __construct(
		private TripMapper $trips,
		private VehicleMapper $vehicles,
		private PartnerLinks $links,
		private TripService $tripService,
		private IAppConfig $config,
		private IDBConnection $db,
	) {
	}

	public function getName(): string {
		return 'Link each trip whose partner has no record yet';
	}

	public function run(IOutput $output): void {
		$installed = $this->config->getValueString(Application::APP_ID, 'installed_version');
		// The upgrade to 1.0.0 has just linked every trip in its migration step; a first install has none.
		if ($installed === '' || version_compare($installed, '1.0.0', '<')) {
			return;
		}
		$linked = 0;
		foreach ($this->trips->findVehiclesWithUnlinkedPartners() as $vehicleId) {
			try {
				$linked += $this->atomicRetry(fn (): int => $this->link($vehicleId), $this->db);
			} catch (\Throwable $e) {
				// A failed step fails the upgrade; one vehicle's trips are not worth that.
				$output->warning('The trips of vehicle ' . $vehicleId . ' were not linked: ' . $e->getMessage());
			}
		}
		if ($linked > 0) {
			$output->info('Trips linked to a partner record: ' . $linked);
		}
	}

	/**
	 * @return int the trips it linked
	 * @throws \OCP\DB\Exception
	 */
	private function link(int $vehicleId): int {
		$this->vehicles->hold($vehicleId);
		try {
			$vehicle = $this->vehicles->findAnyById($vehicleId);
		} catch (DoesNotExistException) {
			return 0;
		}
		$linked = 0;
		foreach ($this->trips->findAnyUnlinkedPartners($vehicleId) as $trip) {
			$partner = $this->links->of($vehicle, $trip->getPartner());
			if ($partner !== null) {
				$this->tripService->relink(Audit::REPAIRED_BY, $vehicle, $trip, $partner);
				$linked++;
			}
		}

		return $linked;
	}
}
