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

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->trips = $container->get(TripMapper::class);
		$this->audit = $container->get(AuditMapper::class);
		$this->service = $container->get(TripService::class);
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

		$trip = $this->service->record(self::AUTHOR, $uuid, [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120450,
			'category' => Trip::BUSINESS,
		]);

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
		$this->service->record(self::AUTHOR, $uuid, [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120450,
			'category' => Trip::BUSINESS,
		]);

		$trip = $this->service->record(self::AUTHOR, $uuid, [
			'started_at' => 1750100000,
			'started_at_off' => 0,
			'ended_at' => 1750105400,
			'ended_at_off' => 0,
			'distance' => 140,
			'category' => Trip::PRIVATE,
		]);

		$this->assertSame(140, $trip->getDistance());
		$this->assertNull($trip->getEndOdo());
		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(2, $readings);
		$this->assertSame(120590, $readings[1]->getValue());
		$this->assertSame(OdometerService::DERIVED, $readings[1]->getOrigin());
		$this->assertSame(120590, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
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
