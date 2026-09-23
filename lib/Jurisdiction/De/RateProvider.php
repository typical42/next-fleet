<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IRateProvider;

/**
 * German rates by date, cited by URL and not legal advice (docs/legal.md).
 */
class RateProvider implements IRateProvider {
	/**
	 * The standard rate by the first day it applied, newest last. The table starts at 2007: an
	 * earlier day is not stated rather than guessed.
	 */
	private const VAT = [
		'2007-01-01' => 1900,
		'2020-07-01' => 1600,
		'2021-01-01' => 1900,
	];

	/**
	 * Grams of CO₂ per litre burnt, tank to wheel, by the first day each applied. From 1990, the
	 * base year of the national inventory; burning a litre of diesel has not changed since. CNG is
	 * sold by the kilogram but entered in litres, of gas at no stated pressure, so it has none.
	 */
	private const EMISSIONS = [
		'petrol' => ['1990-01-01' => 2370],
		'diesel' => ['1990-01-01' => 2650],
		'lpg' => ['1990-01-01' => 1640],
	];

	/**
	 * Tenths of a cent per business kilometre by motor car (`Kraftwagen`), by the first day it
	 * applied. From 2014, when the travel-cost reform wrote it into the statute; a trailer, a
	 * tractor and a generator are not motor cars and have none.
	 */
	private const MILEAGE = [
		'car' => ['2014-01-01' => 300],
		'van' => ['2014-01-01' => 300],
		'truck' => ['2014-01-01' => 300],
	];

	public function vatRateAt(\DateTimeInterface $when): ?int {
		return self::on(self::VAT, $when);
	}

	public function emissionFactorAt(string $energy, \DateTimeInterface $when): ?int {
		return self::on(self::EMISSIONS[$energy] ?? [], $when);
	}

	/** The Umweltbundesamt's factors for fuels. */
	public function emissionSourceUrl(): string {
		return 'https://www.umweltbundesamt.de/publikationen/co2-emissionsfaktoren-fuer-fossile-brennstoffe-0';
	}

	public function mileageRateAt(string $vehicleType, \DateTimeInterface $when): ?int {
		return self::on(self::MILEAGE[$vehicleType] ?? [], $when);
	}

	/** §9 (1) 3 Nr. 4a EStG, which sets the flat rate for a business trip by one's own car. */
	public function mileageSourceUrl(): string {
		return 'https://www.gesetze-im-internet.de/estg/__9.html';
	}

	/** The Umweltbundesamt's figure for the electricity Germany consumed, its newest year. */
	public function gridFactor(): ?array {
		return [
			'grams' => 363,
			'year' => 2024,
			'source' => 'https://www.umweltbundesamt.de/publikationen/entwicklung-der-spezifischen-treibhausgas-11',
		];
	}

	/**
	 * @param array<string, int> $table by the first day each value applied, oldest first
	 */
	private static function on(array $table, \DateTimeInterface $when): ?int {
		// Compared as ISO dates, which sort as strings do.
		$day = $when->format('Y-m-d');
		$value = null;
		foreach ($table as $from => $applied) {
			if ($day >= $from) {
				$value = $applied;
			}
		}

		return $value;
	}

	/**
	 * Insurance is exempt (§4 Nr. 10 UStG) and pays Versicherungsteuer instead, vehicle tax is a
	 * tax, and a fine is not a supply. Tolls, parking and lease depend on who levies them, and
	 * keep the standard rate rather than a guess.
	 */
	public function vatFreeCategories(): array {
		return ['insurance', 'tax', 'fine'];
	}

	/** §12 UStG, the statute itself. */
	public function vatSourceUrl(): string {
		return 'https://www.gesetze-im-internet.de/ustg_1980/__12.html';
	}
}
