<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\CostCentreMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\CostCentreLinks;
use OCA\NextFleet\Service\CostCentreService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Trips, fill-ups, maintenance records and expenses name a cost centre of the vehicle owner's
 * list; a new one takes the vehicle's default, unless the request names one or none.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class CostCentreLinkTest extends TestCase {
	/** Accounts: a grantee has to exist, and the owner's preference is an account's. */
	private const OWNER = 'nextfleet-test-ccl-owner';
	private const DRIVER = 'nextfleet-test-ccl-driver';
	/** Not an account: a list of somebody else's. */
	private const OTHER = 'nextfleet-test-ccl-other';

	private CostCentreService $centres;
	private TripService $trips;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			$users->get($uid)?->delete();
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ([self::OWNER, self::DRIVER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->centres = Server::get(CostCentreService::class);
		$this->trips = Server::get(TripService::class);
		$this->vehicles = Server::get(VehicleService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		$people = [self::OWNER, self::DRIVER, self::OTHER];
		foreach (['fleet_trips', 'fleet_audit', 'fleet_odo_readings', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_bookings', 'fleet_access', 'fleet_reminder_recipients', 'fleet_cost_centres'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('created_by', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->in('user_id', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		Server::get(IConfig::class)->deleteUserValue(self::OWNER, 'nextfleet', 'cost_centres');
	}

	public function testATripNamesACostCentreOfTheOwnersList(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle();

		$trip = $this->trip($vehicle, ['cost_centre_uuid' => $sales]);

		$this->assertSame($sales, $trip->jsonSerialize()['cost_centre_uuid']);
		$this->assertSame($sales, $this->stored($trip)->getCostCentreUuid());
	}

	public function testANewTripTakesTheVehiclesDefault(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales]);

		$this->assertSame($sales, $vehicle->jsonSerialize()['cost_centre_uuid']);
		$this->assertSame($sales, $this->trip($vehicle)->getCostCentreUuid());
	}

	/** Sent as null, it is none: the driver took the default away. */
	public function testANullIsNoCostCentre(): void {
		$vehicle = $this->vehicle(['cost_centre_uuid' => $this->centre('Vertrieb')]);

		$this->assertNull($this->trip($vehicle, ['cost_centre_uuid' => null])->getCostCentreUuid());
	}

	/** An archived one is no longer offered, so no new entry takes it unasked. */
	public function testAnArchivedDefaultIsNotTaken(): void {
		$sales = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales['uuid']]);
		$this->centres->update(self::OWNER, $sales['uuid'], $sales['updated_at'], ['name' => 'Vertrieb', 'archived' => true]);

		$this->assertNull($this->trip($vehicle)->getCostCentreUuid());
	}

	/** It would be a default no entry takes; one the vehicle names already may stay. */
	public function testAnArchivedCostCentreIsNoNewDefault(): void {
		$sales = $this->centres->create(self::OWNER, ['name' => 'Vertrieb']);
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales['uuid']]);
		$sales = $this->centres->update(self::OWNER, $sales['uuid'], $sales['updated_at'], ['name' => 'Vertrieb', 'archived' => true]);

		$kept = $this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['cost_centre_uuid' => $sales['uuid'], 'color' => 'blau']);
		$this->assertSame($sales['uuid'], $kept->jsonSerialize()['cost_centre_uuid']);

		$this->expectException(\InvalidArgumentException::class);
		$this->vehicle(['cost_centre_uuid' => $sales['uuid']]);
	}

	/** A sheet sends back the none it read; the trip keeps the erased record's id as its mark. */
	public function testANullKeepsTheMarkOfAnErasedCostCentre(): void {
		$vehicle = $this->vehicle();
		$trip = $this->trip($vehicle, ['cost_centre_uuid' => $this->centre('Vertrieb')]);
		Server::get(CostCentreMapper::class)->eraseListOf(self::OWNER);
		$read = $this->stored($trip);
		$this->assertNull($read->getCostCentreUuid());

		$this->trips->update(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $read->getUpdatedAt(), self::fields() + ['purpose' => 'Kunde', 'cost_centre_uuid' => null]);

		$this->assertSame($read->getCostCentreId(), $this->stored($trip)->getCostCentreId());
	}

	/**
	 * The vehicle the access check read can be older than the hold: a transfer or a new default
	 * committed in between. A new entry takes the default the vehicle names now.
	 */
	public function testANewEntryTakesTheDefaultAsItIsUnderTheHold(): void {
		$vehicle = $this->vehicle(['cost_centre_uuid' => $this->centre('Vertrieb')]);
		$service = $this->centre('Service');
		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['cost_centre_uuid' => $service]);
		$trip = new Trip();

		Server::get(CostCentreLinks::class)->apply($trip, $vehicle, [], true);

		$this->assertSame($service, $trip->getCostCentreUuid());
	}

	/** A change of the default changes no entry already there. */
	public function testANewDefaultLeavesTheEntriesAsTheyAre(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales]);
		$trip = $this->trip($vehicle);

		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['cost_centre_uuid' => $this->centre('Service')]);

		$this->assertSame($sales, $this->stored($trip)->getCostCentreUuid());
	}

	/** A client that does not know the field keeps what the entry names. */
	public function testAnEditThatSendsNoCostCentreKeepsIt(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle();
		$trip = $this->trip($vehicle, ['cost_centre_uuid' => $sales]);

		$edited = $this->trips->update(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $trip->getUpdatedAt(), self::fields() + ['purpose' => 'Kunde']);

		$this->assertSame($sales, $edited->getCostCentreUuid());
	}

	/** Where the logbook is kept, the change is on the trail like any other. */
	public function testAnEditMovesTheCostCentreOnTheTrail(): void {
		$sales = $this->centre('Vertrieb');
		$service = $this->centre('Service');
		$vehicle = $this->vehicle(['logbook_mode' => true]);
		$trip = $this->trip($vehicle, ['cost_centre_uuid' => $sales]);

		$this->trips->update(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $trip->getUpdatedAt(), self::fields() + ['cost_centre_uuid' => $service]);

		$rows = Server::get(AuditMapper::class)->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertSame([$sales, $service], end($rows)->getDiffJson()['fields']['cost_centre_uuid']);
	}

	public function testACostCentreOfAnotherListIsRefused(): void {
		$theirs = $this->centres->create(self::OTHER, ['name' => 'Fremd'])['uuid'];

		$this->expectException(\InvalidArgumentException::class);
		$this->trip($this->vehicle(), ['cost_centre_uuid' => $theirs]);
	}

	/** A driver picks from the owner's list. */
	public function testADriverNamesACostCentreOfTheOwners(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle();
		Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);

		$trip = $this->trips->record(self::DRIVER, $vehicle->getUuid(), self::fields() + ['cost_centre_uuid' => $sales]);

		$this->assertSame($sales, $trip->getCostCentreUuid());
	}

	/** The import writes each row through the same add() as a fill-up, a record and an expense. */
	public function testEveryKindOfEntryTakesTheDefault(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales]);
		$uuid = $vehicle->getUuid();

		$energy = Server::get(EnergyService::class)->record(self::OWNER, $uuid, ['filled_at' => 1750000000, 'filled_at_off' => 60, 'energy' => 'petrol', 'amount' => 40000, 'total' => 7000]);
		$record = Server::get(MaintenanceService::class)->record(self::OWNER, $uuid, ['done_at' => 1750000000, 'done_at_off' => 60, 'title' => 'Ölwechsel', 'cost' => 9000]);
		$expense = Server::get(ExpenseService::class)->record(self::OWNER, $uuid, ['spent_at' => 1750000000, 'spent_at_off' => 60, 'amount' => 500, 'category' => 'parking']);

		$this->assertSame([$sales, $sales, $sales], [$energy['cost_centre_uuid'], $record['cost_centre_uuid'], $expense['cost_centre_uuid']]);
	}

	public function testAnExpenseEditNamesAnotherCostCentre(): void {
		$vehicle = $this->vehicle(['cost_centre_uuid' => $this->centre('Vertrieb')]);
		$service = $this->centre('Service');
		$expenses = Server::get(ExpenseService::class);
		$expense = $expenses->record(self::OWNER, $vehicle->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 60, 'amount' => 500]);

		$edited = $expenses->update(self::OWNER, $vehicle->getUuid(), $expense['uuid'], $expense['updated_at'], ['spent_at' => 1750000000, 'spent_at_off' => 60, 'amount' => 500, 'cost_centre_uuid' => $service]);

		$this->assertSame($service, $edited['cost_centre_uuid']);
	}

	/** The trip a check-in prompts takes the default, as any new trip does. */
	public function testTheTripACheckInDraftsTakesTheDefault(): void {
		$sales = $this->centre('Vertrieb');
		$vehicle = $this->vehicle(['cost_centre_uuid' => $sales]);
		$bookings = Server::get(BookingService::class);
		$now = time() - time() % 60;
		$booking = $bookings->book(self::OWNER, $vehicle->getUuid(), ['starts_at' => $now, 'starts_at_off' => 60, 'ends_at' => $now + 3600, 'ends_at_off' => 60]);
		$bookings->checkOut(self::OWNER, $vehicle->getUuid(), $booking['uuid'], ['odo' => 100, 'at_off' => 60]);

		$in = $bookings->checkIn(self::OWNER, $vehicle->getUuid(), $booking['uuid'], ['odo' => 150, 'at_off' => 60]);

		$this->assertSame($sales, $in['trip_draft']['cost_centre_uuid'] ?? null);
	}

	/** A freelancer without a shared vehicle sees no cost centre until they switch them on. */
	public function testTheOwnerUsesCostCentresOnceSwitchedOn(): void {
		$vehicle = $this->vehicle();
		$this->assertFalse($this->vehicles->find(self::OWNER, $vehicle->getUuid())->jsonSerialize()['uses_cost_centres']);

		$settings = Server::get(PreferencesService::class)->write(self::OWNER, ['cost_centres' => true]);

		$this->assertTrue($settings['preferences']['cost_centres']);
		$this->assertTrue($this->vehicles->find(self::OWNER, $vehicle->getUuid())->jsonSerialize()['uses_cost_centres']);
	}

	/** A pool uses them: sharing a vehicle turns them on, for the owner and the driver alike. */
	public function testASharedVehicleUsesCostCentres(): void {
		$solo = $this->vehicle();
		$shared = $this->vehicle();
		Server::get(GrantService::class)->grant(self::OWNER, $shared->getUuid(), ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertTrue($this->vehicles->find(self::OWNER, $solo->getUuid())->jsonSerialize()['uses_cost_centres']);
		$this->assertSame([true], array_map(static fn (Vehicle $vehicle): bool => $vehicle->jsonSerialize()['uses_cost_centres'], $this->vehicles->list(self::DRIVER)));
	}

	private function centre(string $name): string {
		return $this->centres->create(self::OWNER, ['name' => $name])['uuid'];
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(array $fields = []): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'NF-CL 1'] + $fields);
	}

	/** @param array<string, mixed> $more */
	private function trip(Vehicle $vehicle, array $more = []): Trip {
		return $this->trips->record(self::OWNER, $vehicle->getUuid(), $more + self::fields());
	}

	/** @return array<string, mixed> */
	private static function fields(): array {
		return [
			'started_at' => 1750000000,
			'started_at_off' => 60,
			'ended_at' => 1750003600,
			'ended_at_off' => 60,
			'end_odo' => 1000,
			'category' => Trip::BUSINESS,
		];
	}

	private function stored(Trip $trip): Trip {
		return Server::get(TripMapper::class)->findAnyByUuid($trip->getUuid());
	}
}
