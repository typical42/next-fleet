<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A fill-up against the real database: the row, the Readings its counters write, and the flags it
 * is answered with.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class EnergyTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private EnergyService $energy;
	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->energy = $container->get(EnergyService::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_energy' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * Rule 5 for a fill-up: the counter it was given is an Observed Reading at the moment of the
	 * fill-up, named after the fill-up that wrote it.
	 */
	public function testAFillUpWithACounterWritesAnObservedReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$written = $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['odo' => 120450]));

		$this->assertSame('diesel', $written['energy']);
		$this->assertSame(42000, $written['amount']);
		$readings = $this->odometer->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(1, $readings);
		$this->assertSame(120450, $readings[0]->getValue());
		$this->assertSame(1750000000, $readings[0]->getReadAt());
		$this->assertSame(120, $readings[0]->getReadAtOff());
		$this->assertSame(OdometerService::OBSERVED, $readings[0]->getOrigin());
		$this->assertSame(OdoReading::ENERGY, $readings[0]->getSourceType());
		$this->assertSame(OdoReading::MAIN, $readings[0]->getCounter());
		$this->assertSame(120450, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * A counter is optional and never prefilled: without one the fill-up is saved and the
	 * odometer is left as it was.
	 */
	public function testAFillUpWithoutACounterWritesNoReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$written = $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp());

		$this->assertNull($written['odo']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
		$this->assertNull($this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * Rule 4: a truck that counts engine hours writes one Reading per counter it was given, each
	 * on its own chain and cached on its own.
	 */
	public function testEachCounterGivenIsAReadingOnItsOwnChain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'energy_types' => ['diesel'],
		]);

		$this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['odo' => 300000, 'second_odo' => 5120]));

		$chains = [];
		foreach ($this->odometer->list(self::OWNER, $vehicle->getUuid()) as $reading) {
			$chains[$reading->getCounter()] = $reading->getValue();
		}
		$this->assertSame([OdoReading::MAIN => 300000, OdoReading::SECOND => 5120], $chains);
		$read = $this->vehicles->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame(300000, $read->getOdoValue());
		$this->assertSame(5120, $read->getSecondValue());
	}

	/** An hour counter on a vehicle that counts none is a field for a chain that is not there. */
	public function testAnHourCounterOnAVehicleWithoutOneIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$this->expectException(\InvalidArgumentException::class);
		$this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['second_odo' => 5120]));
	}

	/** A fill-up with nothing to question states no flag. */
	public function testAPlausibleFillUpIsNotFlagged(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$this->assertSame([], $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp())['flags']);
	}

	/**
	 * `energy_types` decides (docs/architecture.md#data-model), and the sheet never blocks: an
	 * energy the vehicle does not take is saved and flagged.
	 */
	public function testAnEnergyTheVehicleDoesNotTakeIsSavedAndFlagged(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$written = $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['energy' => 'petrol']));

		$this->assertSame('petrol', $written['energy']);
		$this->assertSame([EnergyService::FOREIGN_ENERGY], $written['flags']);
	}

	/** A fill-up without a total is saved and says it has no price; there is none to derive. */
	public function testAFillUpWithoutATotalIsFlaggedNoPrice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$written = $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['total' => '']));

		$this->assertNull($written['total']);
		$this->assertNull($written['unit_price']);
		$this->assertSame([EnergyService::NO_PRICE], $written['flags']);
	}

	/**
	 * The unit price is derived from what was paid for how much: 73.50 for 42 litres is 1.750 a
	 * litre, which is 1750 tenths of a cent. A litre and a kilowatt-hour are both a thousand of
	 * what `amount` counts, so a charge derives the same way.
	 */
	public function testTheUnitPriceIsDerivedWhenNotGiven(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel', 'electric']]);
		$uuid = $vehicle->getUuid();

		$this->assertSame(1750, $this->energy->record(self::OWNER, $uuid, $this->fillUp())['unit_price']);
		// 12.34 for 30.5 kWh is 40.459... cents, so 405 tenths.
		$this->assertSame(405, $this->energy->record(self::OWNER, $uuid, $this->fillUp([
			'energy' => 'electric',
			'amount' => 30500,
			'total' => 1234,
		]))['unit_price']);
	}

	/** A unit price the pump stated is kept, even where the total says otherwise. */
	public function testAGivenUnitPriceIsKept(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);

		$written = $this->energy->record(self::OWNER, $vehicle->getUuid(), $this->fillUp(['unit_price' => 1749]));

		$this->assertSame(1749, $written['unit_price']);
	}

	/**
	 * The sheet's VAT is the vehicle's jurisdiction's rate on the day of the fill-up, read at the
	 * offset it was entered at: 2020-12-31 23:30 in Berlin is the 16 % of the German cut, and
	 * 2021-01-01 00:30 in Berlin is 19 %, though UTC is still on the 31st.
	 */
	public function testThePrefillStatesTheJurisdictionsRateOnTheDay(): void {
		$german = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de']);
		$generic = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'jurisdiction' => 'generic']);

		$this->assertSame(1600, $this->energy->prefill(self::OWNER, $german->getUuid(), ['at' => 1609453800, 'off' => 60])['vat_rate']);
		$this->assertSame(1900, $this->energy->prefill(self::OWNER, $german->getUuid(), ['at' => 1609457400, 'off' => 60])['vat_rate']);
		$this->assertNull($this->energy->prefill(self::OWNER, $generic->getUuid(), ['at' => 1750000000, 'off' => 120])['vat_rate']);
	}

	/** A moment the prefill cannot read is a client that did not send one. */
	public function testThePrefillWantsAMoment(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->energy->prefill(self::OWNER, $vehicle->getUuid(), ['off' => 120]);
	}

	/**
	 * The stations this vehicle has filled up at, the latest first, each with the unit price it
	 * last charged for that energy - a station selling diesel and electricity has two prices.
	 */
	public function testThePrefillOffersTheStationsWithTheirLastPrice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel', 'electric']]);
		$uuid = $vehicle->getUuid();
		$this->energy->record(self::OWNER, $uuid, $this->fillUp(['station' => 'Aral Hauptstr.', 'unit_price' => 1750]));
		$this->energy->record(self::OWNER, $uuid, $this->fillUp(['filled_at' => 1750003600, 'station' => 'Shell Ring', 'unit_price' => 1699]));
		$this->energy->record(self::OWNER, $uuid, $this->fillUp(['filled_at' => 1750086400, 'station' => 'Aral Hauptstr.', 'unit_price' => 1799]));
		$this->energy->record(self::OWNER, $uuid, $this->fillUp(['filled_at' => 1750090000, 'station' => 'Aral Hauptstr.', 'energy' => 'electric', 'amount' => 30000, 'total' => 1590]));
		$this->energy->record(self::OWNER, $uuid, $this->fillUp(['filled_at' => 1750100000]));

		$this->assertSame([
			['station' => 'Aral Hauptstr.', 'energy' => 'electric', 'unit_price' => 530],
			['station' => 'Aral Hauptstr.', 'energy' => 'diesel', 'unit_price' => 1799],
			['station' => 'Shell Ring', 'energy' => 'diesel', 'unit_price' => 1699],
		], $this->energy->prefill(self::OWNER, $uuid, ['at' => 1750200000, 'off' => 120])['stations']);
	}

	/** Another vehicle's stations are its own history, not this one's. */
	public function testThePrefillOffersOnlyThisVehiclesStations(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'energy_types' => ['diesel']]);
		$this->energy->record(self::OWNER, $other->getUuid(), $this->fillUp(['station' => 'Shell Ring']));

		$this->assertSame([], $this->energy->prefill(self::OWNER, $mine->getUuid(), ['at' => 1750200000, 'off' => 120])['stations']);
	}

	/**
	 * An edit rewrites the fill-up in place, and its Reading follows it to the new number and the
	 * new moment - the same row, not a second one.
	 */
	public function testAnEditMovesTheFillUpAndItsReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 120450]));
		$before = $this->odometer->list(self::OWNER, $uuid)[0];

		$edited = $this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp([
			'filled_at' => 1750003600,
			'odo' => 120540,
			'total' => '',
		]));

		$this->assertSame($written['uuid'], $edited['uuid']);
		$this->assertSame(1750003600, $edited['filled_at']);
		$this->assertSame([EnergyService::NO_PRICE], $edited['flags']);
		$readings = $this->odometer->list(self::OWNER, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame($before->getUuid(), $readings[0]->getUuid());
		$this->assertSame(120540, $readings[0]->getValue());
		$this->assertSame(1750003600, $readings[0]->getReadAt());
		$this->assertSame(120540, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/**
	 * A counter emptied on edit takes its Reading off the chain, and one added writes a Reading -
	 * each counter on its own chain, each cache restated.
	 */
	public function testAnEditAddsAndRemovesReadingsPerCounter(): void {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'energy_types' => ['diesel'],
		]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 300000]));

		$this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp(['second_odo' => 5120]));

		$readings = $this->odometer->list(self::OWNER, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(OdoReading::SECOND, $readings[0]->getCounter());
		$this->assertSame(5120, $readings[0]->getValue());
		$read = $this->vehicles->find(self::OWNER, $uuid);
		$this->assertNull($read->getOdoValue());
		$this->assertSame(5120, $read->getSecondValue());
	}

	/** A counter emptied and then stated again, at a new number, brings back the same Reading row. */
	public function testACounterStatedAgainReusesItsReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 120450]));
		$first = $this->odometer->list(self::OWNER, $uuid)[0];
		$emptied = $this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp());

		$this->energy->update(self::OWNER, $uuid, $written['uuid'], $emptied['updated_at'], $this->fillUp(['odo' => 120500]));

		$readings = $this->odometer->list(self::OWNER, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame($first->getUuid(), $readings[0]->getUuid());
		$this->assertSame(120500, $readings[0]->getValue());
		$this->assertSame(120500, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** An edit on a token somebody else has moved on from changes nothing. */
	public function testAnEditThatLostTheRaceChangesNothing(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 120450]));
		$this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp(['odo' => 120460]));

		try {
			$this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp(['odo' => 999999]));
			$this->fail('a stale edit was written');
		} catch (StaleUpdateException) {
		}

		$this->assertSame(120460, $this->odometer->list(self::OWNER, $uuid)[0]->getValue());
	}

	/**
	 * A delete soft-deletes the fill-up and every Reading it wrote; undo brings back the same rows,
	 * on the token the delete answered with.
	 */
	public function testDeleteTakesTheReadingsAlongAndUndoBringsThemBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'energy_types' => ['diesel'],
		]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 300000, 'second_odo' => 5120]));

		$deleted = $this->energy->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertNotNull($deleted['deleted_at']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $uuid));
		$read = $this->vehicles->find(self::OWNER, $uuid);
		$this->assertNull($read->getOdoValue());
		$this->assertNull($read->getSecondValue());

		$back = $this->energy->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertNull($back['deleted_at']);
		$this->assertSame(
			[300000, 5120],
			array_map(static fn (OdoReading $reading): int => $reading->getValue(), $this->odometer->list(self::OWNER, $uuid)),
		);
		$read = $this->vehicles->find(self::OWNER, $uuid);
		$this->assertSame(300000, $read->getOdoValue());
		$this->assertSame(5120, $read->getSecondValue());
	}

	/**
	 * A counter an earlier edit emptied stays gone through a delete and its undo: the undo brings
	 * back what the fill-up stated when it was deleted, nothing older.
	 */
	public function testUndoDoesNotBringBackAReadingAnEditRemoved(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$uuid = $vehicle->getUuid();
		$written = $this->energy->record(self::OWNER, $uuid, $this->fillUp(['odo' => 120450]));
		$edited = $this->energy->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->fillUp());
		$deleted = $this->energy->delete(self::OWNER, $uuid, $written['uuid'], $edited['updated_at']);

		$this->energy->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertSame([], $this->odometer->list(self::OWNER, $uuid));
	}

	/** Another vehicle's fill-up is not reached through this one, even by its owner. */
	public function testAFillUpIsReachedOnlyThroughItsOwnVehicle(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']]);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'energy_types' => ['diesel']]);
		$written = $this->energy->record(self::OWNER, $other->getUuid(), $this->fillUp());

		$this->expectException(DoesNotExistException::class);
		$this->energy->delete(self::OWNER, $mine->getUuid(), $written['uuid'], $written['updated_at']);
	}

	/**
	 * A fill-up as the sheet sends it, with what a test does not care about filled in.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function fillUp(array $fields = []): array {
		return $fields + [
			'filled_at' => 1750000000,
			'filled_at_off' => 120,
			'energy' => 'diesel',
			'amount' => 42000,
			'total' => 7350,
			'full_tank' => true,
		];
	}
}
