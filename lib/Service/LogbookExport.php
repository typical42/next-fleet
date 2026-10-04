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
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * One vehicle's logbook for one year, read by the core and printed by its jurisdiction
 * (docs/features.md#logbook-mode). Which trips, what each one lacks and when the mode was on are
 * decided here; the country only lays them out (`IReportRenderer`).
 *
 * @psalm-import-type LateChange from LogbookReport
 */
class LogbookExport {
	/** The trail's word for the entry itself (TripService), which is never a late change. */
	private const CREATED = 'created';

	public function __construct(
		private VehicleService $fleet,
		private TripMapper $trips,
		private AuditMapper $audit,
		private Completeness $completeness,
		private Jurisdictions $jurisdictions,
		private EnteredBy $enteredBy,
		private LoggerInterface $logger,
		private LogbookPeriods $modePeriods,
		private IDateTimeZone $zone,
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
		$lines = $this->lines($vehicle, $year, $start, $end, $periods);

		return $renderer->render(new LogbookReport(
			$vehicle,
			$year,
			$lines,
			$periods,
			$jurisdiction->logbookRules()?->sourceUrl(),
			$this->enteredBy->names($vehicle, array_map(static fn (array $line): string => $line['trip']->getCreatedBy(), $lines)),
			$this->zone->getTimeZone(),
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
	 * And, under the mode, when it was changed by something that left no row - defence in depth
	 * (docs/architecture.md#the-fahrtenbuch-export).
	 *
	 * @param list<array{from: int, to: ?int, plates: list<array{plate: ?string, from: int}>}> $periods
	 * @return list<array{trip: Trip, missing: list<string>, late: list<LateChange>, unlogged: ?int}>
	 * @throws \OCP\DB\Exception
	 */
	private function lines(Vehicle $vehicle, int $year, int $start, int $end, array $periods): array {
		$trips = array_values(array_filter(
			$this->trips->findAnyStartedBetween((int)$vehicle->getId(), $start, $end),
			static fn (Trip $trip): bool => LocalYear::holds($year, $trip->getStartedAt(), $trip->getStartedAtOff()),
		));
		[$late, $logged] = $this->trails($trips, $this->jurisdictions->get($vehicle->getJurisdiction())->logbookRules()?->lockDelayDays());

		return array_map(function (Trip $trip) use ($vehicle, $periods, $late, $logged): array {
			$period = LogbookPeriods::covering($periods, $trip->getStartedAt());

			return [
				'trip' => $trip,
				'missing' => $period !== null ? $this->completeness->missing($vehicle, $trip) : [],
				'late' => $late[(int)$trip->getId()] ?? [],
				'unlogged' => $period !== null && self::unlogged($trip, $period['from'], $logged) ? $trip->getUpdatedAt() : null,
			];
		}, $trips);
	}

	/**
	 * The rule docs/architecture.md#the-fahrtenbuch-export states, for a trip set off in a period
	 * that began at `$periodFrom`.
	 *
	 * @param array<int, ?int> $logged
	 */
	private static function unlogged(Trip $trip, int $periodFrom, array $logged): bool {
		$id = (int)$trip->getId();
		$since = array_key_exists($id, $logged) ? $logged[$id] : $trip->getCreatedAt();

		return $since !== null && $trip->getUpdatedAt() > $since && $trip->getUpdatedAt() >= $periodFrom;
	}

	/**
	 * What the trips' trails say: the late rows by trip id, oldest first, and the token the newest
	 * row left each trip with - null for a row older than that key.
	 *
	 * Each late row carries the offsets in force before it, because a time it replaced reads right
	 * only with those, and a later edit may have moved them. They are read back from the trip as it
	 * is now, newest row first, through every row on the trail and not only the late ones - and so
	 * is `ended_at`.
	 *
	 * Late is decided here, against the export's own rules, not read off the row: a stored flag
	 * answered the rules of its day, and an `ended_at` set far ahead stopped the clock it was
	 * measured by. The lock delay runs from the earliest the trail had said the journey ended by the
	 * time of the change, or from when the trip was entered if that is earlier
	 * (docs/architecture.md#the-fahrtenbuch-export). A day is 86 400 seconds, as TripService counts.
	 *
	 * @param list<Trip> $trips
	 * @param ?int $delayDays the ruleset's lock delay, or null where it has none and nothing is late
	 * @return array{array<int, list<LateChange>>, array<int, ?int>}
	 * @throws \OCP\DB\Exception
	 */
	private function trails(array $trips, ?int $delayDays): array {
		if ($trips === []) {
			return [[], []];
		}

		$offsets = [];
		$ended = [];
		$entered = [];
		foreach ($trips as $trip) {
			$id = (int)$trip->getId();
			$offsets[$id] = ['started_at_off' => $trip->getStartedAtOff(), 'ended_at_off' => $trip->getEndedAtOff()];
			$ended[$id] = $trip->getEndedAt();
			$entered[$id] = $trip->getCreatedAt();
		}

		$changes = [];
		$logged = [];
		foreach (array_reverse($this->audit->findForEntities(Audit::TRIP, array_keys($offsets))) as $row) {
			$id = $row->getEntityId();
			$diff = $row->getDiffJson();
			if (!array_key_exists($id, $logged)) {
				$logged[$id] = is_int($diff['updated_at'] ?? null) ? $diff['updated_at'] : null;
			}
			/** @var array<string, array{mixed, mixed}> $fields */
			$fields = $diff['fields'] ?? [];
			foreach (['started_at_off', 'ended_at_off'] as $column) {
				if (isset($fields[$column])) {
					$offsets[$id][$column] = (int)$fields[$column][0];
				}
			}
			$after = $ended[$id];
			if (isset($fields['ended_at'][0])) {
				$ended[$id] = (int)$fields['ended_at'][0];
			}
			$changes[] = [$id, (string)($diff['change'] ?? ''), $row->getCreatedAt(), $fields, $offsets[$id], min($ended[$id], $after)];
		}

		// Oldest first, so each change is measured against what the trail had said by then.
		$late = [];
		$earliest = $entered;
		foreach (array_reverse($changes) as [$id, $change, $at, $fields, $before, $endedBy]) {
			$earliest[$id] = min($earliest[$id], $endedBy);
			if ($delayDays !== null && $change !== self::CREATED && $at > $earliest[$id] + $delayDays * 86400) {
				$late[$id][] = ['change' => $change, 'at' => $at, 'fields' => $fields, 'offsets' => $before];
			}
		}

		return [$late, $logged];
	}

	/**
	 * When the mode was on (LogbookPeriods) - every period some trip of the local year `[start, end)`
	 * could have set off in - with the plates the vehicle carried in each. The instants are the
	 * server's and carry no offset, so the bounds here are UTC ones.
	 *
	 * @return list<array{from: int, to: ?int, plates: list<array{plate: ?string, from: int}>}>
	 * @throws \OCP\DB\Exception
	 */
	private function periods(Vehicle $vehicle, int $start, int $end): array {
		$periods = array_values(array_filter(
			$this->modePeriods->of($vehicle),
			static fn (array $period): bool => $period['from'] < $end
				&& ($period['to'] === null || $period['to'] > $start),
		));
		if ($periods === []) {
			return [];
		}

		$plates = $this->modePeriods->plates($vehicle);

		return array_map(
			static fn (array $period): array => $period + ['plates' => LogbookPeriods::within($plates, $period)],
			$periods,
		);
	}
}
