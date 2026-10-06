<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\Gaps;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Trips and their audit trail against the real database: what a trip is when it comes back out,
 * the order trips come back in, and what the audit trail keeps.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TripTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';
	/** Somebody else on the same vehicle, written past the service: no grant is needed for a row. */
	private const OTHER = 'nextfleet-test-bob';
	/** Granted `manager` where a case needs one: edits and voids anybody's trip. */
	private const MANAGER = 'nextfleet-test-carol';
	/** No vehicle row is needed - neither table carries a foreign key into one. */
	private const VEHICLE = 424242;

	private TripMapper $trips;
	private AuditMapper $audit;
	private TripService $service;
	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$this->trips = \OCP\Server::get(TripMapper::class);
		$this->audit = \OCP\Server::get(AuditMapper::class);
		$this->service = \OCP\Server::get(TripService::class);
		$this->odometer = \OCP\Server::get(OdometerService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
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
			'fleet_access' => 'created_by',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter([self::AUTHOR, self::OTHER, self::MANAGER], IQueryBuilder::PARAM_STR_ARRAY)));
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
	 * An offset of zero (entered in UTC) and `reconciled` left false are facts too: a NOT NULL
	 * column would refuse the row if the entity let them go unwritten.
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
	 * Two rows in two tables and a third column moved, in one transaction. Only the instance says
	 * whether the NOT NULL columns were written and whether the vehicle's cache followed.
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

	/** Switched on the way the vehicle edit sheet switches it, through the service. */
	private function underLogbookMode(Vehicle $vehicle): void {
		$this->vehicles->update(
			self::AUTHOR,
			$vehicle->getUuid(),
			$vehicle->getUpdatedAt(),
			['logbook_mode' => true],
		);
	}

	/**
	 * The trail of one trip, as the JSON column gave it back. Only the instance says a nested diff
	 * survives `Types::JSON` and that the row committed alongside the trip it describes.
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
			'updated_at' => $trip->getUpdatedAt(),
		], $trail[0]->getDiffJson());
	}

	public function testATripOnAVehicleOutsideTheModeIsWrittenAndLeavesNoTrail(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 127']);

		$trip = $this->record($vehicle->getUuid(), 1750000000, ['end_odo' => 120450]);

		$this->assertSame([], $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId()));
		$this->assertSame(120450, $this->vehicles->find(self::AUTHOR, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * A vehicle never under the mode keeps no trail of any change either: the trail follows trips
	 * set off inside a period, and this one has none.
	 */
	public function testAVehicleNeverUnderTheModeWritesNoAuditRow(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 128', 'jurisdiction' => 'de'])->getUuid();
		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);
		$edited = $this->service->update(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120460,
			'purpose' => 'Kundentermin',
			'category' => Trip::BUSINESS,
		]);
		$voided = $this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $edited->getUpdatedAt());
		$this->service->restore(self::AUTHOR, $uuid, $trip->getUuid(), $voided->getUpdatedAt());

		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('fleet_audit')
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::AUTHOR)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}

	/**
	 * The row survives its own delete, it is out of every read of the vehicle's trips, and the
	 * Reading it left goes with it, so the vehicle stands where it stood before the journey.
	 */
	public function testVoidingATripKeepsTheRowAndTakesItsCounterWithIt(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 128']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$trip = $this->record($uuid, 1750100000, ['end_odo' => 120500]);

		$voided = $this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertNotNull($voided->getDeletedAt());
		$this->assertSame($trip->getUuid(), $this->trips->findAnyByUuid($trip->getUuid())->getUuid());
		$this->assertCount(1, $this->trips->findAllForVehicle((int)$vehicle->getId()));
		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(120000, $readings[0]->getValue());
		$this->assertSame(120000, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
	}

	/**
	 * What the sheet completes route, purpose and partner from: this vehicle's own trips, each word
	 * once and the latest first. A starting point and a destination are one list, because where a
	 * trip ended is where the next one sets off. A voided trip and another vehicle's are not asked.
	 */
	public function testThePrefillOffersThisVehiclesTripWordsLatestFirst(): void {
		$mine = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 130']);
		$other = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 131']);
		$uuid = $mine->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000, 'from_label' => 'Office', 'to_label' => 'Müller GmbH', 'purpose' => 'Client visit', 'partner' => 'Müller GmbH']);
		$this->record($uuid, 1750100000, ['end_odo' => 120100, 'from_label' => 'Müller GmbH', 'to_label' => 'Office']);
		$this->record($uuid, 1750200000, ['end_odo' => 120200, 'to_label' => 'Depot', 'purpose' => 'Delivery']);
		$voided = $this->record($uuid, 1750300000, ['end_odo' => 120300, 'to_label' => 'Nowhere', 'purpose' => 'Mistake', 'partner' => 'Nobody']);
		$this->service->delete(self::AUTHOR, $uuid, $voided->getUuid(), $voided->getUpdatedAt());
		$this->record($other->getUuid(), 1750400000, ['end_odo' => 5000, 'to_label' => 'Elsewhere', 'purpose' => 'Theirs', 'partner' => 'Theirs']);

		$this->assertSame([
			'places' => ['Depot', 'Office', 'Müller GmbH'],
			'purposes' => ['Delivery', 'Client visit'],
			'partners' => ['Müller GmbH'],
			'category' => Trip::BUSINESS,
			'last' => ['end_odo' => 120200, 'ended_at' => 1750205400, 'ended_at_off' => 0],
		], $this->service->prefill(self::AUTHOR, $uuid));
	}

	/**
	 * The category is the one this person last entered on this vehicle, not the newest-dated trip's
	 * and not somebody else's; a Reconciliation Trip is the app's, not their choice. The last trip
	 * is the one that set off last, whoever entered it, and a trip logged by distance names no counter.
	 */
	public function testThePrefillOffersThisPersonsLastCategoryAndTheLastTripsEnd(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 132']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750100000, ['end_odo' => 120100]);
		$this->service->record(self::AUTHOR, $uuid, [
			'started_at' => 1750000000, 'started_at_off' => 0, 'ended_at' => 1750003600, 'ended_at_off' => 0,
			'category' => Trip::COMMUTE, 'end_odo' => 120000,
		]);
		$reconciled = $this->trip(1749000000);
		$reconciled->setVehicleId((int)$vehicle->getId());
		$reconciled->setReconciled(true);
		$this->trips->insert($reconciled);
		$theirs = $this->trip(1750200000, 120);
		$theirs->setVehicleId((int)$vehicle->getId());
		$theirs->setCreatedBy(self::OTHER);
		$theirs->setDistance(80);
		$this->trips->insert($theirs);

		$prefill = $this->service->prefill(self::AUTHOR, $uuid);

		$this->assertSame(Trip::COMMUTE, $prefill['category']);
		$this->assertSame(['end_odo' => null, 'ended_at' => 1750203600, 'ended_at_off' => 120], $prefill['last']);
	}

	public function testThePrefillOfAVehicleWithoutTripsOffersNoCategoryAndNoLastTrip(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 133']);

		$prefill = $this->service->prefill(self::AUTHOR, $vehicle->getUuid());

		$this->assertNull($prefill['category']);
		$this->assertNull($prefill['last']);
	}

	/**
	 * Undo, against the real statements: both rows come back on the token the void answered with,
	 * and the vehicle is where the journey left it.
	 */
	public function testUndoBringsTheTripAndItsCounterBack(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 129']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$trip = $this->record($uuid, 1750100000, ['end_odo' => 120500]);
		$voided = $this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());

		$back = $this->service->restore(self::AUTHOR, $uuid, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertNull($back->getDeletedAt());
		$this->assertCount(2, $this->trips->findAllForVehicle((int)$vehicle->getId()));
		$this->assertCount(2, $this->odometer->list(self::AUTHOR, $uuid));
		$this->assertSame(120500, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
	}

	/**
	 * A late restore under Logbook Mode moves the token like any restore - the trip's and its
	 * Reading's, which a client asking what changed would otherwise never see come back - and the
	 * void's token is spent. The trail still calls it late.
	 */
	public function testALateRestoreMovesTheTokenOfTheTripAndItsReading(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 131']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);
		$voided = $this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());
		$spent = $voided->getUpdatedAt();

		$back = $this->service->restore(self::AUTHOR, $uuid, $trip->getUuid(), $spent);

		$this->assertGreaterThan($spent, $back->getUpdatedAt());
		[$reading] = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertGreaterThan($spent, $reading->getUpdatedAt());
		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertTrue(end($trail)->getDiffJson()['late']);
		$this->expectException(StaleUpdateException::class);
		$this->service->update(self::AUTHOR, $uuid, $trip->getUuid(), $spent, [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120460,
			'category' => Trip::BUSINESS,
		]);
	}

	/**
	 * The trail of a trip that was voided and brought back: three rows, in the order they happened,
	 * as the JSON column gave them back. An auditor reading the last one knows the trip stands.
	 */
	public function testTheVoidAndTheUndoAreBothInTheTrailOfThatTrip(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 130']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);

		$voided = $this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());
		$this->service->restore(self::AUTHOR, $uuid, $trip->getUuid(), $voided->getUpdatedAt());

		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$stamp = $voided->getDeletedAt();
		$this->assertSame(
			['created', 'voided', 'restored'],
			array_map(static fn (Audit $row): string => $row->getDiffJson()['change'], $trail),
		);
		$this->assertSame(['deleted_at' => [null, $stamp]], $trail[1]->getDiffJson()['fields']);
		$this->assertSame(['deleted_at' => [$stamp, null]], $trail[2]->getDiffJson()['fields']);
	}

	/**
	 * The trail names who made each change, not who entered the trip: a manager who corrects or
	 * voids a driver's trip is the one an auditor has to ask.
	 */
	public function testAManagersChangesToADriversTripAreTheManagersInTheTrail(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 133']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		foreach ([self::OTHER => 'driver', self::MANAGER => 'manager'] as $grantee => $role) {
			$grant = new Access();
			$grant->setVehicleId((int)$vehicle->getId());
			$grant->setGrantee($grantee);
			$grant->setGranteeType(Access::USER);
			$grant->setRole($role);
			$grant->setCreatedBy(self::AUTHOR);
			\OCP\Server::get(AccessMapper::class)->insert($grant);
		}
		$body = [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120450,
			'category' => Trip::BUSINESS,
		];
		$trip = $this->service->record(self::OTHER, $uuid, $body);

		$edited = $this->service->update(self::MANAGER, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), ['purpose' => 'Kundentermin'] + $body);
		$this->service->delete(self::MANAGER, $uuid, $trip->getUuid(), $edited->getUpdatedAt());

		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertSame(
			[['created', self::OTHER], ['edited', self::MANAGER], ['voided', self::MANAGER]],
			array_map(static fn (Audit $row): array => [$row->getDiffJson()['change'], $row->getCreatedBy()], $trail),
		);
		$this->assertSame(self::OTHER, $this->trips->findAnyByUuid($trip->getUuid())->getCreatedBy(), 'the trip is still the driver\'s');
	}

	/**
	 * The concurrency token is the statement's own predicate (docs/architecture.md#concurrency), so
	 * a void that lost the race writes nothing at all - not the stamp, not the trail, and not the
	 * Reading that would have gone with it.
	 */
	public function testAVoidThatLostTheRaceChangesNothing(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 131']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);

		try {
			$this->service->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt() - 1);
			$this->fail('a void wrote under a token nobody held');
		} catch (StaleUpdateException) {
			$this->assertNull($this->trips->findAnyByUuid($trip->getUuid())->getDeletedAt());
			$this->assertCount(1, $this->odometer->list(self::AUTHOR, $uuid));
			$this->assertSame(120450, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
			$this->assertCount(1, $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId()));
		}
	}

	/**
	 * Against the real clock: a trip from 2025 corrected now is long past Germany's delay. The edit
	 * lands, the counter follows it, and the trail as the JSON column gave it back calls it late.
	 */
	public function testALateEditIsWrittenAndItsTrailSaysItWasLate(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 132', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$trip = $this->record($uuid, 1750000000, ['end_odo' => 120450]);

		$this->service->update(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), [
			'started_at' => 1750000000,
			'started_at_off' => 0,
			'ended_at' => 1750005400,
			'ended_at_off' => 0,
			'end_odo' => 120460,
			'purpose' => 'Kundentermin',
			'category' => Trip::BUSINESS,
		]);

		$this->assertSame('Kundentermin', $this->trips->findByUuid($trip->getUuid())->getPurpose());
		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(1, $readings);
		$this->assertSame(120460, $readings[0]->getValue());
		$this->assertSame(120460, $this->vehicles->find(self::AUTHOR, $uuid)->getOdoValue());
		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertSame([
			'change' => 'edited',
			'fields' => ['end_odo' => [120450, 120460], 'purpose' => [null, 'Kundentermin']],
			'late' => true,
			'updated_at' => $this->trips->findByUuid($trip->getUuid())->getUpdatedAt(),
		], end($trail)->getDiffJson());
	}

	/** A journey that ended an hour ago by the server's clock is corrected in time. */
	public function testAnEditInsideTheDelayIsNotLate(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 133', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$startedAt = \OCP\Server::get(ITimeFactory::class)->getTime() - 9000;
		$trip = $this->record($uuid, $startedAt, ['end_odo' => 120450]);

		$this->service->update(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), [
			'started_at' => $startedAt,
			'started_at_off' => 0,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 0,
			'end_odo' => 120450,
			'purpose' => 'Kundentermin',
			'category' => Trip::BUSINESS,
		]);

		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$trip->getId());
		$this->assertFalse(end($trail)->getDiffJson()['late']);
	}

	/**
	 * Only the real query says a restated distance passes over the trip's own Reading: the trip is
	 * moved past where it used to end, so that Reading now stands before its new start.
	 */
	public function testARestatedDistanceCountsFromTheReadingBeforeTheJourney(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 134']);
		$uuid = $vehicle->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$trip = $this->record($uuid, 1750100000, ['distance' => 400]);

		$this->service->update(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), [
			'started_at' => 1750106000,
			'started_at_off' => 0,
			'ended_at' => 1750111400,
			'ended_at_off' => 0,
			'distance' => 400,
			'category' => Trip::BUSINESS,
		]);

		$readings = $this->odometer->list(self::AUTHOR, $uuid);
		$this->assertCount(2, $readings);
		$this->assertSame(1750111400, $readings[1]->getReadAt());
		$this->assertSame(120400, $readings[1]->getValue());
	}

	/**
	 * A claim 200 km above the counter is closed by one private trip the app marks reconciled. Its
	 * counted Reading lands on the claim, so the real queries find no Gap left, and the trail as
	 * the JSON column gave it back says the kilometres were derived.
	 */
	public function testClosingAGapWritesOneReconciledTripAndLeavesNoGap(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 135']);
		$uuid = $vehicle->getUuid();
		$this->underLogbookMode($vehicle);
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$claiming = $this->record($uuid, 1750100000, ['start_odo' => 120200, 'end_odo' => 120300]);
		$gaps = \OCP\Server::get(Gaps::class);
		$held = $this->vehicles->find(self::AUTHOR, $uuid);
		[$gap] = $gaps->of($held);

		$closing = $this->service->reconcile(self::AUTHOR, $uuid, $claiming->getUuid(), $gap['distance'], $gap['from_at'], $gap['to_at']);

		// The vehicle was held for the write and nothing on it moved: not its token, not its counter.
		$after = $this->vehicles->find(self::AUTHOR, $uuid);
		$this->assertSame([$held->getUpdatedAt(), 120300], [$after->getUpdatedAt(), $after->getOdoValue()]);

		$read = $this->trips->findByUuid($closing->getUuid());
		$this->assertTrue($read->getReconciled());
		$this->assertSame(Trip::PRIVATE, $read->getCategory());
		$this->assertSame([1750005400, 1750100000, 200], [$read->getStartedAt(), $read->getEndedAt(), $read->getDistance()]);
		$this->assertSame([], $gaps->of($this->vehicles->find(self::AUTHOR, $uuid)));
		$this->assertSame(
			[120000, 120200, 120300],
			array_map(static fn ($reading): int => $reading->getValue(), $this->odometer->list(self::AUTHOR, $uuid)),
		);
		$trail = $this->audit->findForEntity(Audit::TRIP, (int)$closing->getId());
		$this->assertCount(1, $trail);
		$this->assertTrue($trail[0]->getDiffJson()['derived']);

		$this->expectException(StaleUpdateException::class);
		$this->service->reconcile(self::AUTHOR, $uuid, $claiming->getUuid(), $gap['distance'], $gap['from_at'], $gap['to_at']);
	}

	/**
	 * An Odometer Entry between the two journeys, against the real queries: the Gap is still measured
	 * from where the first trip ended, a confirmation of it measured from the Entry is refused, and
	 * the closing trip lands on the claim over the Entry.
	 */
	public function testAGapIsMeasuredFromTheLastTripAndClosedOverAnEntryBetween(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 136'])->getUuid();
		$this->record($uuid, 1750000000, ['end_odo' => 120000]);
		$this->odometer->record(self::AUTHOR, $uuid, ['read_at' => 1750050000, 'read_at_off' => 0, 'value' => 120120]);
		$claiming = $this->record($uuid, 1750100000, ['start_odo' => 120200, 'end_odo' => 120300]);
		$gaps = \OCP\Server::get(Gaps::class);

		$this->assertSame(
			[[200, 1750005400, 1750100000]],
			array_map(
				static fn (array $gap): array => [$gap['distance'], $gap['from_at'], $gap['to_at']],
				$gaps->of($this->vehicles->find(self::AUTHOR, $uuid)),
			),
		);
		try {
			$this->service->reconcile(self::AUTHOR, $uuid, $claiming->getUuid(), 80, 1750050000, 1750100000);
			$this->fail('a Gap measured from the Entry was closed');
		} catch (StaleUpdateException) {
		}

		$this->service->reconcile(self::AUTHOR, $uuid, $claiming->getUuid(), 200, 1750005400, 1750100000);

		$this->assertSame([], $gaps->of($this->vehicles->find(self::AUTHOR, $uuid)));
		$this->assertSame(
			[[120000, false], [120120, false], [120200, false], [120300, false]],
			array_map(
				static fn ($reading): array => [$reading->getValue(), $reading->getFlagged()],
				$this->odometer->list(self::AUTHOR, $uuid),
			),
		);
	}

	/**
	 * Rule 4 against the real queries: a truck's hour Readings sit between its trips and touch none
	 * of the km rules. Read as one chain, the first trip's claim would open a Gap from 4 990 h, 5 000 h
	 * after 120 000 km would flag as a counter gone backwards, and the distance-only trip would count
	 * from the hours.
	 */
	public function testHourReadingsBetweenTripsStayOnTheirOwnChain(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, [
			'plate' => 'B-XY 137',
			'vehicle_type' => 'truck',
			'second_unit' => 'h',
			'jurisdiction' => 'de',
		])->getUuid();
		$hours = static fn (int $at, int $value): array
			=> ['read_at' => $at, 'read_at_off' => 0, 'value' => $value, 'counter' => 'second'];
		$this->odometer->record(self::AUTHOR, $uuid, $hours(1749900000, 4990));
		$this->record($uuid, 1750000000, ['start_odo' => 119900, 'end_odo' => 120000]);
		$this->odometer->record(self::AUTHOR, $uuid, $hours(1750050000, 5000));
		$claiming = $this->record($uuid, 1750100000, ['start_odo' => 120200, 'end_odo' => 120300]);
		$this->odometer->record(self::AUTHOR, $uuid, $hours(1750200000, 5004));
		$this->record($uuid, 1750250000, ['distance' => 50]);
		$gaps = \OCP\Server::get(Gaps::class);

		$this->assertSame(
			[[200, 1750005400, 1750100000]],
			array_map(
				static fn (array $gap): array => [$gap['distance'], $gap['from_at'], $gap['to_at']],
				$gaps->of($this->vehicles->find(self::AUTHOR, $uuid)),
			),
		);
		$this->service->reconcile(self::AUTHOR, $uuid, $claiming->getUuid(), 200, 1750005400, 1750100000);
		$this->assertSame([], $gaps->of($this->vehicles->find(self::AUTHOR, $uuid)));

		$this->assertSame(
			[
				[4990, 'second', false], [120000, 'main', false], [5000, 'second', false], [120200, 'main', false],
				[120300, 'main', false], [5004, 'second', false], [120350, 'main', false],
			],
			array_map(
				static fn ($reading): array => [$reading->getValue(), $reading->getCounter(), $reading->getFlagged()],
				$this->odometer->list(self::AUTHOR, $uuid),
			),
		);
		$truck = $this->vehicles->find(self::AUTHOR, $uuid);
		$this->assertSame([120350, 5004], [$truck->getOdoValue(), $truck->getSecondValue()]);

		// The Fahrtenbuch lists the four journeys and not one hour Reading between them.
		$html = (string)\OCP\Server::get(LogbookExport::class)->year(self::AUTHOR, $uuid, 2025);
		$this->assertSame(4, substr_count($html, '<tr', (int)strpos($html, '<tbody')));
		$this->assertStringNotContainsString('5004', $html);
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to build
	 * the whole chain from constructor types alone - the database connection the transaction needs
	 * included.
	 */
	public function testTheServiceIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			TripService::class,
			\OCP\Server::get(TripService::class),
		);
	}
}
