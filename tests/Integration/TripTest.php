<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The two tables M2 adds, against the real database: what a trip is when it comes back out, the
 * order trips come back in, and what the audit trail keeps.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TripTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';
	/** No vehicle row is needed - neither table carries a foreign key into one. */
	private const VEHICLE = 424242;

	private TripMapper $trips;
	private AuditMapper $audit;
	private TripService $service;
	private OdometerService $odometer;
	private VehicleService $vehicles;
	private VehicleMapper $vehicleRows;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->trips = $container->get(TripMapper::class);
		$this->audit = $container->get(AuditMapper::class);
		$this->service = $container->get(TripService::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->vehicleRows = $container->get(VehicleMapper::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = [
			'fleet_trips' => 'created_by',
			'fleet_audit' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::AUTHOR)));
			$qb->executeStatement();
		}
	}

	private function trip(int $startedAt, int $offset = 0): Trip {
		$trip = new Trip();
		$trip->setCreatedBy(self::AUTHOR);
		$trip->setVehicleId(self::VEHICLE);
		$trip->setStartedAt($startedAt);
		$trip->setStartedAtOff($offset);
		$trip->setEndedAt($startedAt + 3600);
		$trip->setEndedAtOff($offset);
		$trip->setCategory(Trip::PRIVATE);

		return $trip;
	}

	/**
	 * One trip of an hour and a half through the service, ending on whichever of the two the case
	 * hands it - and under whichever category, the business one being what a logbook is kept for.
	 *
	 * @param array<string, mixed> $ending
	 */
	private function record(string $vehicleUuid, int $startedAt, array $ending): Trip {
		return $this->service->record(self::AUTHOR, $vehicleUuid, $ending + [
			'started_at' => $startedAt,
			'started_at_off' => 0,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 0,
			'category' => Trip::BUSINESS,
		]);
	}

	/**
	 * Every column survives the round trip, the two that a starting value would otherwise drop
	 * included: an offset of zero is a trip entered in UTC, and `reconciled` left alone is a trip
	 * nobody reconciled - both are facts, and a NOT NULL column would refuse the row if the
	 * entity let them go unwritten.
	 */
	public function testATripEnteredInUtcAndNeverReconciledStillWritesBothColumns(): void {
		$written = $this->trips->insert($this->trip(1750000000));

		$read = $this->trips->findByUuid($written->getUuid());
		$this->assertInstanceOf(Trip::class, $read);
		$this->assertSame(0, $read->getStartedAtOff());
		$this->assertFalse($read->getReconciled());
	}

	/**
	 * Trips are ordered by when they happened, never by when they were entered - the rule the
	 * readings follow (docs/architecture.md#odometer-rules), and the reason the index is on
	 * `(vehicle_id, started_at)`. A voided trip is out of this read: the export has its own.
	 */
	public function testTripsComeBackInTheOrderTheyHappenedAndWithoutTheVoidedOne(): void {
		$second = $this->trips->insert($this->trip(1750086400));
		$first = $this->trips->insert($this->trip(1750000000));
		$voided = $this->trips->insert($this->trip(1750043200));
		$this->trips->softDelete($voided, $voided->getUpdatedAt());

		$found = $this->trips->findAllForVehicle(self::VEHICLE);

		$this->assertSame(
			[$first->getUuid(), $second->getUuid()],
			array_map(static fn (Trip $trip): string => $trip->getUuid(), $found),
		);
	}

	/**
	 * The trail of one row, in the order it was written, with the diff decoded. Append-only is
	 * the point: two changes to one trip are two rows, not one row rewritten.
	 */
	public function testTheAuditTrailOfOneTripIsEveryRowWrittenAboutItInOrder(): void {
		$trip = $this->trips->insert($this->trip(1750000000));
		$tripId = (int)$trip->getId();

		foreach ([['category' => [null, 'private']], ['purpose' => ['', 'Kundentermin']]] as $diff) {
			$row = new Audit();
			$row->setCreatedBy(self::AUTHOR);
			$row->setEntity(Audit::TRIP);
			$row->setEntityId($tripId);
			$row->setDiffJson($diff);
			$this->audit->insert($row);
		}
		// A row about a different trip, to show the read is not "everything by this author".
		$other = new Audit();
		$other->setCreatedBy(self::AUTHOR);
		$other->setEntity(Audit::TRIP);
		$other->setEntityId($tripId + 1);
		$other->setDiffJson(['category' => [null, 'business']]);
		$this->audit->insert($other);

		$trail = $this->audit->findForEntity(Audit::TRIP, $tripId);

		$this->assertSame(
			[['category' => [null, 'private']], ['purpose' => ['', 'Kundentermin']]],
			array_map(static fn (Audit $row): array => $row->getDiffJson(), $trail),
		);
	}

	/**
	 * The task, against the real database: two rows in two tables and a third column moved, all of
	 * it inside one transaction. Only the instance says whether the NOT NULL columns were written
	 * and whether the vehicle's cache followed.
	 */
	public function testATripEndingOnACounterWritesItsReadingAndMovesTheVehicle(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();

		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);

		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(1750005400, $readings[0]->getReadAt());
		$this->assertSame(120450, $readings[0]->getValue());
		$this->assertSame(OdometerService::OBSERVED, $readings[0]->getOrigin());
		$this->assertSame((int)$trip->getId(), $readings[0]->getSourceId());
		$this->assertSame(120450, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
	}

	/**
	 * Rule 6 against the real database: the counted Reading is the row the trip set off from plus
	 * the kilometres driven, and the vehicle follows it. Only the instance says that a `distance`
	 * survives the column and that the two reads agree on which row came first.
	 */
	public function testADistanceOnlyTripCountsFromTheReadingItSetOffFrom(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 124']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120450]);

		$trip = $this->record($uuid, 1750100000, ['distance' => 140, 'category' => Trip::PRIVATE]);

		$this->assertSame(140, $trip->getDistance());
		$this->assertNull($trip->getEndOdo());
		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(2, $readings);
		$this->assertSame(120590, $readings[1]->getValue());
		$this->assertSame(OdometerService::DERIVED, $readings[1]->getOrigin());
		$this->assertSame(120590, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
	}

	/**
	 * The other half of rule 6, across both tables: the counter a later trip ended on discredits the
	 * counted Readings the distance-only trips before it left behind, and rewrites none of them. Only
	 * the instance says the flag reaches the column - it is written by a statement of its own, on rows
	 * the transaction that wrote them has already committed.
	 */
	public function testACounterALaterTripEndedOnFlagsTheCountedReadingsBeforeIt(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 125']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$this->record($uuid, 1750100000, ['distance' => 400]);
		$this->record($uuid, 1750200000, ['distance' => 400]);

		$this->record($uuid, 1750300000, ['end_odo' => 120300]);

		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(4, $readings);
		$chain = [];
		foreach ($readings as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}
		$this->assertSame(
			[120000 => false, 120400 => true, 120800 => true, 120300 => false],
			$chain,
		);
		$this->assertSame(120300, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
	}

	/**
	 * The switch itself is task 10's, so the column is flipped here the way the vehicle sheet
	 * will: on the row the test just read, through the checked write every update goes through.
	 */
	private function underLogbookMode(Vehicle $vehicle): void {
		$vehicle->setLogbookMode(true);
		$this->vehicleRows->updateChecked($vehicle, $vehicle->getUpdatedAt());
	}

	/**
	 * The task against the real database: the trail of one trip, as the JSON column gave it back.
	 * Only the instance says a nested diff survives `Types::JSON` and that the row committed
	 * alongside the trip it describes rather than with it.
	 */
	public function testATripUnderLogbookModeIsRecordedInTheTrailOfThatTrip(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 126']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);

		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450, 'purpose' => 'Kundentermin']);

		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertCount(1, $trail);
		$this->assertSame(self::AUTHOR, $trail[0]->getCreatedBy());
		$this->assertSame([
			'change' => 'created',
			'fields' => [
				'started_at' => [null, 1750000000],
				'started_at_off' => [null, 0],
				'ended_at' => [null, 1750005400],
				'ended_at_off' => [null, 0],
				'end_odo' => [null, 120450],
				'purpose' => [null, 'Kundentermin'],
				'category' => [null, Trip::BUSINESS],
			],
		], $trail[0]->getDiffJson());
	}

	/**
	 * Off the mode nothing is recorded, and the trip is written all the same. A vehicle that only
	 * accepted a trip when somebody was watching would be the wrong half of the feature.
	 */
	public function testATripOnAVehicleOutsideTheModeIsWrittenAndLeavesNoTrail(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 127']);

		$trip = $this->record($vehicle->getUuid(), 1750000000, ['end_odo' => 120450]);

		$this->assertSame([], $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId()));
		$this->assertSame(120450, $this->vehicles->find(self::AUTHOR, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to build
	 * the whole chain from constructor types alone - the database connection the transaction needs
	 * included.
	 */
	public function testTheServiceIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			TripService::class,
			(new Application())->getContainer()->get(TripService::class),
		);
	}
}
