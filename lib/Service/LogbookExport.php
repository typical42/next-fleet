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
 *
 * @psalm-import-type LateChange from LogbookReport
 */
class LogbookExport {
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

		[$start, $end] = LocalYear::window($year);
		$periods = $this->periods($vehicle, $start, $end);

		return $renderer->render(new LogbookReport(
			$vehicle,
			$year,
			$this->lines($vehicle, $year, $start, $end, $periods),
			$periods,
			$jurisdiction->logbookRules()?->sourceUrl(),
		));
	}

	/**
	 * The trips that set off in the year where they set off (docs/architecture.md#time), voided ones
	 * included. A trip set off under the mode carries the fields its ruleset requires and it leaves
	 * unstated; any other carries none, because what the logbook asks is said only under the mode
	 * (docs/features.md#logbook-mode).
	 *
	 * Each also carries its late changes - edits, voids and restores. One inside the lock delay is
	 * still the entry being made; one after it changed a record, and a change the auditor cannot see
	 * is not documented.
	 *
	 * @param list<array{from: int, to: ?int}> $periods
	 * @return list<array{trip: Trip, missing: list<string>, late: list<LateChange>}>
	 * @throws \OCP\DB\Exception
	 */
	private function lines(Vehicle $vehicle, int $year, int $start, int $end, array $periods): array {
		$trips = array_values(array_filter(
			$this->trips->findAnyStartedBetween((int)$vehicle->getId(), $start, $end),
			static fn (Trip $trip): bool => LocalYear::holds($year, $trip->getStartedAt(), $trip->getStartedAtOff()),
		));
		$late = $this->lateChanges($trips);

		return array_map(fn (Trip $trip): array => [
			'trip' => $trip,
			'missing' => self::underTheMode($trip, $periods) ? $this->completeness->missing($vehicle, $trip) : [],
			'late' => $late[(int)$trip->getId()] ?? [],
		], $trips);
	}

	/**
	 * The `late` rows on the trips' trails, by trip id, oldest first.
	 *
	 * Each carries the offsets in force before it, because a time it replaced reads right only with
	 * those, and a later edit may have moved them. They are read back from the trip as it is now,
	 * newest row first, through every row on the trail and not only the late ones.
	 *
	 * @param list<Trip> $trips
	 * @return array<int, list<LateChange>>
	 * @throws \OCP\DB\Exception
	 */
	private function lateChanges(array $trips): array {
		if ($trips === []) {
			return [];
		}

		$offsets = [];
		foreach ($trips as $trip) {
			$offsets[(int)$trip->getId()] = ['started_at_off' => $trip->getStartedAtOff(), 'ended_at_off' => $trip->getEndedAtOff()];
		}

		$late = [];
		foreach (array_reverse($this->audit->findForEntities(Audit::TRIP, array_keys($offsets))) as $row) {
			$id = $row->getEntityId();
			$diff = $row->getDiffJson();
			/** @var array<string, array{mixed, mixed}> $fields */
			$fields = $diff['fields'] ?? [];
			foreach (['started_at_off', 'ended_at_off'] as $column) {
				if (isset($fields[$column])) {
					$offsets[$id][$column] = (int)$fields[$column][0];
				}
			}
			if (($diff['late'] ?? null) === true) {
				$late[$id][] = ['change' => (string)($diff['change'] ?? ''), 'at' => $row->getCreatedAt(), 'fields' => $fields, 'offsets' => $offsets[$id]];
			}
		}

		return array_map('array_reverse', $late);
	}

	/** @param list<array{from: int, to: ?int}> $periods */
	private static function underTheMode(Trip $trip, array $periods): bool {
		foreach ($periods as $period) {
			if ($trip->getStartedAt() >= $period['from'] && ($period['to'] === null || $trip->getStartedAt() < $period['to'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When the mode was on, read off the flips on the vehicle's trail - every period some trip of the
	 * local year `[start, end)` could have set off in.
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
			static fn (array $period): bool => $period['from'] < $end
				&& ($period['to'] === null || $period['to'] > $start),
		));
	}
}
