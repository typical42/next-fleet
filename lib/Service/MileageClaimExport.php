<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\MileageClaim;
use Psr\Log\LoggerInterface;

/**
 * One vehicle's mileage claim for one year: its business trips, each valued at its jurisdiction's
 * rate on the day it set off (docs/architecture.md#the-mileage-claim). What is valued and how is
 * decided here; the country only lays it out (`IClaimRenderer`).
 */
class MileageClaimExport {
	public function __construct(
		private VehicleService $fleet,
		private TripMapper $trips,
		private Jurisdictions $jurisdictions,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The printable page, or null where there is no claim: the jurisdiction states no rate or
	 * prints no claim, or the vehicle counts hours. Reading it takes what reading the timeline
	 * takes.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function year(string $userId, string $vehicleUuid, int $year): ?string {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$jurisdiction = $this->jurisdictions->get($vehicle->getJurisdiction());
		$rates = $jurisdiction->rates();
		$renderer = $jurisdiction->claimRenderer();
		if ($rates === null || $renderer === null || $vehicle->getOdoUnit() !== 'km') {
			return null;
		}

		// Logged as the logbook is (docs/security.md): it lists where the driver went.
		$this->logger->info('Mileage claim export', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => $vehicleUuid,
			'year' => $year,
		]);

		[$start, $end] = LocalYear::window($year);
		// Voided trips were not driven as far as a claim goes, and a commute is a different
		// deduction under different rules: a sum mixing them is wrong where nobody can see it.
		$trips = array_filter(
			$this->trips->findAnyStartedBetween((int)$vehicle->getId(), $start, $end),
			static fn (Trip $trip): bool => $trip->getDeletedAt() === null
				&& $trip->getCategory() === Trip::BUSINESS
				&& LocalYear::holds($year, $trip->getStartedAt(), $trip->getStartedAtOff()),
		);

		$lines = [];
		$total = null;
		$kilometres = null;
		foreach ($trips as $trip) {
			$distance = $trip->kilometres();
			$rate = $rates->mileageRateAt($vehicle->getVehicleType(), Jurisdictions::localTime($trip->getStartedAt(), $trip->getStartedAtOff()));
			// Rounded per line, so the lines printed add up to the sum printed.
			$amount = $distance === null || $rate === null ? null : intdiv($distance * $rate + 5, 10);
			if ($amount !== null) {
				$total = ($total ?? 0) + $amount;
				$kilometres = ($kilometres ?? 0) + $distance;
			}
			$lines[] = ['trip' => $trip, 'kilometres' => $distance, 'rate' => $rate, 'amount' => $amount];
		}

		return $renderer->render(new MileageClaim($vehicle, $year, $lines, $total, $kilometres, $rates->mileageSourceUrl()));
	}
}
