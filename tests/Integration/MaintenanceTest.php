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
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A Maintenance Record against the real database: the row, the Readings its counters write, and
 * what the sheet is prefilled with. The counter rules are the fill-up's (EnergyTest).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class MaintenanceTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private MaintenanceService $maintenance;
	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->maintenance = $container->get(MaintenanceService::class);
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
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_maintenance' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/** Rule 5 for maintenance: the counter it was given is an Observed Reading at `done_at`. */
	public function testARecordWithACounterWritesAnObservedReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['odo' => 120450]));

		$this->assertSame('Oil change', $written['title']);
		$this->assertSame(18990, $written['cost']);
		$readings = $this->odometer->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(1, $readings);
		$this->assertSame(120450, $readings[0]->getValue());
		$this->assertSame(1750000000, $readings[0]->getReadAt());
		$this->assertSame(120, $readings[0]->getReadAtOff());
		$this->assertSame(OdometerService::OBSERVED, $readings[0]->getOrigin());
		$this->assertSame(OdoReading::MAINTENANCE, $readings[0]->getSourceType());
		$this->assertSame(120450, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/** A counter is optional and never prefilled: without one the odometer is left as it was. */
	public function testARecordWithoutACounterWritesNoReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work());

		$this->assertNull($written['odo']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
	}

	/** Rule 4: a truck's hours at the workshop are a Reading on the hour chain, cached on its own. */
	public function testEachCounterGivenIsAReadingOnItsOwnChain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'vehicle_type' => 'truck', 'second_unit' => 'h']);

		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['odo' => 300000, 'second_odo' => 5120]));

		$chains = [];
		foreach ($this->odometer->list(self::OWNER, $vehicle->getUuid()) as $reading) {
			$chains[$reading->getCounter()] = $reading->getValue();
		}
		$this->assertSame([OdoReading::MAIN => 300000, OdoReading::SECOND => 5120], $chains);
		$this->assertSame(5120, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getSecondValue());
	}

	/** An hour counter on a vehicle that counts none is a field for a chain that is not there. */
	public function testAnHourCounterOnAVehicleWithoutOneIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['second_odo' => 5120]));
	}

	/**
	 * The title is the one field the sheet requires; everything else may be left out, and a
	 * record without a cost is still saved.
	 */
	public function testOnlyTheTitleIsRequired(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Wipers']);

		$this->assertNull($written['cost']);
		$this->assertNull($written['type']);
		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['title' => ' ']));
	}

	/** Every field the sheet offers is stored as given. */
	public function testTheFieldsAreStoredAsGiven(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work([
			'type' => 'service',
			'vendor' => 'ATU Nord',
			'vat_rate' => 1900,
			'notes' => 'Filter too',
		]));

		$this->assertSame('service', $written['type']);
		$this->assertSame('ATU Nord', $written['vendor']);
		$this->assertSame(1900, $written['vat_rate']);
		$this->assertSame('Filter too', $written['notes']);
	}

	/** A type outside the five is a client that did not use the sheet's list. */
	public function testAnUnknownTypeIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->record(self::OWNER, $vehicle->getUuid(), $this->work(['type' => 'wash']));
	}

	/** The VAT is the jurisdiction's rate on the day the work was done, as for a fill-up. */
	public function testThePrefillStatesTheJurisdictionsRateOnTheDay(): void {
		$german = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de']);
		$generic = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'jurisdiction' => 'generic']);

		$this->assertSame(1600, $this->maintenance->prefill(self::OWNER, $german->getUuid(), ['at' => 1609453800, 'off' => 60])['vat_rate']);
		$this->assertNull($this->maintenance->prefill(self::OWNER, $generic->getUuid(), ['at' => 1750000000, 'off' => 120])['vat_rate']);
	}

	/** A moment the prefill cannot read is a client that did not send one. */
	public function testThePrefillWantsAMoment(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->maintenance->prefill(self::OWNER, $vehicle->getUuid(), ['at' => 1750000000]);
	}

	/** The vendors this vehicle has used, each once, the latest first; another vehicle's are not. */
	public function testThePrefillOffersThisVehiclesVendorsLatestFirst(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['vendor' => 'ATU Nord']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750086400, 'vendor' => 'Reifen Müller']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750172800, 'vendor' => 'ATU Nord']));
		$this->maintenance->record(self::OWNER, $mine->getUuid(), $this->work(['done_at' => 1750259200]));
		$this->maintenance->record(self::OWNER, $other->getUuid(), $this->work(['vendor' => 'Bosch Service']));

		$this->assertSame(
			['ATU Nord', 'Reifen Müller'],
			$this->maintenance->prefill(self::OWNER, $mine->getUuid(), ['at' => 1750300000, 'off' => 120])['vendors'],
		);
	}

	/**
	 * An edit rewrites the record in place and its Reading follows it; the counter rules are the
	 * fill-up's (EnergyTest), so one case proves the record reaches them.
	 */
	public function testAnEditMovesTheRecordAndItsReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['odo' => 120450]));

		$edited = $this->maintenance->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->work([
			'title' => 'Oil and filter',
			'done_at' => 1750003600,
			'odo' => 120540,
		]));

		$this->assertSame('Oil and filter', $edited['title']);
		$readings = $this->odometer->list(self::OWNER, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(120540, $readings[0]->getValue());
		$this->assertSame(1750003600, $readings[0]->getReadAt());
		$this->assertSame(120540, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** A delete takes the record's Reading with it, and undo brings both back. */
	public function testDeleteTakesTheReadingAlongAndUndoBringsItBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work(['odo' => 120450]));

		$deleted = $this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->assertNotNull($deleted['deleted_at']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $uuid));
		$this->assertNull($this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		$back = $this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);

		$this->assertNull($back['deleted_at']);
		$this->assertCount(1, $this->odometer->list(self::OWNER, $uuid));
		$this->assertSame(120450, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** An undo on a token that is not the delete's changes nothing. */
	public function testAnUndoOnAStaleTokenIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->maintenance->record(self::OWNER, $uuid, $this->work());
		$this->maintenance->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);

		$this->expectException(StaleUpdateException::class);
		$this->maintenance->restore(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
	}

	/**
	 * A Maintenance Record as the sheet sends it, with what a test does not care about filled in.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function work(array $fields = []): array {
		return $fields + [
			'done_at' => 1750000000,
			'done_at_off' => 120,
			'title' => 'Oil change',
			'cost' => 18990,
		];
	}
}
