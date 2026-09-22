<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * The periodic inspection a country requires, and how often (docs/contributing.md). It replaces
 * a due-date column: the next inspection is a reminder made from `template()`
 * (docs/architecture.md#data-model).
 *
 * Internal seam, not a public API - see docs/contributing.md. A jurisdiction that requires none
 * has no scheme and says so with a null (`IJurisdiction::inspectionScheme()`).
 */
interface IInspectionScheme {
	/**
	 * The inspection as a template: its key names it, `recurMonths` is the cadence for this
	 * vehicle type, and it is due by date. A default the vehicle sheet lets the user change.
	 *
	 * @param string $vehicleType one of `VehicleService::VEHICLE_TYPES`
	 */
	public function template(string $vehicleType): ReminderTemplate;

	/**
	 * Months from first registration to the first inspection. Never fewer than the cadence.
	 *
	 * @param string $vehicleType one of `VehicleService::VEHICLE_TYPES`
	 */
	public function firstDueMonths(string $vehicleType): int;
}
