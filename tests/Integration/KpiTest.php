<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Jurisdiction\De\RateProvider;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The vehicle header's figures for one period, read against the real database.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class KpiTest extends TestCase {
	use CountsQueries;

	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private EnergyService $energy;
	private ExpenseService $expenses;
	private MaintenanceService $maintenance;
	private KpiService $kpis;
	private VehicleService $vehicles;
	private IConfig $config;

	protected function setUp(): void {
		$this->energy = \OCP\Server::get(EnergyService::class);
		$this->expenses = \OCP\Server::get(ExpenseService::class);
		$this->maintenance = \OCP\Server::get(MaintenanceService::class);
		$this->kpis = \OCP\Server::get(KpiService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->config = \OCP\Server::get(IConfig::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = ['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_expenses' => 'created_by'];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
		$this->config->deleteUserValue(self::OWNER, Application::APP_ID, 'grid_factor');
	}

	/**
	 * A truck that also counts engine hours: consumption and cost against its kilometres, and the
	 * hours it ran in the period beside them.
	 */
	public function testATwoCounterTruckStatesConsumptionCostAndHoursForThePeriod(): void {
		$uuid = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'energy_types' => ['diesel'],
			'currency' => 'EUR',
		])->getUuid();
		$this->fill($uuid, 1750000000, 300000, 5100, 200000, 36000);
		$this->fill($uuid, 1750100000, 301000, 5140, 250000, 45000);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => 1750050000, 'done_at_off' => 120, 'title' => 'Oil change', 'cost' => 19000]);

		$kpis = $this->kpis->of(self::OWNER, $uuid, ['from' => 1749900000, 'to' => 1750200000, 'net' => 'false']);

		$this->assertSame([['energy' => 'diesel', 'amount' => 250000, 'distance' => 1000, 'per' => 'km', 'value' => 25.0]], $kpis['consumption']);
		$this->assertNull($kpis['wall_side']);
		$this->assertSame(100000, $kpis['cost']['total']);
		$this->assertSame(10000.0, $kpis['cost']['value']);
		$this->assertSame(40, $kpis['hours']);
	}

	/** A vehicle that counts no engine hours states none, rather than zero of them. */
	public function testAOneCounterVehicleStatesNoHours(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel']])->getUuid();

		$kpis = $this->kpis->of(self::OWNER, $uuid, ['from' => 1749900000, 'to' => 1750200000]);

		$this->assertNull($kpis['hours']);
		$this->assertSame([], $kpis['consumption']);
	}

	/** A period that ends before it starts is not one this route hands out. */
	public function testAPeriodMustRunForwards(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();

		$this->expectException(\InvalidArgumentException::class);
		$this->kpis->of(self::OWNER, $uuid, ['from' => 1750200000, 'to' => 1750200000]);
	}

	/**
	 * The Costs screen's year: twelve months cut at midnight where the person is, each with its
	 * three bands and its categories, and the header's figures for the whole year.
	 */
	public function testAYearOfCostsIsTwelveLocalMonthsAndTheYearsFigures(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'energy_types' => ['diesel'], 'currency' => 'EUR'])->getUuid();
		// 31 January 23:30 UTC is 1 February in Berlin.
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1738366200, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => 10000]);
		$this->maintenance->record(self::OWNER, $uuid, ['done_at' => 1739000000, 'done_at_off' => 60, 'title' => 'Oil change', 'cost' => 19000, 'odo' => 10400]);
		$this->expenses->record(self::OWNER, $uuid, ['spent_at' => 1741600000, 'spent_at_off' => 60, 'category' => 'insurance', 'amount' => 30000]);

		$year = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin', 'net' => 'false']);

		$this->assertCount(12, $year['months']);
		$this->assertSame(1735686000, $year['months'][0]['from']);
		$this->assertSame(1738364400, $year['months'][0]['to']);
		$this->assertSame($year['months'][0]['to'], $year['months'][1]['from']);
		$this->assertSame(1767222000, $year['months'][11]['to']);

		$this->assertNull($year['months'][0]['cost']['total'], 'a month with no rows is not a zero');
		$this->assertSame(6000, $year['months'][1]['cost']['energy']);
		$this->assertSame(19000, $year['months'][1]['cost']['maintenance']);
		$this->assertSame([], $year['months'][1]['cost']['expenses']);
		$this->assertSame([['category' => 'insurance', 'total' => 30000]], $year['months'][2]['cost']['expenses']);
		$this->assertSame(0, $year['months'][2]['cost']['energy']);

		$this->assertSame(55000, $year['year']['cost']['total']);
		$this->assertSame(400, $year['year']['cost']['distance']);
		$this->assertNull($year['year']['hours']);
	}

	/** @dataProvider notAYear */
	public function testAYearNeedsFourDigitsAndAZone(string $year, string $zone): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();

		$this->expectException(\InvalidArgumentException::class);
		$this->kpis->year(self::OWNER, $uuid, $year, ['tz' => $zone]);
	}

	/**
	 * Chrome still reports some zones by their old names, which PHP's default identifier list leaves
	 * out; refusing them would leave the reader with no costs in any year.
	 */
	public function testAYearTakesTheZoneNamesABrowserStillReports(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();

		$year = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Asia/Calcutta']);

		$this->assertSame(1735669800, $year['months'][0]['from'], 'midnight in India, UTC+5:30');
	}

	/**
	 * CO₂ is what was burnt times its factor, and electricity at the grid's. A fuel the table does
	 * not state leaves the figure rather than adding a zero, and says so.
	 */
	public function testAYearStatesItsCo2FromWhatWasBurnt(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de', 'engine' => 'hybrid', 'energy_types' => ['diesel', 'electric', 'cng']])->getUuid();
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1740000000, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000]);
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1741000000, 'filled_at_off' => 60, 'energy' => 'electric', 'amount' => 10000]);

		$co2 = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin'])['co2'];

		// 40 l × 2650 g/l, and 10 kWh × 344 g/kWh.
		$this->assertSame(106000 + 3440, $co2['grams'] ?? null);
		$this->assertFalse($co2['unstated']);
		$this->assertStringStartsWith('https://', $co2['source']);
		// The fuel factors' year beside their source, as the grid's is.
		$this->assertArrayHasKey('year', $co2);
		$this->assertSame((new RateProvider())->emissionSourceYear(), $co2['year']);
		$this->assertSame(344, $co2['grid']['grams'] ?? null);
		$this->assertSame(2025, $co2['grid']['year'] ?? null);

		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1742000000, 'filled_at_off' => 60, 'energy' => 'cng', 'amount' => 30000]);
		$co2 = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin'])['co2'];

		$this->assertSame(106000 + 3440, $co2['grams'] ?? null);
		$this->assertTrue($co2['unstated']);
	}

	/** A person's own grid factor replaces the country's, and cites no source of its own. */
	public function testAPersonsGridFactorReplacesTheCountrys(): void {
		$this->config->setUserValue(self::OWNER, Application::APP_ID, 'grid_factor', '120');
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de', 'energy_types' => ['electric']])->getUuid();
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1741000000, 'filled_at_off' => 60, 'energy' => 'electric', 'amount' => 10000]);

		$co2 = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin'])['co2'];

		$this->assertSame(1200, $co2['grams'] ?? null);
		$this->assertSame(['grams' => 120, 'year' => null, 'source' => null], $co2['grid'] ?? null);
	}

	/** A year with nothing burnt has no figure, and one with no charge names no grid. */
	public function testAYearWithNothingBurntStatesNoCo2(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de'])->getUuid();

		$co2 = $this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin'])['co2'];

		$this->assertNotNull($co2, 'Germany states factors, so the estimate is available');
		$this->assertNull($co2['grams']);
		$this->assertNull($co2['grid']);
	}

	/** A country with no factors has no estimate at all, not a zero (docs/architecture.md). */
	public function testUnderGenericCo2IsUnavailable(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'generic', 'energy_types' => ['diesel']])->getUuid();
		$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1740000000, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000]);

		$this->assertNull($this->kpis->year(self::OWNER, $uuid, '2025', ['tz' => 'Europe/Berlin'])['co2']);
	}

	/**
	 * The Costs screen opens on a vehicle with years behind it as fast as on a new one: thirteen
	 * period edges and a handful of lookups, never a pass over the whole chain.
	 */
	public function testAYearReadsAsManyQueriesForALongHistoryAsForAShortOne(): void {
		$short = $this->priced();
		$this->history($short, 3);
		$long = $this->priced();
		$this->history($long, 3000);

		$this->kpis->year(self::OWNER, $short, '2025', ['tz' => 'Europe/Berlin']);
		$few = self::queriesOf(fn () => $this->kpis->year(self::OWNER, $short, '2025', ['tz' => 'Europe/Berlin']));
		$many = self::queriesOf(fn () => $this->kpis->year(self::OWNER, $long, '2025', ['tz' => 'Europe/Berlin']));

		$this->assertSame($few, $many, '6 000 Readings cost what 6 do');
		// 27: thirteen edges, three tables, the holding's distance, the consumption.
		$this->assertLessThanOrEqual(30, $many);
		$this->assertNotNull($this->kpis->year(self::OWNER, $long, '2025', ['tz' => 'Europe/Berlin'])['year']['cost']['tco']);
	}

	/** A diesel with a price, a residual and a fill-up a month in 2025, so every figure is read. */
	private function priced(): string {
		$uuid = $this->vehicles->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'energy_types' => ['diesel'],
			'currency' => 'EUR',
			'purchase_price' => 2000000,
			'residual_est' => 500000,
		])->getUuid();
		for ($month = 0; $month < 12; $month++) {
			$this->energy->record(self::OWNER, $uuid, ['filled_at' => 1736000000 + $month * 2600000, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => 6000, 'full_tank' => true, 'odo' => 900000 + $month * 1000]);
		}

		return $uuid;
	}

	/**
	 * `$fills` full tanks before 2025, each with its Reading, and as many Readings of the driver's
	 * own between them. Written past the services: 6 000 entries one by one would settle the chain
	 * 6 000 times.
	 */
	private function history(string $uuid, int $fills): void {
		$vehicleId = (int)$this->vehicles->reach(self::OWNER, 'view', $uuid)->getId();
		$energy = \OCP\Server::get(EnergyMapper::class);
		$readings = \OCP\Server::get(OdoReadingMapper::class);
		$db = \OCP\Server::get(IDBConnection::class);
		$db->beginTransaction();
		for ($i = 0; $i < $fills; $i++) {
			$at = 1500000000 + $i * 50000;
			$fill = new Energy();
			$fill->setUuid(\OCP\Server::get(ISecureRandom::class)->generate(32, ISecureRandom::CHAR_LOWER));
			$fill->setVehicleId($vehicleId);
			$fill->setFilledAt($at);
			$fill->setFilledAtOff(60);
			$fill->setEnergy('diesel');
			$fill->setAmount(40000);
			$fill->setTotal(6000);
			$fill->setFullTank(true);
			$fill->setCreatedAt($at);
			$fill->setUpdatedAt($at);
			$fill->setCreatedBy(self::OWNER);
			$energy->insert($fill);
			$readings->insert($this->reading($vehicleId, $at, $i * 200, OdoReading::ENERGY, (int)$fill->getId()));
			$readings->insert($this->reading($vehicleId, $at + 25000, $i * 200 + 100, OdoReading::MANUAL, null));
		}
		$db->commit();
	}

	private function reading(int $vehicleId, int $at, int $value, string $sourceType, ?int $sourceId): OdoReading {
		$reading = new OdoReading();
		$reading->setUuid(\OCP\Server::get(ISecureRandom::class)->generate(32, ISecureRandom::CHAR_LOWER));
		$reading->setVehicleId($vehicleId);
		$reading->setReadAt($at);
		$reading->setReadAtOff(60);
		$reading->setValue($value);
		$reading->setKind(OdoReading::READING);
		$reading->setOrigin(OdometerService::OBSERVED);
		$reading->setFlagged(false);
		$reading->setSourceType($sourceType);
		$reading->setSourceId($sourceId);
		$reading->setCounter(OdoReading::MAIN);
		$reading->setCreatedAt($at);
		$reading->setUpdatedAt($at);
		$reading->setCreatedBy(self::OWNER);

		return $reading;
	}

	/** @return iterable<string, array{string, string}> */
	public static function notAYear(): iterable {
		yield 'a year with a fraction' => ['2025.5', 'Europe/Berlin'];
		yield 'five digits' => ['20250', 'Europe/Berlin'];
		yield 'no zone' => ['2025', ''];
		yield 'a zone that is not one' => ['2025', 'Europe/Atlantis'];
	}

	private function fill(string $uuid, int $at, int $odo, int $hours, int $amount, int $total): void {
		$this->energy->record(self::OWNER, $uuid, [
			'filled_at' => $at,
			'filled_at_off' => 120,
			'energy' => 'diesel',
			'amount' => $amount,
			'total' => $total,
			'full_tank' => true,
			'odo' => $odo,
			'second_odo' => $hours,
		]);
	}
}
