<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What adding a trip does to the vehicle it hangs off: the journey is stored, and the counter it
 * ended on becomes one Reading at `ended_at` (docs/architecture.md#odometer-rules, rule 5).
 *
 * The odometer is the real service here rather than a mock. "The vehicle's kilometres move" is a
 * statement about what the two do together, and a mock of the second one could only repeat what
 * this test already assumed.
 */
class TripServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	/** What the gate hands back for that uuid, and so the id every row here is written under. */
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const STRANGER = 'bob';

	/** The trips the mapper stands for, in insertion order. @var list<Trip> */
	private array $trips = [];
	/** The readings the odometer wrote. @var list<OdoReading> */
	private array $readings = [];
	private int $nextTripId = 1;
	private int $nextReadingId = 1;
	private ?int $cached = null;
	private ?OdometerService $odometer = null;

	private TripMapper&MockObject $tripMapper;
	private OdoReadingMapper&MockObject $readingMapper;
	private VehicleMapper&MockObject $vehicles;
	private VehicleService&MockObject $fleet;
	private IDBConnection&MockObject $db;

	protected function setUp(): void {
		$this->trips = [];
		$this->readings = [];
		$this->nextTripId = 1;
		$this->nextReadingId = 1;
		$this->cached = null;
		$this->odometer = null;

		// Stores rather than expectations, for the reason OdometerServiceTest keeps them: what a
		// trip is worth is what the vehicle shows once the row is in.
		$this->tripMapper = $this->createMock(TripMapper::class);
		$this->tripMapper->method('insert')->willReturnCallback(function (Trip $trip): Trip {
			$trip->setId($this->nextTripId);
			$trip->setUuid('0195e2f1-1111-4000-8000-00000000000' . $this->nextTripId++);
			$this->trips[] = $trip;

			return $trip;
		});

		$this->readingMapper = $this->createMock(OdoReadingMapper::class);
		$this->readingMapper->method('insert')->willReturnCallback(
			function (OdoReading $reading): OdoReading {
				$reading->setId($this->nextReadingId);
				$reading->setUuid('0195e2f1-0000-4000-8000-00000000000' . $this->nextReadingId++);
				$this->readings[] = $reading;

				return $reading;
			},
		);
		$this->readingMapper->method('flag')->willReturnCallback(
			static function (OdoReading $reading, bool $flagged): void {
				$reading->setFlagged($flagged);
			},
		);
		$this->readingMapper->method('findAllForVehicle')
			->willReturnCallback(fn (int $vehicleId): array => $this->ordered($vehicleId));
		$this->readingMapper->method('findNewestAtOrBefore')->willReturnCallback(
			function (int $vehicleId, int $readAt): ?OdoReading {
				$earlier = array_filter(
					$this->ordered($vehicleId),
					static fn (OdoReading $reading): bool => $reading->getReadAt() <= $readAt,
				);

				return $earlier === [] ? null : end($earlier);
			},
		);

		$this->vehicles = $this->createMock(VehicleMapper::class);
		$this->vehicles->method('cacheOdoValue')
			->willReturnCallback(function (int $vehicleId, ?int $value): void {
				$this->cached = $value;
			});

		// Who reaches which vehicle is VehicleAccessTest's; what this states is which operation a
		// trip asks the gate for.
		$this->fleet = $this->createMock(VehicleService::class);
		$this->fleet->method('reach')->willReturnCallback(
			function (string $userId, string $operation): Vehicle {
				$allowed = match ($userId) {
					self::OWNER => true,
					self::DRIVER => $operation === VehicleAccess::VIEW,
					default => false,
				};
				if (!$allowed) {
					throw new AccessDeniedException();
				}

				return $this->vehicle();
			},
		);

		$this->db = $this->createMock(IDBConnection::class);
	}

	/**
	 * One vehicle's readings in the order rule 1 puts them in, which is the order both mapper
	 * reads answer in.
	 *
	 * @return list<OdoReading>
	 */
	private function ordered(int $vehicleId): array {
		$rows = array_values(array_filter(
			$this->readings,
			static fn (OdoReading $reading): bool => $reading->getVehicleId() === $vehicleId,
		));
		usort($rows, static fn (OdoReading $a, OdoReading $b): int
			=> [$a->getReadAt(), $a->getId()] <=> [$b->getReadAt(), $b->getId()]);

		return $rows;
	}

	private function service(): TripService {
		return new TripService($this->tripMapper, $this->odometer(), $this->fleet, $this->db);
	}

	/**
	 * One odometer, the way the container hands out one: a case that writes an Odometer Entry of its
	 * own reaches the same service the trips went through.
	 */
	private function odometer(): OdometerService {
		return $this->odometer ??= new OdometerService($this->readingMapper, $this->vehicles, $this->fleet);
	}

	private function vehicle(): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'uuid' => self::VEHICLE,
			'user_id' => self::OWNER,
			'odo_unit' => 'km',
			'updated_at' => 1750000000,
		]);
	}

	/**
	 * The vehicle's odometer as the timeline shows it: what each Reading holds, and whether it is
	 * still waiting for its follow-up question.
	 *
	 * @return array<int, bool>
	 */
	private function chain(): array {
		$chain = [];
		foreach ($this->ordered(self::VEHICLE_ID) as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}

		return $chain;
	}

	/**
	 * A trip as the sheet posts one, ending on a counter somebody read.
	 *
	 * @return array<string, mixed>
	 */
	private function drove(int $startedAt, int $endOdo): array {
		return [
			'started_at' => $startedAt,
			'started_at_off' => 120,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 120,
			'end_odo' => $endOdo,
			'category' => Trip::BUSINESS,
		];
	}

	/**
	 * A trip as the sheet posts one with the toggle the other way round: a distance, and no counter
	 * at either end. How long it ran matters to where its Reading lands, so a case that cares says.
	 *
	 * @return array<string, mixed>
	 */
	private function covered(int $startedAt, int $distance, int $ranFor = 5400): array {
		return [
			'started_at' => $startedAt,
			'started_at_off' => 120,
			'ended_at' => $startedAt + $ranFor,
			'ended_at_off' => 120,
			'distance' => $distance,
			'category' => Trip::BUSINESS,
		];
	}

	/**
	 * The whole of this task: the journey is stored, and the counter it ended on is what the
	 * vehicle then shows. Nothing counts anything up - the Reading is the number that was read.
	 */
	public function testATripEndingOnACounterMovesTheVehiclesKilometres(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame(120450, $trip->getEndOdo());
		$this->assertSame(120450, $this->cached);
	}

	/**
	 * Rule 5: one Entry, one Reading, at the moment the journey ended - not the moment it started,
	 * which is where a trip that runs past midnight would otherwise land.
	 */
	public function testTheOneReadingATripWritesIsAtItsEnd(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertCount(1, $this->readings);
		$reading = $this->readings[0];
		$this->assertSame(1750005400, $reading->getReadAt());
		$this->assertSame(120, $reading->getReadAtOff());
		$this->assertSame(120450, $reading->getValue());
		$this->assertSame(OdometerService::OBSERVED, $reading->getOrigin());
		$this->assertFalse($reading->getFlagged());
	}

	/**
	 * The Reading names the trip it came from, so the timeline can show one row for the two and an
	 * erasure can find both (docs/architecture.md#data-model).
	 */
	public function testTheReadingNamesTheTripItCameFrom(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame('trip', $this->readings[0]->getSourceType());
		$this->assertSame((int)$trip->getId(), $this->readings[0]->getSourceId());
	}

	/**
	 * `start_odo` is a claim about the counter and not a Reading (rule 5): comparing the two is
	 * what gap detection is made of, and writing it as a Reading would leave nothing to compare.
	 */
	public function testTheStartingCounterIsKeptAsAClaimAndWritesNoReading(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'start_odo' => 120310,
			'end_odo' => 120450,
			'category' => Trip::BUSINESS,
		]);

		$this->assertSame(120310, $trip->getStartOdo());
		$this->assertCount(1, $this->readings);
		$this->assertSame(120450, $this->readings[0]->getValue());
	}

	/**
	 * Rule 6: a driver who types the kilometres driven instead of the counter still moves the
	 * vehicle. The Reading is counted, so it says so - and `start_odo` stays empty, because a
	 * distance says nothing about where the counter stood.
	 */
	public function testADistanceOnlyTripCountsItsReadingFromTheOneBeforeIt(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 140));

		$this->assertSame(140, $trip->getDistance());
		$this->assertNull($trip->getEndOdo());
		$this->assertNull($trip->getStartOdo());
		$this->assertCount(2, $this->readings);
		$this->assertSame(1750105400, $this->readings[1]->getReadAt());
		$this->assertSame(120590, $this->readings[1]->getValue());
		$this->assertSame(OdometerService::DERIVED, $this->readings[1]->getOrigin());
		$this->assertSame(120590, $this->cached);
	}

	/**
	 * "Latest reading at or before `started_at`", not the newest one there is: a trip entered three
	 * days late is dated three days back (rule 1), so the row it counts from is the one the vehicle
	 * stood on when this journey began.
	 */
	public function testTheDistanceIsCountedFromWhereTheVehicleStoodWhenTheJourneyBegan(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750200000, 121000));

		$this->service()->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 140));

		$derived = end($this->readings);
		$this->assertSame(120590, $derived->getValue());
		// The counted row lands between the two observed ones, so what the vehicle shows is still
		// the newest reading and not the one just written.
		$this->assertSame(121000, $this->cached);
	}

	/**
	 * The shape only a trip can make. A Reading is written at `ended_at` and counted from
	 * `started_at` (rules 5 and 6), so a long journey's counted row lands after counters that were
	 * read while it was still running - and below them, because the kilometres it added were counted
	 * from before they were read. Observed beats derived: the counted row is flagged, the counter
	 * nobody computed stands, and neither number is rewritten.
	 *
	 * An Odometer Entry counts from the moment it is written at and can never produce this.
	 */
	public function testACounterReadDuringALongTripDiscreditsWhatThatTripCounted(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750100000, 120500));

		$service->record(self::OWNER, self::VEHICLE, $this->covered(1750006000, 100, 194000));

		$this->assertSame([120000 => false, 120500 => false, 120100 => true], $this->chain());
		// Rule 2 all the same: what the vehicle shows is the newest row by date, flag or no flag.
		$this->assertSame(120100, $this->cached);
	}

	/**
	 * There is one chain, whatever wrote it. An Odometer Entry somebody typed discredits the
	 * Readings a distance-only trip left behind exactly as a trip's own counter does - the rows are
	 * judged by where their number came from, never by which table it names.
	 */
	public function testAnOdometerEntryDiscreditsTheCountedRowsATripLeftBehind(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));

		$this->odometer()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750200000,
			'read_at_off' => 120,
			'value' => 120100,
		]);

		$this->assertSame([120000 => false, 120400 => true, 120100 => false], $this->chain());
		$this->assertSame(
			['trip', 'trip', 'manual'],
			array_map(
				static fn (OdoReading $reading): string => $reading->getSourceType(),
				$this->ordered(self::VEHICLE_ID),
			),
		);
	}

	/** The trip is written under the vehicle the route named, and the person who posted it. */
	public function testATripBelongsToTheVehicleAndTheDriverWhoEnteredIt(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame(self::VEHICLE_ID, $this->trips[0]->getVehicleId());
		$this->assertSame(self::OWNER, $this->trips[0]->getCreatedBy());
		$this->assertSame(self::VEHICLE_ID, $this->readings[0]->getVehicleId());
		$this->assertSame(self::OWNER, $this->readings[0]->getCreatedBy());
	}

	/** Where the journey went and what it was for, kept as entered. */
	public function testWhatTheJourneyWasForIsStoredAsEntered(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450) + [
			'from_label' => 'Köln, Hauptbahnhof',
			'to_label' => 'Düsseldorf, Königsallee 12',
			'purpose' => 'Kundentermin',
			'partner' => 'Meyer GmbH',
		]);

		$this->assertSame('Köln, Hauptbahnhof', $trip->getFromLabel());
		$this->assertSame('Düsseldorf, Königsallee 12', $trip->getToLabel());
		$this->assertSame('Kundentermin', $trip->getPurpose());
		$this->assertSame('Meyer GmbH', $trip->getPartner());
	}

	/**
	 * A Reconciliation Trip is one the app created to close a Gap (CONTEXT.md), and the audit trail
	 * says it was derived. A client that could set the flag could dress a hand-typed trip as one.
	 */
	public function testAClientCannotDeclareItsOwnTripReconciled(): void {
		$trip = $this->service()->record(
			self::OWNER,
			self::VEHICLE,
			$this->drove(1750000000, 120450) + ['reconciled' => true],
		);

		$this->assertFalse($trip->getReconciled());
	}

	/**
	 * A column a property default merely agrees with is not dirty, so QBMapper leaves it out of
	 * the INSERT and the NOT NULL constraint refuses the row.
	 *
	 * @dataProvider notNullColumns
	 */
	public function testATripWritesEveryColumnTheDatabaseWillNotDefault(string $property): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertArrayHasKey($property, $this->trips[0]->getUpdatedFields());
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function notNullColumns(): iterable {
		yield 'vehicle_id' => ['vehicleId'];
		yield 'created_by' => ['createdBy'];
		yield 'started_at' => ['startedAt'];
		yield 'started_at_off' => ['startedAtOff'];
		yield 'ended_at' => ['endedAt'];
		yield 'ended_at_off' => ['endedAtOff'];
		yield 'category' => ['category'];
	}

	/**
	 * The database would refuse each of these too, but as a 500 that names no field. Refusing them
	 * here is what makes the answer a 400 the sheet can point at. It is not the same as blocking on
	 * a field a German logbook wants and the driver has not filled in - that one is a flag
	 * (docs/features.md#logbook-mode).
	 *
	 * @param array<string, mixed> $fields
	 * @dataProvider misshapenTrips
	 */
	public function testATripTheColumnsCannotHoldIsRefused(array $fields): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->record(self::OWNER, self::VEHICLE, $fields);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function misshapenTrips(): iterable {
		$whole = [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 120450,
			'category' => Trip::BUSINESS,
		];

		foreach (['started_at', 'started_at_off', 'ended_at', 'ended_at_off', 'category'] as $column) {
			$without = $whole;
			unset($without[$column]);
			yield 'no ' . $column => [$without];
		}

		$withoutCounter = $whole;
		unset($withoutCounter['end_odo']);
		yield 'neither a counter nor a distance' => [$withoutCounter];
		yield 'a counter and a distance at once' => [['distance' => 140] + $whole];
		// The one refusal that is about the vehicle rather than the request: rule 6 counts from a
		// Reading, and this vehicle has none yet.
		yield 'a distance counted from nothing' => [['distance' => 140] + $withoutCounter];
		yield 'a distance below zero' => [['distance' => -1] + $withoutCounter];
		yield 'a category nobody declares' => [['category' => 'holiday'] + $whole];
		yield 'a counter below zero' => [['end_odo' => -1] + $whole];
		yield 'a fractional counter' => [['end_odo' => '4.5'] + $whole];
		yield 'an offset no clock has' => [['ended_at_off' => 2000] + $whole];
		yield 'a destination longer than the column' => [['to_label' => str_repeat('a', 256)] + $whole];
		yield 'an end before its start' => [['ended_at' => 1749999999] + $whole];
	}

	/**
	 * A uuid is all it takes to name a vehicle, and everything hanging off one goes through the
	 * same gate (docs/security.md). Nothing is written on the way to the refusal.
	 */
	public function testAStrangerWritesNoTrip(): void {
		try {
			$this->service()->record(self::STRANGER, self::VEHICLE, $this->drove(1750000000, 120450));
			$this->fail('a stranger wrote a trip');
		} catch (AccessDeniedException) {
			$this->assertSame([], $this->trips);
			$this->assertSame([], $this->readings);
			$this->assertNull($this->cached);
		}
	}

	/**
	 * A co-driver who may look at the vehicle may not add to its logbook: a trip moves what the
	 * vehicle shows, so it asks to edit, not to view.
	 */
	public function testAGrantToLookIsNotAGrantToAddATrip(): void {
		$this->expectException(AccessDeniedException::class);

		$this->service()->record(self::DRIVER, self::VEHICLE, $this->drove(1750000000, 120450));
	}

	/**
	 * The trip and its Reading are one write. A vehicle whose counter did not move because the
	 * second statement failed is a logbook that silently disagrees with itself.
	 */
	public function testTheTripAndItsReadingAreOneTransaction(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
	}
}
