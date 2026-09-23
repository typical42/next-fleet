<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\Jurisdictions;

/**
 * The CO₂ a vehicle's fill-ups gave off in a period, always an estimate
 * (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @psalm-type Grid = array{grams: int, year: ?int, source: ?string}
 * @psalm-type Co2 = array{grams: ?int, unstated: bool, source: string, grid: ?Grid}
 */
class EmissionService {
	public function __construct(
		private EnergyMapper $energy,
		private Jurisdictions $jurisdictions,
	) {
	}

	/**
	 * Grams of CO₂, or null for a vehicle whose country states no factors: unavailable, never
	 * zero. `grams` is null for a period with nothing burnt. A fill-up whose factor is not stated
	 * leaves the sum and sets `unstated`. `grid` is the factor the charges were read at, null when
	 * there were none; a person's own has no year and no source.
	 *
	 * @param ?int $personalGrid grams per kWh this person stated, or null for the country's
	 * @return ?Co2
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle, int $from, int $to, ?int $personalGrid): ?array {
		$rates = $this->jurisdictions->get($vehicle->getJurisdiction())->rates();
		if ($rates === null) {
			return null;
		}
		$grid = $personalGrid !== null
			? ['grams' => $personalGrid, 'year' => null, 'source' => null]
			: $rates->gridFactor();

		$grams = null;
		$unstated = false;
		$charged = false;
		foreach ($this->energy->findBetween((int)$vehicle->getId(), $from, $to) as $fill) {
			if ($fill->getEnergy() === 'electric') {
				$charged = true;
				$factor = $grid['grams'] ?? null;
			} else {
				$factor = $rates->emissionFactorAt($fill->getEnergy(), Jurisdictions::localTime($fill->getFilledAt(), $fill->getFilledAtOff()));
			}
			if ($factor === null) {
				$unstated = true;
				continue;
			}
			// Millilitres × grams per litre, or watt-hours × grams per kWh: a thousandth either way.
			$grams = ($grams ?? 0) + intdiv($fill->getAmount() * $factor + 500, 1000);
		}

		return [
			'grams' => $grams,
			'unstated' => $unstated,
			'source' => $rates->emissionSourceUrl(),
			'grid' => $charged ? $grid : null,
		];
	}
}
