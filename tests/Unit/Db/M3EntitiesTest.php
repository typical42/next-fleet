<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\TestCase;

/**
 * What M3's columns mean when they come back from the database, before any service reads them.
 */
class M3EntitiesTest extends TestCase {
	public function testAReadingWrittenBeforeM3ReadsAsTheMainCounter(): void {
		$reading = OdoReading::fromRow(['counter' => null]);

		$this->assertSame(OdoReading::MAIN, $reading->getCounter());
		$this->assertSame(OdoReading::MAIN, $reading->jsonSerialize()['counter']);
	}

	public function testAReadingKeepsTheSecondCounter(): void {
		$reading = OdoReading::fromRow(['counter' => OdoReading::SECOND]);

		$this->assertSame(OdoReading::SECOND, $reading->getCounter());
	}

	public function testAVehicleCarriesItsSecondCounter(): void {
		$vehicle = Vehicle::fromRow(['second_unit' => 'h', 'second_value' => '1234']);

		$this->assertSame('h', $vehicle->getSecondUnit());
		$this->assertSame(1234, $vehicle->getSecondValue());
		$this->assertSame('h', $vehicle->jsonSerialize()['second_unit']);
		$this->assertSame(1234, $vehicle->jsonSerialize()['second_value']);
	}

	public function testAFillUpsUnansweredBooleansAreFalse(): void {
		$energy = Energy::fromRow(['full_tank' => null, 'missed_previous' => null, 'is_dc' => null]);

		$this->assertFalse($energy->getFullTank());
		$this->assertFalse($energy->getMissedPrevious());
		$this->assertFalse($energy->getIsDc());
		$this->assertSame(
			['full_tank' => false, 'missed_previous' => false, 'is_dc' => false],
			array_intersect_key($energy->jsonSerialize(), array_flip(['full_tank', 'missed_previous', 'is_dc'])),
		);
	}

	public function testAFillUpReadsItsIntegersAsIntegers(): void {
		$energy = Energy::fromRow([
			'filled_at' => '1758240000', 'filled_at_off' => '7200', 'odo' => '51234', 'second_odo' => null,
			'energy' => 'diesel', 'amount' => '45120', 'unit_price' => '1789', 'total' => '8072',
			'vat_rate' => '1900', 'full_tank' => '1',
		]);

		$this->assertSame(51234, $energy->getOdo());
		$this->assertNull($energy->getSecondOdo());
		$this->assertSame(45120, $energy->getAmount());
		$this->assertSame(1789, $energy->getUnitPrice());
		$this->assertSame(8072, $energy->getTotal());
		$this->assertSame(1900, $energy->getVatRate());
		$this->assertTrue($energy->getFullTank());
	}

	public function testMaintenanceAndExpenseSerializeTheirColumns(): void {
		$maintenance = Maintenance::fromRow([
			'uuid' => 'm', 'type' => 'service', 'done_at' => '10', 'done_at_off' => '0', 'odo' => '5',
			'title' => 'Oil', 'cost' => '12000', 'vat_rate' => null,
		]);
		$expense = Expense::fromRow([
			'uuid' => 'e', 'spent_at' => '10', 'spent_at_off' => '0', 'category' => 'toll',
			'amount' => '850', 'vat_rate' => '0',
		]);

		$this->assertSame('Oil', $maintenance->jsonSerialize()['title']);
		$this->assertSame(12000, $maintenance->jsonSerialize()['cost']);
		// Null is "not stated" and zero is a stated zero; neither may become the other.
		$this->assertNull($maintenance->jsonSerialize()['vat_rate']);
		$this->assertSame(0, $expense->jsonSerialize()['vat_rate']);
		$this->assertSame(850, $expense->jsonSerialize()['amount']);
		$this->assertSame('toll', $expense->jsonSerialize()['category']);
	}
}
