<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;

/**
 * The figures a vehicle's header states for one period (docs/ui.md). The client names the period,
 * since "this year" starts at midnight where the person is; the period before it is the client's
 * to ask for too, with a second read.
 *
 * @psalm-import-type Period from ConsumptionService
 * @psalm-import-type Rolling from ConsumptionService
 * @psalm-import-type Cost from CostService
 */
class KpiService {
	public function __construct(
		private VehicleService $fleet,
		private ConsumptionService $consumption,
		private CostService $cost,
	) {
	}

	/**
	 * `hours` is how far the second counter moved, and null on a vehicle that has none.
	 *
	 * @param array<string, mixed> $fields `from` and `to` in unix seconds, half-open; `net` for
	 *                                     "I reclaim VAT"
	 * @return array{consumption: list<Period>, wall_side: ?Rolling, cost: Cost, hours: ?int}
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
		$net = Field::read('net', 'flag', null, $fields['net'] ?? null) === true;

		return [
			'consumption' => $this->consumption->period($vehicle, $from, $to),
			'wall_side' => $this->consumption->wallSide($vehicle, $from, $to),
			'cost' => $this->cost->of($vehicle, $from, $to, $net),
			'hours' => $vehicle->getSecondUnit() === null
				? null
				: $this->consumption->distance($vehicle, $from, $to, OdoReading::SECOND),
		];
	}
}
