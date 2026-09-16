<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use Psr\Log\LoggerInterface;

/**
 * One vehicle's logbook for one year, read by the core and printed by its jurisdiction
 * (docs/features.md#logbook-mode). Which trips, what each one lacks and when the mode was on are
 * decided here; the country only lays them out (`IReportRenderer`).
 */
class LogbookExport {
	/**
	 * How far a local calendar year reaches past its UTC bounds. An offset is at most fourteen
	 * hours (`TripService`), so a day is room to spare, and the trips are then sorted into years
	 * by their own local date.
	 */
	private const MARGIN = 86400;

	public function __construct(
		private VehicleService $fleet,
		private TripMapper $trips,
		private AuditMapper $audit,
		private Completeness $completeness,
		private Jurisdictions $jurisdictions,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The printable page, or null where the vehicle's jurisdiction has no export for it. Reading it
	 * takes what reading the timeline takes: it shows nothing the timeline does not.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function year(string $userId, string $vehicleUuid, int $year): ?string {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$jurisdiction = $this->jurisdictions->get($vehicle->getJurisdiction());
		$renderer = $jurisdiction->logbookRenderer();
		if ($renderer === null) {
			return null;
		}

		// docs/security.md: the most sensitive thing this app hands out, so it is logged - by ids.
		$this->logger->info('Logbook export', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => $vehicleUuid,
			'year' => $year,
		]);

		$start = gmmktime(0, 0, 0, 1, 1, $year);
		$end = gmmktime(0, 0, 0, 1, 1, $year + 1);

		return $renderer->render(new LogbookReport(
			$vehicle,
			$year,
			$this->lines($vehicle, $year, $start, $end),
			$this->periods($vehicle, $start, $end),
			$jurisdiction->logbookRules()?->sourceUrl(),
		));
	}

	/**
	 * The trips that set off in the year where they set off (docs/architecture.md#time), voided ones
	 * included, each with the fields its ruleset requires and it leaves unstated.
	 *
	 * @return list<array{trip: Trip, missing: list<string>}>
	 * @throws \OCP\DB\Exception
	 */
	private function lines(Vehicle $vehicle, int $year, int $start, int $end): array {
		$lines = [];
		foreach ($this->trips->findAnyStartedBetween((int)$vehicle->getId(), $start - self::MARGIN, $end + self::MARGIN) as $trip) {
			if ((int)gmdate('Y', $trip->getStartedAt() + $trip->getStartedAtOff() * 60) === $year) {
				$lines[] = ['trip' => $trip, 'missing' => $this->completeness->missing($vehicle, $trip)];
			}
		}

		return $lines;
	}

	/**
	 * When the mode was on, read off the flips on the vehicle's trail - every period that reaches
	 * into `[start, end)`.
	 *
	 * Where the first period begins is not a row (docs/features.md#logbook-mode): the first flip's
	 * `before` says whether the vehicle was created under the mode, and a vehicle never switched is
	 * under it since its creation exactly when its column says so. The instants are the server's
	 * and carry no offset, so the bounds here are UTC ones.
	 *
	 * @return list<array{from: int, to: ?int}>
	 * @throws \OCP\DB\Exception
	 */
	private function periods(Vehicle $vehicle, int $start, int $end): array {
		$flips = [];
		foreach ($this->audit->findForEntity(Audit::VEHICLE, (int)$vehicle->getId()) as $row) {
			$pair = $row->getDiffJson()['fields']['logbook_mode'] ?? null;
			if (is_array($pair) && count($pair) === 2) {
				$flips[] = [$pair[0] === true, $pair[1] === true, $row->getCreatedAt()];
			}
		}

		$on = $flips === [] ? $vehicle->getLogbookMode() === true : $flips[0][0];
		$from = $on ? $vehicle->getCreatedAt() : null;
		$periods = [];
		foreach ($flips as [, $after, $at]) {
			if ($after && $from === null) {
				$from = $at;
			} elseif (!$after && $from !== null) {
				$periods[] = ['from' => $from, 'to' => $at];
				$from = null;
			}
		}
		if ($from !== null) {
			$periods[] = ['from' => $from, 'to' => null];
		}

		return array_values(array_filter(
			$periods,
			static fn (array $period): bool => $period['from'] < $end && ($period['to'] === null || $period['to'] > $start),
		));
	}
}
