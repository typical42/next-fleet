<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\StatisticsService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The Statistics screen's figures for one vehicle or all, over a range the reader picks, against
 * the real database and real accounts (docs/api.md#statistics).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 *
 * @psalm-import-type NextFleetPerDistance from \OCA\NextFleet\ResponseDefinitions as PerDistance
 */
class StatisticsTest extends TestCase {
	use Accounts;
	use CountsQueries;

	private const OWNER = 'nextfleet-test-stats-owner';
	private const DRIVER = 'nextfleet-test-stats-driver';
	/** Made and deleted by the one case that erases a driver. */
	private const LEAVER = 'nextfleet-test-stats-leaver';
	private const ACCOUNTS = [self::OWNER, self::DRIVER];

	private const TABLES = ['fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_access'];

	private VehicleService $vehicles;
	private StatisticsService $statistics;
	/** @var list<int> */
	private array $vehicleIds = [];
	/** Where the counter stands after the last trip(). */
	private int $odo = 1000;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([...self::ACCOUNTS, self::LEAVER]);
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([...self::ACCOUNTS, self::LEAVER]);
	}

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->statistics = \OCP\Server::get(StatisticsService::class);
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	protected function tearDown(): void {
		if ($this->vehicleIds === []) {
			return;
		}
		$db = \OCP\Server::get(IDBConnection::class);
		foreach ([...self::TABLES, 'fleet_vehicles'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($table === 'fleet_vehicles' ? 'id' : 'vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_audit')->where($qb->expr()->in('created_by', $qb->createNamedParameter(self::ACCOUNTS, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		$this->vehicleIds = [];
	}

	/**
	 * A calendar year of one vehicle: twelve months cut at midnight where the reader is, each with
	 * its costs by category, and the range's sum beside them.
	 */
	public function testACalendarYearIsTwelveLocalMonthsOfCostsByCategory(): void {
		$vehicle = $this->vehicle('EUR');
		// 31 January 23:30 UTC is 1 February in Berlin.
		$this->fill($vehicle, 1738366200, 6000);
		\OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $vehicle->getUuid(), ['done_at' => 1739000000, 'done_at_off' => 60, 'title' => 'Oil change', 'cost' => 19000]);
		$this->expense($vehicle, 1741600000, 30000, 'insurance');

		$read = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);

		$this->assertSame('month', $read['bucket']);
		$this->assertCount(12, $read['buckets']);
		$this->assertSame(['from' => 1735686000, 'to' => 1738364400], $read['buckets'][0]);
		$this->assertSame(1767222000, $read['buckets'][11]['to']);
		$this->assertSame([1735686000, 1767222000], [$read['from'], $read['to']]);

		$this->assertCount(1, $read['costs']);
		[$euro] = $read['costs'];
		$this->assertSame('EUR', $euro['currency']);
		$this->assertCount(12, $euro['buckets']);
		$this->assertNull($euro['buckets'][0]['total'], 'a month with no rows is not a zero');
		$this->assertSame(6000, $euro['buckets'][1]['energy']);
		$this->assertSame(19000, $euro['buckets'][1]['maintenance']);
		$this->assertSame([], $euro['buckets'][1]['expenses']);
		$this->assertSame([['category' => 'insurance', 'total' => 30000]], $euro['buckets'][2]['expenses']);
		$this->assertSame(55000, $euro['range']['total']);
		$this->assertSame([['uuid' => $vehicle->getUuid(), 'costs' => true, 'drivers' => true]], $read['vehicles']);
	}

	/**
	 * Every vehicle the reader sees, their costs added up in one series per currency. A vehicle
	 * whose owner keeps its costs from the reader is counted and says so, with no sum, and so is
	 * one with no currency.
	 */
	public function testAllVehiclesAddUpByCurrencyAndOnlyWhereTheReaderSeesTheCosts(): void {
		$shared = $this->vehicle('EUR', plate: 'S-TA 1');
		$kept = $this->vehicle('EUR', shared: false, plate: 'S-TA 2');
		$swiss = $this->vehicle('CHF', plate: 'S-TA 3');
		$bare = $this->vehicle(null, plate: 'S-TA 4');
		foreach ([$shared, $kept, $swiss, $bare] as $vehicle) {
			$this->fill($vehicle, 1750000000, 5000);
		}
		$this->expense($kept, 1750000000, 700, 'parking');
		$range = ['from' => '2025-06-01', 'to' => '2025-07-01', 'tz' => 'Europe/Berlin'];

		$owner = $this->statistics->of(self::OWNER, $range);
		$this->assertSame(['CHF', 'EUR'], array_column($owner['costs'], 'currency'));
		$this->assertSame(5000, $owner['costs'][0]['range']['total']);
		$this->assertSame(10700, $owner['costs'][1]['range']['total']);
		$this->assertSame([['category' => 'parking', 'total' => 700]], $owner['costs'][1]['buckets'][0]['expenses']);

		$driver = $this->statistics->of(self::DRIVER, $range);
		$this->assertSame(['CHF', 'EUR'], array_column($driver['costs'], 'currency'));
		$this->assertSame(5000, $driver['costs'][1]['range']['total'], 'the kept vehicle adds nothing');
		$this->assertSame([], $driver['costs'][1]['range']['expenses']);
		$this->assertSame([
			['uuid' => $shared->getUuid(), 'costs' => true, 'drivers' => false],
			['uuid' => $kept->getUuid(), 'costs' => false, 'drivers' => false],
			['uuid' => $swiss->getUuid(), 'costs' => true, 'drivers' => false],
			['uuid' => $bare->getUuid(), 'costs' => true, 'drivers' => false],
		], $driver['vehicles']);
	}

	/**
	 * One vehicle's cost per 100 km in each bucket and over the range: its costs over the distance
	 * its counter moved. A month the counter stood still has no figure, whatever it cost, and the
	 * range rates only the months that have one: 6.25 and 8.75 make 7.50, not 11.25.
	 */
	public function testOneVehicleCostsPerDistanceInEachBucketAndOverTheRange(): void {
		$vehicle = $this->vehicle('EUR');
		$this->odoFill($vehicle, 1736935200, 10000, 40000, 6000);
		$this->odoFill($vehicle, 1739613600, 10800, 40000, 5000);
		$this->odoFill($vehicle, 1742032800, 11600, 48000, 7000);

		$read = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);

		$this->assertCount(1, $read['per_distance']);
		[$euro] = $read['per_distance'];
		$this->assertSame(['EUR', 'km'], [$euro['currency'], $euro['per']]);
		$this->assertCount(12, $euro['buckets']);
		$this->assertSame(['total' => 6000, 'distance' => null, 'value' => null], $euro['buckets'][0]);
		$this->assertSame(['total' => 5000, 'distance' => 800, 'value' => 625.0], $euro['buckets'][1]);
		$this->assertSame(['total' => 7000, 'distance' => 800, 'value' => 875.0], $euro['buckets'][2]);
		$this->assertSame(['total' => null, 'distance' => null, 'value' => null], $euro['buckets'][3]);
		$this->assertSame(['total' => 18000, 'distance' => 1600, 'value' => 750.0], $euro['range']);
		$this->assertSame([['uuid' => $vehicle->getUuid(), 'range' => $euro['range']]], $euro['vehicles']);
		self::assertBetweenTheBuckets($euro);
	}

	/**
	 * Every vehicle: one rate per currency and unit, never across them, from the vehicles whose
	 * costs the reader sees. The fleet's rate counts the vehicles that moved; its sums count them
	 * all. Each vehicle's range stands beside it.
	 */
	public function testAllVehiclesCostPerDistanceByCurrencyAndUnit(): void {
		$moved = $this->vehicle('EUR', plate: 'S-TA 1');
		$this->odoFill($moved, 1749000000, 1000, 40000, 5000);
		$this->odoFill($moved, 1750000000, 1500, 40000, 4000);
		$standing = $this->vehicle('EUR', plate: 'S-TA 2');
		$this->expense($standing, 1750000000, 3000);
		$hours = $this->vehicle('EUR', plate: 'S-TA 3', odoUnit: 'h');
		$this->odoFill($hours, 1749000000, 100, 40000, 2000);
		$this->odoFill($hours, 1750000000, 110, 40000, 2000);
		$kept = $this->vehicle('EUR', shared: false, plate: 'S-TA 4');
		$this->odoFill($kept, 1749000000, 1000, 40000, 5000);
		$this->odoFill($kept, 1750000000, 1100, 40000, 9000);

		$read = $this->statistics->of(self::DRIVER, ['from' => '2025-06-01', 'to' => '2025-07-01', 'tz' => 'Europe/Berlin']);

		$this->assertSame([['EUR', 'h'], ['EUR', 'km']], array_map(static fn (array $one): array => [$one['currency'], $one['per']], $read['per_distance']));
		[$perHour, $perKm] = $read['per_distance'];
		$this->assertSame(['total' => 4000, 'distance' => 10, 'value' => 400.0], $perHour['range']);
		$this->assertSame(['total' => 12000, 'distance' => 500, 'value' => 1800.0], $perKm['range']);
		$this->assertSame($perKm['range'], $perKm['buckets'][0]);
		$this->assertSame([
			['uuid' => $moved->getUuid(), 'range' => ['total' => 9000, 'distance' => 500, 'value' => 1800.0]],
			['uuid' => $standing->getUuid(), 'range' => ['total' => 3000, 'distance' => null, 'value' => null]],
		], $perKm['vehicles']);
	}

	/**
	 * A month driven between fill-ups rates 0, and the range counts its distance: 70 € over
	 * 1,600 km is 4.38 €/100 km, between February's 0 and March's 8.75.
	 */
	public function testAMonthDrivenWithoutACostRatesZero(): void {
		$vehicle = $this->vehicle('EUR');
		$this->odoFill($vehicle, 1736935200, 10000, 40000, 6000);
		$this->reading($vehicle, 1739613600, 10800);
		$this->odoFill($vehicle, 1742032800, 11600, 80000, 7000);

		$read = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2025-04-01', 'tz' => 'Europe/Berlin']);

		[$euro] = $read['per_distance'];
		$this->assertSame(['total' => null, 'distance' => 800, 'value' => 0.0], $euro['buckets'][1]);
		$this->assertSame(['total' => 13000, 'distance' => 1600, 'value' => 437.5], $euro['range']);
		self::assertBetweenTheBuckets($euro);
	}

	/**
	 * The fleet's range is the vehicles' ranges added up: 120 € over 1,600 km and 20 € over
	 * 800 km make 5.83 €/100 km. The second vehicle's February moved without a cost.
	 */
	public function testTheFleetsRangeRatesTheVehiclesBuckets(): void {
		$first = $this->vehicle('EUR', plate: 'S-TA 1');
		$this->odoFill($first, 1736935200, 10000, 40000, 6000);
		$this->odoFill($first, 1739613600, 10800, 40000, 5000);
		$this->odoFill($first, 1742032800, 11600, 48000, 7000);
		$second = $this->vehicle('EUR', plate: 'S-TA 2');
		$this->odoFill($second, 1736935200, 600, 40000, 4000);
		$this->reading($second, 1739613600, 1000);
		$this->odoFill($second, 1742032800, 1400, 20000, 2000);

		$read = $this->statistics->of(self::OWNER, ['from' => '2025-01-01', 'to' => '2025-04-01', 'tz' => 'Europe/Berlin']);

		[$euro] = $read['per_distance'];
		$this->assertSame([24000, 2400], [$euro['range']['total'], $euro['range']['distance']]);
		$this->assertEqualsWithDelta(14000 / 24, $euro['range']['value'], 0.001);
		$this->assertSame(['total' => 6000, 'distance' => 800, 'value' => 250.0], $euro['vehicles'][1]['range']);
		self::assertBetweenTheBuckets($euro);
	}

	/**
	 * The year route rates a year as Statistics rates it read by month: a month that moved
	 * without a cost counts at 0, a cost where the counter stood still does not count.
	 */
	public function testTheYearRouteRatesAYearAsStatisticsDoes(): void {
		$vehicle = $this->vehicle('EUR');
		$this->odoFill($vehicle, 1736935200, 10000, 40000, 6000);
		$this->reading($vehicle, 1739613600, 10800);
		$this->odoFill($vehicle, 1742032800, 11600, 80000, 7000);
		$this->expense($vehicle, 1750000000, 30000, 'insurance');

		$statistics = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);
		$year = \OCP\Server::get(KpiService::class)->year(self::OWNER, $vehicle->getUuid(), '2025', ['tz' => 'Europe/Berlin']);

		$this->assertSame(437.5, $statistics['per_distance'][0]['range']['value']);
		$this->assertSame(437.5, $year['year']['cost']['value']);
	}

	/**
	 * Two years of months read as many queries as three months: each vehicle's counter and
	 * fill-ups are read once, and the bucket edges found among them. Neither engine hours nor the
	 * TCO is asked, though the vehicle has both.
	 */
	public function testTheQueriesDoNotGrowWithTheBuckets(): void {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => 'S-TA 1', 'jurisdiction' => 'de', 'currency' => 'EUR', 'energy_types' => ['diesel'],
			'second_unit' => 'h', 'purchase_price' => 2000000, 'residual_est' => 500000,
		]);
		$this->vehicleIds[] = (int)$vehicle->getId();
		for ($month = 0; $month < 24; $month++) {
			$this->odoFill($vehicle, 1705000000 + $month * 2629800, 10000 + $month * 1000, 40000, 6000);
		}
		$range = ['vehicle' => $vehicle->getUuid(), 'tz' => 'Europe/Berlin'];

		$this->statistics->of(self::OWNER, $range + ['from' => '2024-01-01', 'to' => '2024-04-01']);
		$few = self::queriesOf(fn () => $this->statistics->of(self::OWNER, $range + ['from' => '2024-01-01', 'to' => '2024-04-01']));
		$many = self::queriesOf(fn () => $this->statistics->of(self::OWNER, $range + ['from' => '2024-01-01', 'to' => '2026-01-01']));

		$this->assertSame($few, $many, '24 buckets cost what 3 do');
		// 10 for one vehicle: a query or two per table, none per bucket.
		$this->assertLessThanOrEqual(10, $many);
	}

	/**
	 * One vehicle's consumption, full tank to full tank: each segment that closes in the range, and
	 * their average weighted by distance. No cost right is needed; litres are not money.
	 */
	public function testOneVehicleConsumesSegmentBySegment(): void {
		$vehicle = $this->vehicle('EUR', shared: false);
		$this->odoFill($vehicle, 1733000000, 9000, 30000, 4000);
		$this->odoFill($vehicle, 1736935200, 10000, 40000, 6000);
		$this->odoFill($vehicle, 1739613600, 10800, 40000, 5000);
		$this->odoFill($vehicle, 1742032800, 11600, 48000, 7000);

		$read = $this->statistics->of(self::DRIVER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-02-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);

		$this->assertSame([], $read['per_distance'], 'the costs are kept from the driver');
		$this->assertCount(1, $read['consumption']);
		[$diesel] = $read['consumption'];
		$segments = $diesel['segments'];
		unset($diesel['segments']);
		$this->assertSame(['uuid' => $vehicle->getUuid(), 'energy' => 'diesel', 'per' => 'km', 'amount' => 88000, 'distance' => 1600, 'value' => 5.5], $diesel);
		$this->assertSame([[1739613600, 5.0], [1742032800, 6.0]], array_map(static fn (array $segment): array => [$segment['filled_at'], $segment['value']], (array)$segments));
	}

	/** Every vehicle: each one's average per energy, without its segments. */
	public function testAllVehiclesConsumeOnAverage(): void {
		$first = $this->vehicle('EUR', plate: 'S-TA 1');
		$this->odoFill($first, 1749000000, 1000, 40000, 5000);
		$this->odoFill($first, 1750000000, 1500, 40000, 4000);
		$second = $this->vehicle('EUR', plate: 'S-TA 2');
		$this->odoFill($second, 1749000000, 1000, 40000, 5000);
		$this->odoFill($second, 1750000000, 1800, 60000, 4000);
		$this->vehicle('EUR', plate: 'S-TA 3');

		$read = $this->statistics->of(self::OWNER, ['from' => '2025-06-01', 'to' => '2025-07-01', 'tz' => 'Europe/Berlin']);

		$this->assertSame([
			['uuid' => $first->getUuid(), 'energy' => 'diesel', 'per' => 'km', 'amount' => 40000, 'distance' => 500, 'value' => 8.0, 'segments' => null],
			['uuid' => $second->getUuid(), 'energy' => 'diesel', 'per' => 'km', 'amount' => 60000, 'distance' => 800, 'value' => 7.5, 'segments' => null],
		], $read['consumption']);
	}

	/**
	 * One vehicle's trips by category in each bucket they set off in, and over the range. A voided
	 * trip was not driven; a trip without a distance is counted with none.
	 */
	public function testOneVehicleDrivesByTripCategory(): void {
		$vehicle = $this->vehicle('EUR');
		// 31 January 23:30 UTC is 1 February in Berlin.
		$this->trip($vehicle, self::OWNER, 1738366200, 120, Trip::BUSINESS);
		$this->trip($vehicle, self::DRIVER, 1739000000, 30, Trip::PRIVATE);
		$this->trip($vehicle, self::DRIVER, 1739100000, 15, Trip::COMMUTE);
		$this->trip($vehicle, self::DRIVER, 1741600000, null, Trip::PRIVATE);
		$voided = $this->trip($vehicle, self::OWNER, 1741700000, 500, Trip::BUSINESS);
		\OCP\Server::get(TripService::class)->delete(self::OWNER, $vehicle->getUuid(), $voided->getUuid(), $voided->getUpdatedAt());

		$read = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);

		$this->assertCount(1, $read['distance']);
		[$km] = $read['distance'];
		$this->assertSame('km', $km['per']);
		$this->assertCount(12, $km['buckets']);
		$this->assertSame(['trips' => 0, 'business' => 0, 'private' => 0, 'commute' => 0], $km['buckets'][0]);
		$this->assertSame(['trips' => 3, 'business' => 120, 'private' => 30, 'commute' => 15], $km['buckets'][1]);
		$this->assertSame(['trips' => 1, 'business' => 0, 'private' => 0, 'commute' => 0], $km['buckets'][2]);
		$this->assertSame(['trips' => 4, 'business' => 120, 'private' => 30, 'commute' => 15], $km['range']);
	}

	/**
	 * Each driver's trips, distance and business distance over the range, by who entered the trip.
	 * The owner sees every driver's; a driver sees only their own, and is told so. Nobody drove a
	 * Reconciliation Trip, so it is nobody's.
	 */
	public function testTheOwnerSeesEveryDriverAndADriverOnlyThemselves(): void {
		$vehicle = $this->vehicle('EUR');
		$this->trip($vehicle, self::OWNER, 1739000000, 100, Trip::BUSINESS);
		$this->trip($vehicle, self::DRIVER, 1739100000, 30, Trip::PRIVATE);
		$this->trip($vehicle, self::DRIVER, 1739200000, 20, Trip::BUSINESS);
		$gap = new Trip();
		$gap->setVehicleId((int)$vehicle->getId());
		$gap->setCreatedBy(self::OWNER);
		$gap->setCategory(Trip::PRIVATE);
		$gap->setStartedAt(1739300000);
		$gap->setEndedAt(1739303600);
		$gap->setDistance(40);
		$gap->setReconciled(true);
		\OCP\Server::get(TripMapper::class)->insert($gap);
		$range = ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin'];
		$driver = ['user_id' => self::DRIVER, 'name' => self::DRIVER, 'trips' => 2, 'distance' => 50, 'business' => 20];

		$owner = $this->statistics->of(self::OWNER, $range);
		$this->assertSame([['per' => 'km', 'drivers' => [
			$driver,
			['user_id' => self::OWNER, 'name' => self::OWNER, 'trips' => 1, 'distance' => 100, 'business' => 100],
		]]], $owner['by_driver']);
		$this->assertTrue($owner['vehicles'][0]['drivers']);
		$this->assertSame(['trips' => 3, 'business' => 120, 'private' => 30, 'commute' => 0], $owner['distance'][0]['range'], 'nor is it a trip, so the drivers add up to the categories');

		$own = $this->statistics->of(self::DRIVER, $range);
		$this->assertSame([['per' => 'km', 'drivers' => [$driver]]], $own['by_driver']);
		$this->assertFalse($own['vehicles'][0]['drivers']);
	}

	/**
	 * A driver whose account is gone stays in the figures under the pseudonym their trips now
	 * carry (docs/adr/0008-erasing-a-driver-pseudonymises.md), never under their old name.
	 */
	public function testAnErasedDriverCountsUnderTheirPseudonym(): void {
		$vehicle = $this->vehicle('EUR');
		$users = \OCP\Server::get(IUserManager::class);
		$users->createUser(self::LEAVER, bin2hex(random_bytes(16)));
		\OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::LEAVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->trip($vehicle, self::LEAVER, 1739000000, 70, Trip::BUSINESS);
		$users->get(self::LEAVER)?->delete();

		$read = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin']);

		[$erased] = $read['by_driver'][0]['drivers'];
		// The trail of the trip is the pseudonym's now, which tearDown() does not know.
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('fleet_audit')->where($qb->expr()->eq('created_by', $qb->createNamedParameter($erased['user_id'])))->executeStatement();
		$this->assertMatchesRegularExpression('/^erased:[a-z0-9]{20}$/', $erased['user_id']);
		$this->assertSame($erased['user_id'], $erased['name']);
		$this->assertSame(70, $erased['distance']);
	}

	/** All of it starts at the first trip too: distance needs no price. */
	public function testAllOfItStartsAtTheFirstTrip(): void {
		$vehicle = $this->vehicle('EUR');
		// 2023-12-31 23:30 UTC is already 2024 in Berlin.
		$this->trip($vehicle, self::OWNER, 1704065400, 10, Trip::PRIVATE);

		$all = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'tz' => 'Europe/Berlin']);

		$this->assertSame(1704063600, $all['from'], 'midnight of 1 January 2024 in Berlin');
		$this->assertSame(10, $all['distance'][0]['buckets'][0]['private']);
	}

	/**
	 * Months up to 24 of them; a longer range is cut into calendar years, the first and the last
	 * cut to the range.
	 */
	public function testARangeBeyondTwoYearsIsCutIntoYears(): void {
		$vehicle = $this->vehicle('EUR');

		$two = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2024-01-01', 'to' => '2026-01-01', 'tz' => 'UTC']);
		$this->assertSame('month', $two['bucket']);
		$this->assertCount(24, $two['buckets']);

		$long = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'from' => '2022-07-15', 'to' => '2025-03-01', 'tz' => 'UTC']);
		$this->assertSame('year', $long['bucket']);
		$this->assertSame([
			['from' => 1657843200, 'to' => 1672531200],
			['from' => 1672531200, 'to' => 1704067200],
			['from' => 1704067200, 'to' => 1735689600],
			['from' => 1735689600, 'to' => 1740787200],
		], $long['buckets']);
	}

	/**
	 * All of it, with neither end named: a year each, from the year of the first entry on any of
	 * the vehicles to the reader's current one, side by side.
	 */
	public function testAllOfItIsAYearEachFromTheFirstEntry(): void {
		$vehicle = $this->vehicle('EUR');
		// 2023-12-31 23:30 UTC is already 2024 in Berlin.
		$this->expense($vehicle, 1704065400, 1000);
		$this->fill($vehicle, 1750000000, 5000);
		$thisYear = (int)(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y');

		$all = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'tz' => 'Europe/Berlin']);

		$this->assertSame('year', $all['bucket']);
		$this->assertCount($thisYear - 2024 + 1, $all['buckets']);
		$this->assertSame(1704063600, $all['from'], 'midnight of 1 January 2024 in Berlin');
		$this->assertSame((new \DateTimeImmutable(($thisYear + 1) . '-01-01', new \DateTimeZone('Europe/Berlin')))->getTimestamp(), $all['to']);
		$this->assertSame(1000, $all['costs'][0]['buckets'][0]['total']);
		$this->assertSame(6000, $all['costs'][0]['range']['total']);
	}

	/**
	 * All of it starts at no expense the reader may not see: its year would tell it is there. Their
	 * own counts, as they see it.
	 */
	public function testAllOfItStartsAtNoExpenseKeptFromTheReader(): void {
		$vehicle = $this->vehicle('EUR', shared: false);
		$this->expense($vehicle, 1654041600, 1000);
		$this->expense($vehicle, 1685620800, 500, by: self::DRIVER);
		$this->fill($vehicle, 1750000000, 5000);
		$range = ['vehicle' => $vehicle->getUuid(), 'tz' => 'Europe/Berlin'];

		$this->assertSame(1640991600, $this->statistics->of(self::OWNER, $range)['from'], 'midnight of 1 January 2022 in Berlin');
		$this->assertSame(1672527600, $this->statistics->of(self::DRIVER, $range)['from'], 'midnight of 1 January 2023 in Berlin');
	}

	/** All of it starts no later than this year, though every entry is dated in a later one. */
	public function testAllOfItStartsThisYearBeforeAnEntryAhead(): void {
		$vehicle = $this->vehicle('EUR');
		$zone = new \DateTimeZone('Europe/Berlin');
		$thisYear = (int)(new \DateTimeImmutable('now', $zone))->format('Y');
		$this->expense($vehicle, (new \DateTimeImmutable(($thisYear + 1) . '-01-01', $zone))->getTimestamp(), 30000, 'insurance');

		$all = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'tz' => 'Europe/Berlin']);

		$this->assertSame((new \DateTimeImmutable($thisYear . '-01-01', $zone))->getTimestamp(), $all['from']);
		$this->assertCount(1, $all['buckets']);
	}

	/** A fleet with nothing in it yet has this year, empty. */
	public function testAllOfNothingIsThisYear(): void {
		$vehicle = $this->vehicle('EUR');

		$all = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid(), 'tz' => 'UTC']);

		$this->assertSame('year', $all['bucket']);
		$this->assertCount(1, $all['buckets']);
		$this->assertNull($all['costs'][0]['range']['total']);
	}

	/**
	 * One vehicle's range states its CO₂. All vehicles state none: each country cites its own
	 * factors, and one figure could not name them.
	 */
	public function testOneVehicleStatesItsCo2AndAllVehiclesNone(): void {
		$vehicle = $this->vehicle('EUR');
		$this->fill($vehicle, 1750000000, 5000);
		$range = ['from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin'];

		$one = $this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid()] + $range);
		$this->assertNotNull($one['co2']);
		$this->assertGreaterThan(0, $one['co2']['grams']);

		$this->assertNull($this->statistics->of(self::OWNER, $range)['co2']);
	}

	/**
	 * @dataProvider notARange
	 * @param array<string, string> $fields
	 */
	public function testARangeIsTwoDaysInOrderOrNoneAndAZone(array $fields): void {
		$vehicle = $this->vehicle('EUR');

		$this->expectException(\InvalidArgumentException::class);
		$this->statistics->of(self::OWNER, ['vehicle' => $vehicle->getUuid()] + $fields);
	}

	/** @return array<string, array{array<string, string>}> */
	public static function notARange(): array {
		return [
			'no zone' => [['from' => '2025-01-01', 'to' => '2026-01-01']],
			'one end alone' => [['from' => '2025-01-01', 'tz' => 'UTC']],
			'backwards' => [['from' => '2025-01-01', 'to' => '2025-01-01', 'tz' => 'UTC']],
			'not a day' => [['from' => '2025-13-01', 'to' => '2026-01-01', 'tz' => 'UTC']],
			'a century and more' => [['from' => '1900-01-01', 'to' => '2026-01-01', 'tz' => 'UTC']],
		];
	}

	/** A diesel the owner prices in `$currency`, with a driver. */
	private function vehicle(?string $currency, bool $shared = true, string $plate = 'S-TA 1', string $odoUnit = 'km'): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, [
			'plate' => $plate, 'jurisdiction' => 'de', 'currency' => $currency, 'energy_types' => ['diesel'], 'costs_shared' => $shared, 'odo_unit' => $odoUnit,
		]);
		$this->vehicleIds[] = (int)$vehicle->getId();
		\OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);

		return $vehicle;
	}

	private function fill(Vehicle $vehicle, int $at, int $total): void {
		\OCP\Server::get(EnergyService::class)->record(self::OWNER, $vehicle->getUuid(), ['filled_at' => $at, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 40000, 'total' => $total]);
	}

	/** A full tank of `$amount` millilitres at the counter's `$odo`. */
	private function odoFill(Vehicle $vehicle, int $at, int $odo, int $amount, int $total): void {
		\OCP\Server::get(EnergyService::class)->record(self::OWNER, $vehicle->getUuid(), ['filled_at' => $at, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => $amount, 'total' => $total, 'full_tank' => true, 'odo' => $odo]);
	}

	/**
	 * An hour's trip of `$distance` km set off at `$at`, read off the counter where the last one
	 * left it. Without a distance only the end is read, which says how far the counter stands and
	 * not how far the trip went.
	 */
	private function trip(Vehicle $vehicle, string $userId, int $at, ?int $distance, string $category): Trip {
		$start = $this->odo;
		$this->odo += $distance ?? 0;

		return \OCP\Server::get(TripService::class)->record($userId, $vehicle->getUuid(), [
			'started_at' => $at, 'started_at_off' => 60, 'ended_at' => $at + 3600, 'ended_at_off' => 60,
			'start_odo' => $distance === null ? null : $start, 'end_odo' => $this->odo, 'category' => $category, 'partner' => 'ACME',
		]);
	}

	/** A Reading of the counter alone, with no cost beside it. */
	private function reading(Vehicle $vehicle, int $at, int $odo): void {
		\OCP\Server::get(OdometerService::class)->record(self::OWNER, $vehicle->getUuid(), ['read_at' => $at, 'read_at_off' => 60, 'value' => $odo]);
	}

	/**
	 * The range's rate lies between its lowest and highest bucket's.
	 *
	 * @param PerDistance $series
	 */
	private static function assertBetweenTheBuckets(array $series): void {
		$values = array_values(array_filter(array_column($series['buckets'], 'value'), is_float(...)));
		self::assertNotSame([], $values);
		self::assertNotNull($series['range']['value']);
		self::assertGreaterThanOrEqual(min($values), $series['range']['value']);
		self::assertLessThanOrEqual(max($values), $series['range']['value']);
	}

	private function expense(Vehicle $vehicle, int $at, int $amount, ?string $category = null, string $by = self::OWNER): void {
		\OCP\Server::get(ExpenseService::class)->record($by, $vehicle->getUuid(), ['spent_at' => $at, 'spent_at_off' => 60, 'amount' => $amount, 'category' => $category]);
	}
}
