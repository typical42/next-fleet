<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;

/**
 * The figures a vehicle's header states for one period (docs/ui.md). The client names the period,
 * since "this year" starts at midnight where the person is; the period before it is the client's
 * to ask for too, with a second read.
 *
 * @psalm-import-type Period from ConsumptionService
 * @psalm-import-type Rolling from ConsumptionService
 * @psalm-import-type Cost from CostService
 * @psalm-import-type Co2 from EmissionService
 * @psalm-type Figures = array{consumption: list<Period>, wall_side: ?Rolling, cost: Cost, hours: ?int}
 */
class KpiService {
	public function __construct(
		private VehicleService $fleet,
		private ConsumptionService $consumption,
		private CostService $cost,
		private EmissionService $emissions,
		private PreferencesService $preferences,
	) {
	}

	/**
	 * `hours` is how far the second counter moved, and null on a vehicle that has none.
	 *
	 * @param array<string, mixed> $fields `from` and `to` in unix seconds, half-open; `net` for
	 *                                     "I reclaim VAT"
	 * @return Figures
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the period is not one
	 * @throws \OCP\DB\Exception
	 */
	public function of(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$from = Field::read('from', 'count', null, $fields['from'] ?? null);
		$to = Field::read('to', 'count', null, $fields['to'] ?? null);
		if (!is_int($from) || !is_int($to) || $from >= $to) {
			throw new \InvalidArgumentException('from and to are a period, the one before the other');
		}

		return $this->figures($vehicle, $from, $to, self::net($fields));
	}

	/**
	 * The Costs screen's year: the header's figures for all of it, and each month's cost. The
	 * months are cut at midnight in the zone the client names, for the reason `of()` takes its
	 * period from the client. A month with no rows has the null cost `CostService` gives any empty
	 * period. The year's CO₂ is read at the reader's own grid factor: whoever looks at a shared
	 * car states the tariff they know.
	 *
	 * @param string $year four digits, as the route carries it
	 * @param array<string, mixed> $fields `tz`, an IANA zone; `net` as for `of()`
	 * @return array{year: Figures, co2: ?Co2, months: list<array{month: int, from: int, to: int, cost: Cost}>}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the year or the zone is not one
	 * @throws \OCP\DB\Exception
	 */
	public function year(string $userId, string $vehicleUuid, string $year, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		// Not an `int`: a cast would read `2025.5` as a year (ReportController::parseYear()).
		if (preg_match('/^\d{4}$/', $year) !== 1) {
			throw new \InvalidArgumentException('A year is four digits');
		}
		$net = self::net($fields);

		$january = new \DateTimeImmutable($year . '-01-01', self::zone($fields['tz'] ?? null));
		$starts = [];
		for ($i = 0; $i <= 12; $i++) {
			$starts[] = $january->add(new \DateInterval('P' . $i . 'M'))->getTimestamp();
		}
		$months = [];
		for ($i = 0; $i < 12; $i++) {
			$months[] = [
				'month' => $i + 1,
				'from' => $starts[$i],
				'to' => $starts[$i + 1],
				'cost' => $this->cost->of($vehicle, $starts[$i], $starts[$i + 1], $net),
			];
		}

		return [
			'year' => $this->figures($vehicle, $starts[0], $starts[12], $net),
			'co2' => $this->emissions->of($vehicle, $starts[0], $starts[12], $this->preferences->gridFactor($userId)),
			'months' => $months,
		];
	}

	/**
	 * @return Figures
	 * @throws \OCP\DB\Exception
	 */
	private function figures(Vehicle $vehicle, int $from, int $to, bool $net): array {
		return [
			'consumption' => $this->consumption->period($vehicle, $from, $to),
			'wall_side' => $this->consumption->wallSide($vehicle, $from, $to),
			'cost' => $this->cost->of($vehicle, $from, $to, $net),
			'hours' => $vehicle->getSecondUnit() === null
				? null
				: $this->consumption->distance($vehicle, $from, $to, OdoReading::SECOND),
		];
	}

	/** @param array<string, mixed> $fields */
	private static function net(array $fields): bool {
		return Field::read('net', 'flag', null, $fields['net'] ?? null) === true;
	}

	/**
	 * The backward-compatible names count too: browsers still report some zones by them.
	 *
	 * @throws \InvalidArgumentException unless it names an IANA zone
	 */
	private static function zone(mixed $name): \DateTimeZone {
		if (!is_string($name) || !in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
			throw new \InvalidArgumentException('tz is the zone the months are cut in');
		}

		return new \DateTimeZone($name);
	}
}
