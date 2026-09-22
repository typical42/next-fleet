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

	/** The statute itself, not an article about it. */
	public function testItCitesTheStatute(): void {
		$this->assertSame(
			'https://www.gesetze-im-internet.de/ustg_1980/__12.html',
			(new De\RateProvider())->vatSourceUrl(),
		);
	}
}
