<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\TestCase;

/**
 * What an entity hands out of its money to a reader who may not see costs
 * (VehicleAccess::COSTS).
 */
class CostEntitiesTest extends TestCase {
	/**
	 * The column is nullable, as every boolean is, and a row older than it reads as shared: that
	 * is what every vehicle did before the switch.
	 */
	public function testAVehicleSharesCostsUnlessSwitchedOff(): void {
		$this->assertTrue(Vehicle::fromRow(['user_id' => 'alice'])->sharesCosts());
		$this->assertTrue(Vehicle::fromRow(['user_id' => 'alice', 'costs_shared' => null])->sharesCosts());
		$this->assertTrue(Vehicle::fromRow(['user_id' => 'alice', 'costs_shared' => '1'])->sharesCosts());
		$this->assertFalse(Vehicle::fromRow(['user_id' => 'alice', 'costs_shared' => '0'])->sharesCosts());
	}

	public function testTheSwitchIsOnTheWireAsABoolean(): void {
		$this->assertTrue(Vehicle::fromRow(['user_id' => 'alice', 'costs_shared' => null])->jsonSerialize()['costs_shared']);
		$this->assertFalse(Vehicle::fromRow(['user_id' => 'alice', 'costs_shared' => '0'])->jsonSerialize()['costs_shared']);
	}

	/** What the car cost is a cost, and so is what it is still worth. */
	public function testAVehicleShowsItsPriceOnlyWithCosts(): void {
		$vehicle = Vehicle::fromRow(['user_id' => 'alice', 'purchase_price' => '3200000', 'residual_est' => '1200000']);

		$vehicle->setMay(['view', 'costs']);
		$this->assertSame(3200000, $vehicle->jsonSerialize()['purchase_price']);
		$this->assertSame(1200000, $vehicle->jsonSerialize()['residual_est']);

		$vehicle->setMay(['view', 'log']);
		$this->assertNull($vehicle->jsonSerialize()['purchase_price']);
		$this->assertNull($vehicle->jsonSerialize()['residual_est']);
	}

	/** The fill-up keeps what was filled; only what it cost goes, and the row read is untouched. */
	public function testAFillUpWithoutItsCostKeepsTheRest(): void {
		$fill = Energy::fromRow(['uuid' => 'f1', 'amount' => '42000', 'unit_price' => '1799', 'total' => '7556', 'vat_rate' => '1900']);

		$withheld = $fill->withoutCost()->jsonSerialize();

		$this->assertNull($withheld['unit_price']);
		$this->assertNull($withheld['total']);
		$this->assertSame(42000, $withheld['amount']);
		$this->assertSame('f1', $withheld['uuid']);
		$this->assertSame(7556, $fill->getTotal());
		$this->assertSame([], $fill->withoutCost()->getUpdatedFields());
	}

	public function testARecordWithoutItsCostKeepsTheRest(): void {
		$record = Maintenance::fromRow(['uuid' => 'm1', 'title' => 'Brakes', 'cost' => '42000']);

		$withheld = $record->withoutCost()->jsonSerialize();

		$this->assertNull($withheld['cost']);
		$this->assertSame('Brakes', $withheld['title']);
		$this->assertSame(42000, $record->getCost());
		$this->assertSame([], $record->withoutCost()->getUpdatedFields());
	}
}
