<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\Jurisdictions;

/**
 * Whether a trip states what its vehicle's jurisdiction requires. An incomplete trip is a Flag,
 * never a refusal (CONTEXT.md), and it is computed on read rather than stored: the answer is the
 * trip plus a ruleset, and a stored one would drift the day the ruleset changes.
 */
class Completeness {
	public function __construct(
		private Jurisdictions $jurisdictions,
	) {
	}

	/**
	 * The required fields the trip leaves unstated, in the order the ruleset names them - which is
	 * the order the follow-up question asks for them in.
	 *
	 * @return list<string>
	 */
	public function missing(Vehicle $vehicle, Trip $trip): array {
		$rules = $this->jurisdictions->get($vehicle->getJurisdiction())->logbookRules();
		if ($rules === null) {
			return [];
		}

		// The ruleset names fields as the trip's wire form does, plus the plate, which is the
		// vehicle's (ILogbookRules::mandatoryFields()).
		$stated = ['plate' => $vehicle->getPlate()] + $trip->jsonSerialize();

		return array_values(array_filter(
			$rules->mandatoryFields($trip->getCategory()),
			static fn (string $field): bool => ($stated[$field] ?? null) === null || $stated[$field] === '',
		));
	}
}
