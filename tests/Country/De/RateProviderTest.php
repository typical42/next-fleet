<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\De;

use OCA\NextFleet\Jurisdiction\De;
use PHPUnit\Framework\TestCase;

/**
 * The German VAT rate by date. Each figure carries its source and nothing that would justify it
 * (docs/legal.md).
 */
class RateProviderTest extends TestCase {
	private function rateOn(string $date): ?int {
		return (new De\RateProvider())->vatRateAt(new \DateTimeImmutable($date . 'T12:00:00+02:00'));
	}

	public function testGermanyHandsOutItsRates(): void {
		$this->assertInstanceOf(De\RateProvider::class, (new De\Profile())->rates());
	}

	/** §12 (1) UStG, in force since 2007-01-01. */
	public function testTheStandardRateIsNineteenPercent(): void {
		$this->assertSame(1900, $this->rateOn('2007-01-01'));
		$this->assertSame(1900, $this->rateOn('2020-06-30'));
		$this->assertSame(1900, $this->rateOn('2021-01-01'));
		$this->assertSame(1900, $this->rateOn('2026-09-19'));
	}

	/** The second Corona tax relief act lowered it for the second half of 2020. */
	public function testItWasSixteenPercentInTheSecondHalfOf2020(): void {
		$this->assertSame(1600, $this->rateOn('2020-07-01'));
		$this->assertSame(1600, $this->rateOn('2020-12-31'));
	}

	/**
	 * The day is the receipt's own, in the offset the caller hands over
	 * (docs/adr/0007-time-is-an-instant-plus-an-offset.md): half past midnight on 1 July in Berlin
	 * is 16 %, though it is still 30 June in UTC.
	 */
	public function testTheDayIsTheReceiptsOwn(): void {
		$this->assertSame(1600, (new De\RateProvider())->vatRateAt(new \DateTimeImmutable('2020-07-01T00:30:00+02:00')));
	}

	/** Before 2007 the rate was lower, and this table does not state it: no answer beats a wrong one. */
	public function testItDoesNotGuessBefore2007(): void {
		$this->assertNull($this->rateOn('2006-12-31'));
	}

	/**
	 * Insurance premiums are exempt (§4 Nr. 10 UStG) and carry Versicherungsteuer instead, vehicle
	 * tax is a tax, and a fine is not a supply: none charges VAT. Tolls, parking and lease depend on
	 * who levies them, so they keep the standard rate.
	 */
	public function testInsuranceTaxAndFinesCarryNoVat(): void {
		$this->assertSame(['insurance', 'tax', 'fine'], (new De\RateProvider())->vatFreeCategories());
	}

	/**
	 * Grams of CO₂ per litre burnt, tank to wheel. CNG is sold by the kilogram and entered here in
	 * litres of nobody knows what pressure, so it has no factor rather than a wrong one.
	 */
	public function testFuelsBurnAtTheirEmissionFactor(): void {
		$rates = new De\RateProvider();
		$day = new \DateTimeImmutable('2025-03-01T12:00:00+01:00');

		$this->assertSame(2370, $rates->emissionFactorAt('petrol', $day));
		$this->assertSame(2650, $rates->emissionFactorAt('diesel', $day));
		$this->assertSame(1640, $rates->emissionFactorAt('lpg', $day));
		$this->assertNull($rates->emissionFactorAt('cng', $day));
		$this->assertNull($rates->emissionFactorAt('electric', $day), 'electricity is the grid factor');
	}

	/** Before the table starts nothing is stated, as for VAT. */
	public function testItDoesNotStateAFuelFactorBeforeItsTable(): void {
		$this->assertNull((new De\RateProvider())->emissionFactorAt('petrol', new \DateTimeImmutable('1989-12-31T12:00:00+01:00')));
	}

	/** The German average a person's own grid factor replaces, with the year it describes. */
	public function testTheGridFactorIsTheGermanAverageWithItsYear(): void {
		$grid = (new De\RateProvider())->gridFactor();

		$this->assertSame(363, $grid['grams'] ?? null);
		$this->assertSame(2024, $grid['year'] ?? null);
	}

	/**
	 * §9 (1) 3 Nr. 4a EStG: 0,30 € per kilometre by motor car, in tenths of a cent, since the
	 * travel-cost reform put it in the statute on 2014-01-01.
	 */
	public function testABusinessKilometreByCarIsThirtyCent(): void {
		$rates = new De\RateProvider();
		$day = new \DateTimeImmutable('2024-05-02T12:00:00+02:00');

		$this->assertSame(300, $rates->mileageRateAt('car', $day));
		$this->assertSame(300, $rates->mileageRateAt('van', $day));
		$this->assertSame(300, $rates->mileageRateAt('truck', $day));
		$this->assertSame(300, $rates->mileageRateAt('car', new \DateTimeImmutable('2014-01-01T00:30:00+01:00')));
	}

	/**
	 * A trailer drives nowhere by itself, and whether a tractor is a motor car is not this table's
	 * call: no rate rather than a guessed one.
	 */
	public function testItStatesNoRateForWhatIsNotAMotorCar(): void {
		$rates = new De\RateProvider();
		$day = new \DateTimeImmutable('2024-05-02T12:00:00+02:00');

		foreach (['trailer', 'tractor', 'generator'] as $type) {
			$this->assertNull($rates->mileageRateAt($type, $day), $type);
		}
	}

	/** Before the statute set it, the table says nothing, as for VAT. */
	public function testItStatesNoMileageRateBefore2014(): void {
		$this->assertNull((new De\RateProvider())->mileageRateAt('car', new \DateTimeImmutable('2013-12-31T23:30:00+01:00')));
	}

	public function testTheMileageRateCitesTheStatute(): void {
		$this->assertSame(
			'https://www.gesetze-im-internet.de/estg/__9.html',
			(new De\RateProvider())->mileageSourceUrl(),
		);
	}

	/** The statute itself, not an article about it. */
	public function testItCitesTheStatute(): void {
		$this->assertSame(
			'https://www.gesetze-im-internet.de/ustg_1980/__12.html',
			(new De\RateProvider())->vatSourceUrl(),
		);
	}
}
