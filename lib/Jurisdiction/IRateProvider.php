<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * The rates a country sets, each by the date it applied: a 2020 receipt is read at the 2020 rate,
 * never today's (docs/contributing.md#rules-that-keep-the-seam-honest).
 *
 * Internal seam, not a public API - see docs/contributing.md. A jurisdiction that sets none has
 * no provider at all and says so with a null (`IJurisdiction::rates()`).
 *
 * @psalm-type GridAverage = array{grams: int, year: int, source: string}
 */
interface IRateProvider {
	/**
	 * The standard VAT rate on the day `$when` falls on in its own timezone, in basis points
	 * (`1900` = 19 %), or null for a day the table does not state. Null is "not stated", never a
	 * zero rate (docs/architecture.md#data-model).
	 */
	public function vatRateAt(\DateTimeInterface $when): ?int;

	/**
	 * The expense categories (`ExpenseService::CATEGORIES`) on which no VAT is charged. Such an
	 * expense is prefilled with no rate, "not stated", since a stored rate is never zero.
	 *
	 * @return list<string>
	 */
	public function vatFreeCategories(): array;

	/** Where the VAT rate is written down. A URL, because a rate is linked and never quoted. */
	public function vatSourceUrl(): string;

	/**
	 * Grams of CO₂ one litre of `$energy` gives off when burnt, on the day `$when` falls on, or
	 * null for an energy or a day the table does not state. Never electricity's: a kWh emits what
	 * the grid behind it does (`gridFactor()`).
	 */
	public function emissionFactorAt(string $energy, \DateTimeInterface $when): ?int;

	/** Where the fuel factors are written down. */
	public function emissionSourceUrl(): string;

	/**
	 * What one business kilometre in a `$vehicleType` is worth on the day `$when` falls on, in
	 * tenths of a cent of the jurisdiction's currency, or null for a type or a day the table does
	 * not state. The mileage claim's rate; a commute is a different deduction and not asked here.
	 *
	 * @param string $vehicleType one of `VehicleService::VEHICLE_TYPES`
	 */
	public function mileageRateAt(string $vehicleType, \DateTimeInterface $when): ?int;

	/** Where the mileage rate is written down. */
	public function mileageSourceUrl(): string;

	/**
	 * The country's average grid in grams of CO₂ per kWh, the year it describes and its source:
	 * the default a person's own grid factor replaces. One figure and not a table, since a grid
	 * average is published years late and a charge today has none of its own. Null where the
	 * country states none.
	 *
	 * @return ?GridAverage
	 */
	public function gridFactor(): ?array;
}
