<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\Gaps;
use OCA\NextFleet\Service\LogbookPeriods;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Adding a trip: the journey is stored, and its end counter becomes one Reading at `ended_at`
 * (docs/architecture.md#odometer-rules, rule 5).
 *
 * The odometer is the real service: "the kilometres move" is about what the two do together, and
 * a mock could only repeat what the test assumed.
 */
class TripServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	/** What the gate hands back for that uuid, and so the id every row here is written under. */
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const STRANGER = 'bob';
	private const VIEWER = 'erin';

	/** The clock the fake mappers stamp a row with, and the token a client then holds. */
	private const ENTERED_AT = 1750500000;
	/** The moment a void lands, and the token it leaves behind. */
	private const VOIDED_AT = 1750600000;
	/** The moment an edit lands, and the token it leaves behind. */
	private const EDITED_AT = 1750700000;
	/** What the stub ruleset gives a journey before an edit about it is late. */
	private const LOCK_DELAY_DAYS = 7;
	/** When the Reading before a Gap was read: the end of the journey before it (gapped()). */
	private const GAP_FROM_AT = 1750005400;
	/** When the trip whose claim opened that Gap set off. */
	private const GAP_TO_AT = 1750100000;

	/** The trips the mapper stands for, in insertion order. @var list<Trip> */
	private array $trips = [];
	/** @var list<OdoReading> */
	private array $readings = [];
	/** The audit trail, in the order it was written. @var list<Audit> */
	private array $audits = [];
	/** Every write and every transaction boundary, in the order they happened. @var list<string> */
	private array $calls = [];
	private int $nextTripId = 1;
	private int $nextReadingId = 1;
	private ?int $cached = null;
	/** Whether the vehicle the gate hands back is under Logbook Mode. */
	private bool $logbookMode = false;
	/** The mode's flips on the vehicle's trail, oldest first. @var list<Audit> */
	private array $flips = [];
	/** Whether the vehicle's jurisdiction has a logbook ruleset at all. */
	private bool $ruleset = true;
	/** What the server's clock says when a write arrives. */
	private int $now = self::ENTERED_AT;
	private ?OdometerService $odometer = null;

	private TripMapper&MockObject $tripMapper;
	private OdoReadingMapper&MockObject $readingMapper;
	private AuditMapper&MockObject $auditMapper;
	private VehicleMapper&MockObject $vehicles;
	private VehicleService&MockObject $fleet;
	private IDBConnection&MockObject $db;

	protected function setUp(): void {
		$this->trips = [];
		$this->readings = [];
		$this->audits = [];
		$this->calls = [];
		$this->nextTripId = 1;
		$this->nextReadingId = 1;
		$this->cached = null;
		$this->logbookMode = false;
		$this->flips = [];
		$this->ruleset = true;
		$this->now = self::ENTERED_AT;
		$this->odometer = null;

		// Stores rather than expectations: a trip is judged by what the vehicle shows afterwards.
		$this->tripMapper = $this->createMock(TripMapper::class);
		$this->tripMapper->method('insert')->willReturnCallback(function (Trip $trip): Trip {
			$trip->setId($this->nextTripId);
			$trip->setUuid('0195e2f1-1111-4000-8000-00000000000' . $this->nextTripId++);
			$trip->setCreatedAt(self::ENTERED_AT);
			$trip->setUpdatedAt(self::ENTERED_AT);
			$this->trips[] = $trip;
			$this->calls[] = 'trip';

			return $trip;
		});
		$this->tripMapper->method('findByUuid')
			->willReturnCallback(fn (string $uuid): Trip => $this->tripNamed($uuid, false));
		$this->tripMapper->method('findAnyByUuid')
			->willReturnCallback(fn (string $uuid): Trip => $this->tripNamed($uuid, true));
		$this->tripMapper->method('findAllForVehicle')->willReturnCallback(function (int $vehicleId): array {
			$this->calls[] = 'trips read';
			$rows = array_values(array_filter(
				$this->trips,
				static fn (Trip $trip): bool => $trip->getVehicleId() === $vehicleId && $trip->getDeletedAt() === null,
			));
			usort($rows, static fn (Trip $a, Trip $b): int
				=> [$a->getStartedAt(), $a->getId()] <=> [$b->getStartedAt(), $b->getId()]);

			return $rows;
		});
		$this->tripMapper->method('softDelete')->willReturnCallback(
			function (Trip $trip, int $expectedUpdatedAt): Trip {
				$this->checked($trip, $expectedUpdatedAt, null);
				$trip->setDeletedAt(self::VOIDED_AT);
				$trip->setUpdatedAt(self::VOIDED_AT);
				$this->calls[] = 'void';

				return $trip;
			},
		);
		$this->tripMapper->method('updateChecked')->willReturnCallback(
			function (Trip $trip, int $expectedUpdatedAt): Trip {
				$this->checked($trip, $expectedUpdatedAt, null);
				$trip->setUpdatedAt(self::EDITED_AT);
				$this->calls[] = 'edit';

				return $trip;
			},
		);
		$this->tripMapper->method('restoreChecked')->willReturnCallback(
			function (Trip $trip, int $expectedUpdatedAt): Trip {
				// The mirror predicate, and the token stays where the void put it
				// (docs/architecture.md#concurrency).
				$this->checked($trip, $expectedUpdatedAt, self::VOIDED_AT);
				$trip->setDeletedAt(null);
				$this->calls[] = 'unvoid';

				return $trip;
			},
		);

		$this->auditMapper = $this->createMock(AuditMapper::class);
		$this->auditMapper->method('insert')->willReturnCallback(function (Audit $row): Audit {
			$this->audits[] = $row;
			$this->calls[] = 'audit';

			return $row;
		});
		$this->auditMapper->method('findForEntity')->willReturnCallback(
			fn (string $entity): array => $entity === Audit::VEHICLE ? $this->flips : [],
		);

		$this->readingMapper = $this->createMock(OdoReadingMapper::class);
		$this->readingMapper->method('insert')->willReturnCallback(
			function (OdoReading $reading): OdoReading {
				$reading->setId($this->nextReadingId);
				$reading->setUuid('0195e2f1-0000-4000-8000-00000000000' . $this->nextReadingId++);
				$reading->setCreatedAt(self::ENTERED_AT);
				$reading->setUpdatedAt(self::ENTERED_AT);
				$this->readings[] = $reading;

				return $reading;
			},
		);
		$this->readingMapper->method('flag')->willReturnCallback(
			static function (OdoReading $reading, bool $flagged): void {
				$reading->setFlagged($flagged);
			},
		);
		// Every Reading here is a trip's, so on the main chain.
		$this->readingMapper->method('findChain')
			->willReturnCallback(fn (int $vehicleId): array => $this->ordered($vehicleId));
		$this->readingMapper->method('findAnyForTrip')->willReturnCallback(
			function (int $vehicleId, int $tripId): ?OdoReading {
				foreach ($this->readings as $reading) {
					if ($reading->getVehicleId() === $vehicleId
						&& $reading->getSourceType() === OdoReading::TRIP
						&& $reading->getSourceId() === $tripId) {
						return $reading;
					}
				}

				return null;
			},
		);
		$this->readingMapper->method('softDelete')->willReturnCallback(
			function (OdoReading $reading, int $expectedUpdatedAt): OdoReading {
				$this->checked($reading, $expectedUpdatedAt, null);
				$reading->setDeletedAt(self::VOIDED_AT);
				$reading->setUpdatedAt(self::VOIDED_AT);
				$this->calls[] = 'reading';

				return $reading;
			},
		);
		$this->readingMapper->method('updateChecked')->willReturnCallback(
			function (OdoReading $reading, int $expectedUpdatedAt): OdoReading {
				$this->checked($reading, $expectedUpdatedAt, null);
				$reading->setUpdatedAt(self::EDITED_AT);
				$this->calls[] = 'reading';

				return $reading;
			},
		);
		$this->readingMapper->method('restoreChecked')->willReturnCallback(
			function (OdoReading $reading, int $expectedUpdatedAt): OdoReading {
				$this->checked($reading, $expectedUpdatedAt, self::VOIDED_AT);
				$reading->setDeletedAt(null);
				$this->calls[] = 'reading';

				return $reading;
			},
		);
		$this->readingMapper->method('findNewestAtOrBefore')->willReturnCallback(
			function (int $vehicleId, string $counter, int $readAt, ?int $except = null): ?OdoReading {
				$earlier = array_filter(
					$this->ordered($vehicleId),
					static fn (OdoReading $reading): bool
						=> $reading->getReadAt() <= $readAt && $reading->getId() !== $except,
				);

				return $earlier === [] ? null : end($earlier);
			},
		);

		$this->vehicles = $this->createMock(VehicleMapper::class);
		$this->vehicles->method('cacheOdoValue')
			->willReturnCallback(function (int $vehicleId, ?int $value): void {
				$this->cached = $value;
			});
		$this->vehicles->method('hold')->willReturnCallback(function (): void {
			$this->calls[] = 'hold';
		});

		// Who reaches which vehicle is VehicleAccessTest's; what this states is which operation a
		// trip asks the gate for. The Entry's own gate is the real rule, fed what reach() held.
		$this->fleet = $this->createMock(VehicleService::class);
		$this->fleet->method('reach')->willReturnCallback(
			function (string $userId, string $operation): Vehicle {
				$held = match ($userId) {
					self::OWNER => [VehicleAccess::VIEW, VehicleAccess::LOG, VehicleAccess::EDIT, VehicleAccess::DELETE, VehicleAccess::OWN],
					self::DRIVER => [VehicleAccess::VIEW, VehicleAccess::LOG],
					self::VIEWER => [VehicleAccess::VIEW],
					default => [],
				};
				if (!in_array($operation, $held, true)) {
					throw new AccessDeniedException();
				}
				$vehicle = $this->vehicle();
				$vehicle->setMay($held);

				return $vehicle;
			},
		);
		$access = new VehicleAccess($this->createMock(AccessMapper::class), $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class));
		$this->fleet->method('change')->willReturnCallback(
			function (string $userId, string $operation, Vehicle $vehicle, ?string $createdBy) use ($access): void {
				if (!$access->mayChange($userId, $operation, $vehicle, $createdBy)) {
					throw new AccessDeniedException();
				}
			},
		);

		// Both boundaries are recorded rather than counted, so a case can say not only that the
		// writes happened but that they happened between the two.
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->calls[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->calls[] = 'commit';
		});
	}

	/**
	 * @throws DoesNotExistException
	 */
	private function tripNamed(string $uuid, bool $anyState): Trip {
		foreach ($this->trips as $trip) {
			if ($trip->getUuid() === $uuid && ($anyState || $trip->getDeletedAt() === null)) {
				return $trip;
			}
		}

		throw new DoesNotExistException('no trip ' . $uuid);
	}

	/**
	 * What every checked write is checked against (docs/architecture.md#concurrency): the row as
	 * the client read it, in the state the statement's predicate names.
	 *
	 * @throws StaleUpdateException
	 */
	private function checked(BaseEntity $row, int $expectedUpdatedAt, ?int $deletedAt): void {
		if ($row->getUpdatedAt() !== $expectedUpdatedAt || $row->getDeletedAt() !== $deletedAt) {
			throw new StaleUpdateException('row ' . $row->getId() . ' is not the row that was read');
		}
	}

	/**
	 * One vehicle's live readings in rule-1 order, as both mapper reads answer.
	 *
	 * @return list<OdoReading>
	 */
	private function ordered(int $vehicleId): array {
		$rows = array_values(array_filter(
			$this->readings,
			static fn (OdoReading $reading): bool
				=> $reading->getVehicleId() === $vehicleId && $reading->getDeletedAt() === null,
		));
		usort($rows, static fn (OdoReading $a, OdoReading $b): int
			=> [$a->getReadAt(), $a->getId()] <=> [$b->getReadAt(), $b->getId()]);

		return $rows;
	}

	private function service(): TripService {
		return new TripService(
			$this->tripMapper,
			$this->auditMapper,
			$this->odometer(),
			$this->fleet,
			$this->jurisdictions(),
			$this->clock(),
			new Gaps($this->tripMapper, $this->readingMapper),
			$this->vehicles,
			$this->createMock(BookingService::class),
			$this->db,
			new LogbookPeriods($this->auditMapper),
		);
	}

	/** The mode was on from `$on` to `$off` and is off now, as VehicleService's flips say. */
	private function wasOn(int $on, int $off): void {
		foreach ([[false, true, $on], [true, false, $off]] as [$before, $after, $at]) {
			$this->flips[] = Audit::fromRow([
				'entity' => Audit::VEHICLE,
				'entity_id' => self::VEHICLE_ID,
				'diff_json' => json_encode(['change' => 'switched', 'fields' => ['logbook_mode' => [$before, $after]]]),
				'created_at' => $at,
				'created_by' => self::OWNER,
			]);
		}
	}

	/** Every jurisdiction answers with one ruleset, or with none when a case says so. */
	private function jurisdictions(): Jurisdictions {
		$rules = $this->createMock(ILogbookRules::class);
		$rules->method('lockDelayDays')->willReturn(self::LOCK_DELAY_DAYS);
		$profile = $this->createMock(IJurisdiction::class);
		$profile->method('logbookRules')->willReturnCallback(fn (): ?ILogbookRules => $this->ruleset ? $rules : null);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($profile);

		return new Jurisdictions($container);
	}

	private function clock(): ITimeFactory {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $clock;
	}

	/** Shared, as the container's is: an Odometer Entry a case writes sees the trips' Readings. */
	private function odometer(): OdometerService {
		return $this->odometer ??= new OdometerService($this->readingMapper, $this->vehicles, $this->fleet, $this->db);
	}

	private function vehicle(): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'uuid' => self::VEHICLE,
			'user_id' => self::OWNER,
			'odo_unit' => 'km',
			'logbook_mode' => $this->logbookMode,
			'updated_at' => 1750000000,
		]);
	}

	/**
	 * Each live Reading's value and whether it is flagged.
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
	 * A trip posted with a distance and no counter. `$ranFor` moves where its Reading lands.
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

	/** Nothing counts up: the vehicle shows the number that was read. */
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
	 * A distance trip's Reading is written at `ended_at` but counted from `started_at` (rules 5
	 * and 6), so a long journey can land after, and below, a counter read while it ran. Observed
	 * beats derived: the counted row is flagged, and neither number is rewritten. Only a trip can
	 * make this shape.
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
	 * One chain, whatever wrote it: rows are judged by where their number came from, never by which
	 * table they name.
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

	public function testATripBelongsToTheVehicleAndTheDriverWhoEnteredIt(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame(self::VEHICLE_ID, $this->trips[0]->getVehicleId());
		$this->assertSame(self::OWNER, $this->trips[0]->getCreatedBy());
		$this->assertSame(self::VEHICLE_ID, $this->readings[0]->getVehicleId());
		$this->assertSame(self::OWNER, $this->readings[0]->getCreatedBy());
	}

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
	 * Only the app creates a Reconciliation Trip (CONTEXT.md); a client setting the flag could
	 * dress a hand-typed trip as one.
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
	 * The database would refuse these as a 500 naming no field; refused here, they are a 400 the
	 * sheet can point at. A field the logbook wants but the driver left empty is a flag instead
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
	 * Two counters the wrong way round are a typo, and a negative trip would lower every sum it
	 * lands in. The refusal names its reason, so the sheet can say it in the driver's words.
	 */
	public function testAnEndCounterBelowTheStartIsRefusedWithItsReason(): void {
		try {
			$this->service()->record(self::OWNER, self::VEHICLE, ['start_odo' => 120500] + $this->drove(1750000000, 120450));
			$this->fail('a trip that drove backwards was written');
		} catch (RefusedException $e) {
			$this->assertSame('end_below_start', $e->reason);
			$this->assertSame([], $this->trips);
		}
	}

	public function testAnEndCounterEqualToTheStartIsAStandingTrip(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, ['start_odo' => 120450] + $this->drove(1750000000, 120450));

		$this->assertSame(0, $trip->kilometres());
	}

	/**
	 * A day of slack covers every clock and offset a phone gets wrong; past it the arrival is a
	 * typo in the year, and it would date a Reading ahead of every later one.
	 */
	public function testAnArrivalMoreThanADayAheadIsRefusedWithItsReason(): void {
		try {
			$this->service()->record(self::OWNER, self::VEHICLE, ['ended_at' => self::ENTERED_AT + 86401] + $this->drove(1750000000, 120450));
			$this->fail('a trip arriving next week was written');
		} catch (RefusedException $e) {
			$this->assertSame('ends_in_future', $e->reason);
			$this->assertSame([], $this->trips);
		}
	}

	public function testAnArrivalADayAheadIsStillTaken(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, ['ended_at' => self::ENTERED_AT + 86400] + $this->drove(1750000000, 120450));

		$this->assertSame(self::ENTERED_AT + 86400, $trip->getEndedAt());
	}

	public function testAnEditIsHeldToTheSameArithmetic(): void {
		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->expectException(RefusedException::class);
		$this->service()->update(self::OWNER, self::VEHICLE, (string)$trip->getUuid(), self::ENTERED_AT, ['start_odo' => 120451] + $this->drove(1750000000, 120450));
	}

	/** A uuid names a vehicle, so every write passes the gate first (docs/security.md). */
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

	/** Somebody who may only look at the vehicle may not add to its logbook: a trip takes `log`. */
	public function testAGrantToLookIsNotAGrantToAddATrip(): void {
		$this->expectException(AccessDeniedException::class);

		$this->service()->record(self::VIEWER, self::VEHICLE, $this->drove(1750000000, 120450));
	}

	/**
	 * Under Logbook Mode a trip is recorded as written (docs/features.md#logbook-mode): the row
	 * names the trip by table and id, and carries its own author and instant.
	 */
	public function testATripWrittenUnderLogbookModeLeavesAnAuditRow(): void {
		$this->logbookMode = true;

		$trip = $this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertCount(1, $this->audits);
		$this->assertSame(Audit::TRIP, $this->audits[0]->getEntity());
		$this->assertSame((int)$trip->getId(), $this->audits[0]->getEntityId());
		$this->assertSame(self::OWNER, $this->audits[0]->getCreatedBy());
	}

	/**
	 * Off the mode there is no trail: a private driver never asked for one, and a row would claim
	 * an integrity the records do not have (docs/adr/0003-logbook-mode-does-not-lock-the-past.md).
	 */
	public function testATripOnAVehicleWithoutTheModeLeavesNoAuditRow(): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame([], $this->audits);
	}

	/**
	 * The diff is each stated field as `[null, value]`; author and dating are the audit row's own
	 * columns, not in it.
	 */
	public function testTheAuditRowCarriesTheFieldsTheTripWasWrittenWith(): void {
		$this->logbookMode = true;

		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450) + [
			'to_label' => 'Düsseldorf, Königsallee 12',
			'purpose' => 'Kundentermin',
		]);

		$this->assertSame([
			'change' => 'created',
			'fields' => [
				'started_at' => [null, 1750000000],
				'started_at_off' => [null, 120],
				'ended_at' => [null, 1750005400],
				'ended_at_off' => [null, 120],
				'end_odo' => [null, 120450],
				'to_label' => [null, 'Düsseldorf, Königsallee 12'],
				'purpose' => [null, 'Kundentermin'],
				'category' => [null, 'business'],
			],
			'updated_at' => self::ENTERED_AT,
		], $this->audits[0]->getDiffJson());
	}

	/**
	 * A field the driver left empty did not change, so it is not in the diff: a trail that lists
	 * what stayed as it was buries what did not. `reconciled` is false rather than absent when
	 * nobody touched it, and is left out for the same reason.
	 */
	public function testAFieldTheDriverLeftEmptyIsNotInTheDiff(): void {
		$this->logbookMode = true;

		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame(
			['started_at', 'started_at_off', 'ended_at', 'ended_at_off', 'end_odo', 'category'],
			array_keys($this->audits[0]->getDiffJson()['fields']),
		);
	}

	/**
	 * The trail's field names are read off the wire form, the one place the app spells out a trip's
	 * columns. A change to the wire form fails here rather than quietly giving later rows a
	 * different field set.
	 */
	public function testTheAuditRowNamesEveryColumnATripCarries(): void {
		$this->logbookMode = true;

		$this->service()->record(self::OWNER, self::VEHICLE, [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'start_odo' => 120310,
			'end_odo' => 120450,
			'from_label' => 'Köln, Hauptbahnhof',
			'to_label' => 'Düsseldorf, Königsallee 12',
			'purpose' => 'Kundentermin',
			'partner' => 'Meyer GmbH',
			'category' => Trip::BUSINESS,
		]);

		// `distance` is missing because a trip states its end once: this one is a counter trip
		// (rule 6). `reconciled` is missing because only the app sets it, in a task of its own.
		$this->assertSame(
			[
				'started_at', 'started_at_off', 'ended_at', 'ended_at_off', 'start_odo', 'end_odo',
				'from_label', 'to_label', 'purpose', 'partner', 'category',
			],
			array_keys($this->audits[0]->getDiffJson()['fields']),
		);
	}

	/**
	 * The row and the change are one write: a trail that outlives a rolled-back trip, or a trip
	 * without its row, records something that did not happen.
	 */
	public function testTheAuditRowIsWrittenInsideTheSameTransactionAsTheTrip(): void {
		$this->logbookMode = true;

		$this->service()->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->assertSame('begin', $this->calls[0]);
		$this->assertSame('commit', end($this->calls));
		$this->assertSame(['hold', 'trip', 'audit'], array_slice($this->calls, 1, -1));
	}

	/**
	 * A delete voids, it does not remove (docs/features.md#logbook-mode): the trash, the undo and
	 * the Fahrtenbuch export read the row back through `deleted_at`.
	 */
	public function testDeletingATripVoidsItRatherThanRemovingIt(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertSame(self::VOIDED_AT, $voided->getDeletedAt());
		$this->assertCount(1, $this->trips);
		$this->assertSame($trip->getUuid(), $this->trips[0]->getUuid());
	}

	/**
	 * The trip and its Reading are one fact (rule 5), so the Reading goes where the trip goes; left
	 * standing, it would hold the vehicle's kilometres on a row nothing explains.
	 */
	public function testTheReadingAVoidedTripLeftGoesWithIt(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750100000, 120500));

		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertSame([120000 => false], $this->chain());
		$this->assertSame(120000, $this->cached);
	}

	/**
	 * The chain is judged again once a row leaves it (rule 3): with the discrediting counter gone,
	 * the counted rows stand again.
	 */
	public function testTheRowsAVoidedCounterDiscreditedStandAgain(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));
		$doubted = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750200000, 120100));
		$this->assertSame([120000 => false, 120400 => true, 120100 => false], $this->chain());

		$service->delete(self::OWNER, self::VEHICLE, $doubted->getUuid(), $doubted->getUpdatedAt());

		$this->assertSame([120000 => false, 120400 => false], $this->chain());
		$this->assertSame(120400, $this->cached);
	}

	/**
	 * A void is recorded like any change, with `deleted_at` as the diff. The row's own instant is
	 * when the trail was written; the two differ only when one is wrong.
	 */
	public function testAVoidUnderLogbookModeIsRecordedInTheTrail(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertCount(2, $this->audits);
		$void = $this->audits[1];
		$this->assertSame(Audit::TRIP, $void->getEntity());
		$this->assertSame((int)$trip->getId(), $void->getEntityId());
		$this->assertSame(self::OWNER, $void->getCreatedBy());
		$this->assertSame(
			['change' => 'voided', 'fields' => ['deleted_at' => [null, self::VOIDED_AT]], 'late' => false, 'updated_at' => self::VOIDED_AT],
			$void->getDiffJson(),
		);
	}

	/**
	 * A void past the lock delay takes a line out of a logbook that was already kept, so its row
	 * says it was late - measured from the end of the journey, the way an edit's is.
	 */
	public function testAVoidPastTheLockDelayIsMarkedLate(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + self::LOCK_DELAY_DAYS * 86400 + 1;

		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertTrue($this->audits[1]->getDiffJson()['late']);
	}

	/**
	 * An undo months later puts a line back that an auditor may have read as voided, so it is late
	 * too - even when the void it undoes was not.
	 */
	public function testARestorePastTheLockDelayIsMarkedLate(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());
		$this->now = $trip->getEndedAt() + self::LOCK_DELAY_DAYS * 86400 + 1;

		$service->restore(self::OWNER, self::VEHICLE, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertFalse($this->audits[1]->getDiffJson()['late']);
		$this->assertTrue($this->audits[2]->getDiffJson()['late']);
	}

	/**
	 * Off the mode a delete is a delete: the row is still kept, because that is what `deleted_at`
	 * is for, but nobody is keeping evidence and no trail claims otherwise.
	 */
	public function testAVoidOnAVehicleWithoutTheModeLeavesNoAuditRow(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertSame(self::VOIDED_AT, $voided->getDeletedAt());
		$this->assertSame([], $this->audits);
	}

	/**
	 * The three writes a void is - the stamp, the row that records it, the Reading that goes with
	 * the trip - are one transaction, for the reason the trip and its Reading are.
	 */
	public function testTheVoidItsTrailAndItsReadingAreOneTransaction(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$entering = count($this->calls);

		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$this->assertSame(
			['begin', 'hold', 'void', 'audit', 'reading', 'commit'],
			array_slice($this->calls, $entering),
		);
	}

	/**
	 * Voiding the row a distance was counted from rewrites nothing: rule 6 never corrects a derived
	 * value, and a recomputed number would be a counter nobody read.
	 */
	public function testVoidingTheRowADistanceWasCountedFromLeavesTheCountedNumberAlone(): void {
		$service = $this->service();
		$base = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));

		$service->delete(self::OWNER, self::VEHICLE, $base->getUuid(), $base->getUpdatedAt());

		$this->assertSame([120400 => false], $this->chain());
		$this->assertSame(120400, $this->cached);
	}

	/**
	 * Undo, the gesture this app deletes with everywhere (docs/ui.md), and it brings back both
	 * rows: a trip whose counter stayed in the trash would be a journey the vehicle never drove.
	 * The token is the one the void answered with, which is the one the undo toast holds.
	 */
	public function testUndoBringsBackTheTripAndTheCounterItEndedOn(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750100000, 120500));
		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$back = $service->restore(self::OWNER, self::VEHICLE, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertNull($back->getDeletedAt());
		$this->assertSame([120000 => false, 120500 => false], $this->chain());
		$this->assertSame(120500, $this->cached);
	}

	/**
	 * An undo is a change like any other, so it is recorded like any other. A trail that stopped
	 * at the void would leave an auditor reading "voided" over a trip the export lists as driven,
	 * and nothing to say which of the two happened last.
	 */
	public function testAnUndoUnderLogbookModeIsRecordedInTheTrailToo(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());

		$service->restore(self::OWNER, self::VEHICLE, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertCount(3, $this->audits);
		$this->assertSame(
			['change' => 'restored', 'fields' => ['deleted_at' => [self::VOIDED_AT, null]], 'late' => false, 'updated_at' => self::VOIDED_AT],
			$this->audits[2]->getDiffJson(),
		);
		$this->assertSame(self::OWNER, $this->audits[2]->getCreatedBy());
	}

	/**
	 * A driver may take out of the logbook only what they put in, and undo takes the right the
	 * void took.
	 *
	 * @dataProvider voids
	 */
	public function testADriverVoidsNoTripSomebodyElseEntered(string $method): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->expectException(AccessDeniedException::class);

		$service->$method(self::DRIVER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());
	}

	/** Their own they void and bring back, under Logbook Mode as anybody does, and the trail says who. */
	public function testADriverVoidsAndRestoresATripTheyEntered(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::DRIVER, self::VEHICLE, $this->drove(1750000000, 120450));

		$voided = $service->delete(self::DRIVER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());
		$back = $service->restore(self::DRIVER, self::VEHICLE, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertNull($back->getDeletedAt());
		$this->assertSame(
			[[self::DRIVER, 'created'], [self::DRIVER, 'voided'], [self::DRIVER, 'restored']],
			array_map(static fn (Audit $row): array => [$row->getCreatedBy(), $row->getDiffJson()['change']], $this->audits),
		);
	}

	/**
	 * A refusal writes nothing: the trip stands, and so does the counter.
	 *
	 * @dataProvider voids
	 */
	public function testAStrangerVoidsNothing(string $method): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		try {
			$service->$method(self::STRANGER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());
			$this->fail('a stranger reached a trip');
		} catch (AccessDeniedException) {
			$this->assertNull($this->trips[0]->getDeletedAt());
			$this->assertSame(120450, $this->cached);
			$this->assertSame([], $this->audits);
		}
	}

	/**
	 * A trip is reached through the vehicle it hangs off, and only through it. The uuid alone
	 * would otherwise be a second way in: one vehicle of their own is all somebody would need to
	 * take a journey out of anybody else's logbook.
	 *
	 * @dataProvider voids
	 */
	public function testATripOfAnotherVehicleIsNotReachableThroughThisOne(string $method): void {
		$elsewhere = $this->onAnotherVehicle();

		$this->expectException(DoesNotExistException::class);

		$this->service()->$method(self::OWNER, self::VEHICLE, $elsewhere->getUuid(), self::ENTERED_AT);
	}

	/**
	 * Both ways out of the trash, walked by every case about who may use them: they take the same
	 * right and reach the same rows.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function voids(): iterable {
		yield 'delete' => ['delete'];
		yield 'restore' => ['restore'];
	}

	/**
	 * One trip on a vehicle this route does not name, straight into the store - the service can
	 * only write trips on the vehicle its gate hands back, which is the one it must not be.
	 */
	private function onAnotherVehicle(): Trip {
		$trip = new Trip();
		$trip->setId(99);
		$trip->setUuid('0195e2f1-2222-4000-8000-000000000099');
		$trip->setVehicleId(self::VEHICLE_ID + 1);
		$trip->setCreatedBy(self::OWNER);
		$trip->setCreatedAt(self::ENTERED_AT);
		$trip->setUpdatedAt(self::ENTERED_AT);
		$this->trips[] = $trip;

		return $trip;
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

	/**
	 * An edit changes the trip it names and writes no second one: append-only is the audit row, not
	 * a second trip row. The token moves, so the client holds the one the next write is checked
	 * against.
	 */
	public function testAnEditRewritesTheTripInPlace(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$edited = $service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin'],
		);

		$this->assertCount(1, $this->trips);
		$this->assertSame($trip->getUuid(), $edited->getUuid());
		$this->assertSame('Kundentermin', $edited->getPurpose());
		$this->assertSame(self::EDITED_AT, $edited->getUpdatedAt());
	}

	/**
	 * The request is the whole trip, as the sheet posts it: a field it leaves out is a field the
	 * driver emptied. That is what lets a distance trip become a counter trip - the one answer a
	 * business trip asked for both counters has (docs/features.md#logbook-mode).
	 */
	public function testAnEditCanTurnADistanceTripIntoACounterTrip(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));

		$edited = $service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750100000, 120390) + ['start_odo' => 120000],
		);

		$this->assertNull($edited->getDistance());
		$this->assertSame(120390, $edited->getEndOdo());
		$this->assertSame(120000, $edited->getStartOdo());
	}

	/**
	 * The trip and its Reading are one fact (rule 5), so a journey restated is a Reading restated:
	 * still the one row, now holding what the trip says, and the vehicle shows it.
	 */
	public function testAnEditMovesTheReadingTheTripLeftOnTheCounter(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750100000, 120390),
		);

		$this->assertCount(2, $this->readings);
		$this->assertSame(120390, $this->readings[1]->getValue());
		$this->assertSame(OdometerService::OBSERVED, $this->readings[1]->getOrigin());
		$this->assertSame(120390, $this->cached);
	}

	/**
	 * A distance counts from the Reading before the journey, never from the journey's own: a trip
	 * moved past where it used to end would otherwise find its old Reading before its new start and
	 * count the kilometres twice.
	 */
	public function testARestatedDistanceIsNotCountedFromTheTripsOwnReading(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->covered(1750106000, 400),
		);

		$this->assertSame([120000 => false, 120400 => false], $this->chain());
		$this->assertSame(1750111400, $this->readings[1]->getReadAt());
	}

	/**
	 * An edit that leaves the journey where it was leaves its Reading alone. A counted number is
	 * never recomputed on its own (rule 6): an Odometer Entry typed in since would otherwise move a
	 * distance trip's Reading because somebody fixed its purpose.
	 */
	public function testAnEditThatLeavesTheJourneyAloneLeavesItsReadingAlone(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));
		$this->odometer()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750050000,
			'read_at_off' => 120,
			'value' => 120200,
		]);

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->covered(1750100000, 400) + ['purpose' => 'Kundentermin'],
		);

		$this->assertSame(120400, $this->readings[1]->getValue());
		$this->assertSame(self::ENTERED_AT, $this->readings[1]->getUpdatedAt());
	}

	/**
	 * When only the journey's end moves, the Reading moves with it and keeps its number: a distance
	 * counts from where the journey began, which the end has no say in.
	 */
	public function testMovingOnlyTheEndMovesTheReadingAndKeepsItsNumber(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->covered(1750100000, 400));
		$this->odometer()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750050000,
			'read_at_off' => 120,
			'value' => 120200,
		]);

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			['ended_at_off' => 60] + $this->covered(1750100000, 400),
		);

		$this->assertSame(60, $this->readings[1]->getReadAtOff());
		$this->assertSame(120400, $this->readings[1]->getValue());
	}

	/**
	 * Under the mode an edit is recorded like every other change, and the diff is what changed -
	 * each column as `[before, after]`, nothing that stayed as it was. Inside the delay it says so.
	 */
	public function testAnEditUnderLogbookModeRecordsWhatChanged(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450) + [
			'purpose' => 'Kundentermin',
		]);

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120460) + ['partner' => 'Meyer GmbH'],
		);

		$this->assertCount(2, $this->audits);
		$edit = $this->audits[1];
		$this->assertSame(Audit::TRIP, $edit->getEntity());
		$this->assertSame((int)$trip->getId(), $edit->getEntityId());
		$this->assertSame(self::OWNER, $edit->getCreatedBy());
		$this->assertSame([
			'change' => 'edited',
			'fields' => [
				'end_odo' => [120450, 120460],
				'purpose' => ['Kundentermin', null],
				'partner' => [null, 'Meyer GmbH'],
			],
			'late' => false,
			'updated_at' => self::EDITED_AT,
		], $edit->getDiffJson());
	}

	/** The mode refuses no write; it only marks the late one. */
	public function testAnEditPastTheLockDelayIsAllowedAndMarkedLate(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + self::LOCK_DELAY_DAYS * 86400 + 1;

		$edited = $service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin'],
		);

		$this->assertSame('Kundentermin', $edited->getPurpose());
		$this->assertTrue($this->audits[1]->getDiffJson()['late']);
	}

	/** The last second of the delay still counts as timely: late is after it, not at it. */
	public function testAnEditOnTheLastSecondOfTheDelayIsTimely(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + self::LOCK_DELAY_DAYS * 86400;

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin'],
		);

		$this->assertFalse($this->audits[1]->getDiffJson()['late']);
	}

	/**
	 * The trail follows the trip, not the switch: a trip that set off while the mode was on is in a
	 * logbook somebody kept, and switching the mode off must not open a window to change it unseen.
	 */
	public function testATripSetOffUnderTheModeIsRecordedAfterTheModeWentOff(): void {
		$this->wasOn(1749000000, 1751000000);
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$edited = $service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin']);
		$voided = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $edited->getUpdatedAt());
		$service->restore(self::OWNER, self::VEHICLE, $trip->getUuid(), $voided->getUpdatedAt());

		$this->assertSame(
			['created', 'edited', 'voided', 'restored'],
			array_map(static fn (Audit $row): string => $row->getDiffJson()['change'], $this->audits),
		);
	}

	/**
	 * A trip that never fell under the mode keeps no trail while it is off: nobody who never kept a
	 * logbook has old trip text kept.
	 */
	public function testATripSetOffOutsideEveryPeriodLeavesNoRowWhileTheModeIsOff(): void {
		$this->wasOn(1749000000, 1749500000);
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$edited = $service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin']);
		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $edited->getUpdatedAt());

		$this->assertSame([], $this->audits);
	}

	/** An edit that moves a trip out of the period is still a change to a trip that was in it. */
	public function testAnEditMovingATripOutOfAPeriodIsRecorded(): void {
		$this->wasOn(1749000000, 1751000000);
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		// Edited once the trip it is moved to has happened.
		$this->now = 1752100000;

		$service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1752000000, 120450));

		$this->assertCount(2, $this->audits);
		$this->assertSame([1750000000, 1752000000], $this->audits[1]->getDiffJson()['fields']['started_at']);
	}

	/**
	 * Each row says which token the trip was left with, so the export can tell a trip changed
	 * without a row (LogbookExport): a second save in the same second moves the token past the
	 * row's own `created_at`.
	 */
	public function testEveryRowCarriesTheTokenTheChangeLeftTheTripWith(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$edited = $service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1750000000, 120460));
		$service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $edited->getUpdatedAt());

		$this->assertSame(
			[self::ENTERED_AT, self::EDITED_AT, self::VOIDED_AT],
			array_map(static fn (Audit $row): int => $row->getDiffJson()['updated_at'], $this->audits),
		);
	}

	/**
	 * A save that changed nothing writes nothing: no token moved without an audit row - which the
	 * export would print as a change nobody recorded - and no audit row, since a trail of
	 * non-changes buries the changes an auditor is looking for.
	 */
	public function testASaveThatChangesNothingLeavesTheTripAsItWas(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->calls = [];

		$same = $service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1750000000, 120450));

		$this->assertSame(self::ENTERED_AT, $same->getUpdatedAt());
		$this->assertNotContains('edit', $this->calls);
		$this->assertCount(1, $this->audits);
	}

	/** ...and is still checked: a stale token is refused whether or not anything changed. */
	public function testAStaleSaveThatChangesNothingIsStillRefused(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->expectException(StaleUpdateException::class);
		$service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt() - 1, $this->drove(1750000000, 120450));
	}

	/**
	 * The delay runs from the journey as it was recorded, and an edit cannot restart it by moving
	 * the journey: a late correction that also re-dates the trip to yesterday is still late.
	 */
	public function testMovingTheJourneyLaterDoesNotHideALateEdit(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + self::LOCK_DELAY_DAYS * 86400 + 1;

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove($this->now - 86400, 120450),
		);

		$this->assertTrue($this->audits[1]->getDiffJson()['late']);
	}

	/**
	 * The other way round: a fresh trip re-dated to a month ago is a statement, made now, about a
	 * journey the delay has long run out on.
	 */
	public function testMovingTheJourneyBackPastTheDelayIsLate(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + 86400;

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000 - 30 * 86400, 120450),
		);

		$this->assertTrue($this->audits[1]->getDiffJson()['late']);
	}

	/**
	 * A jurisdiction that requires no logbook sets no delay, so no edit under it is late - and the
	 * trail still records the edit, because append-only and audit are the core's.
	 */
	public function testWithoutARulesetNoEditIsLate(): void {
		$this->logbookMode = true;
		$this->ruleset = false;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + 3650 * 86400;

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120450) + ['purpose' => 'Kundentermin'],
		);

		$this->assertFalse($this->audits[1]->getDiffJson()['late']);
	}

	/** Off the mode an edit is an edit, late or not, and no trail claims otherwise. */
	public function testAnEditOnAVehicleWithoutTheModeLeavesNoAuditRow(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$this->now = $trip->getEndedAt() + 30 * 86400;

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120460),
		);

		$this->assertSame([], $this->audits);
	}

	/** The edit, its trail and its Reading are one transaction, for the reason a void's are. */
	public function testTheEditItsTrailAndItsReadingAreOneTransaction(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		$entering = count($this->calls);

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120460),
		);

		$this->assertSame(
			['begin', 'hold', 'edit', 'audit', 'reading', 'commit'],
			array_slice($this->calls, $entering),
		);
	}

	/**
	 * An edit that lost the race writes nothing: not the trail, not the counter
	 * (docs/architecture.md#concurrency).
	 */
	public function testAnEditThatLostTheRaceChangesNothing(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		try {
			$service->update(
				self::OWNER,
				self::VEHICLE,
				$trip->getUuid(),
				$trip->getUpdatedAt() - 1,
				$this->drove(1750000000, 120460),
			);
			$this->fail('an edit wrote under a token nobody held');
		} catch (StaleUpdateException) {
			$this->assertCount(1, $this->audits);
			$this->assertSame(120450, $this->readings[0]->getValue());
			$this->assertSame(120450, $this->cached);
		}
	}

	/** An edit is held to the shape a new trip is: a counter and a distance at once is still two ends. */
	public function testAnEditTheColumnsCannotHoldIsRefused(): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));

		$this->expectException(\InvalidArgumentException::class);

		$service->update(
			self::OWNER,
			self::VEHICLE,
			$trip->getUuid(),
			$trip->getUpdatedAt(),
			$this->drove(1750000000, 120450) + ['distance' => 140],
		);
	}

	public function testADriverEditsTheTripsTheyEnteredAndNoOthers(): void {
		$service = $this->service();
		$theirs = $service->record(self::DRIVER, self::VEHICLE, $this->drove(1750000000, 120450));
		$owners = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750100000, 120600));

		$edited = $service->update(self::DRIVER, self::VEHICLE, $theirs->getUuid(), $theirs->getUpdatedAt(), $this->drove(1750000000, 120460));
		$this->assertSame(120460, $edited->getEndOdo());

		$this->expectException(AccessDeniedException::class);
		$service->update(self::DRIVER, self::VEHICLE, $owners->getUuid(), $owners->getUpdatedAt(), $this->drove(1750100000, 120610));
	}

	/** The second gate, as for a void: a trip is reached through the vehicle it hangs off. */
	public function testATripOfAnotherVehicleCannotBeEditedThroughThisOne(): void {
		$elsewhere = $this->onAnotherVehicle();

		$this->expectException(DoesNotExistException::class);

		$this->service()->update(
			self::OWNER,
			self::VEHICLE,
			$elsewhere->getUuid(),
			self::ENTERED_AT,
			$this->drove(1750000000, 120460),
		);
	}

	/**
	 * Two journeys with 200 km nobody recorded between them: the first ends on 120000, the second
	 * says it set off at 120200.
	 *
	 * @return Trip the trip whose claim opened the Gap
	 */
	private function gapped(TripService $service): Trip {
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));

		return $service->record(self::OWNER, self::VEHICLE, ['start_odo' => 120200] + $this->drove(self::GAP_TO_AT, 120300));
	}

	/**
	 * A confirmation closes one Gap as one private trip the app marks reconciled, over the
	 * kilometres and between the two moments that bracket them. Its Reading lands on the claim, so
	 * the Gap is gone on the next read.
	 */
	public function testClosingAGapWritesOnePrivateReconciledTripOverIt(): void {
		$service = $this->service();
		$claiming = $this->gapped($service);

		$closing = $service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);

		$this->assertSame(Trip::PRIVATE, $closing->getCategory());
		$this->assertTrue($closing->getReconciled());
		$this->assertSame(200, $closing->getDistance());
		$this->assertNull($closing->getStartOdo());
		$this->assertNull($closing->getEndOdo());
		$this->assertSame([self::GAP_FROM_AT, 120, self::GAP_TO_AT, 120], [
			$closing->getStartedAt(), $closing->getStartedAtOff(), $closing->getEndedAt(), $closing->getEndedAtOff(),
		]);
		$this->assertSame(self::OWNER, $closing->getCreatedBy());
		$this->assertCount(3, $this->trips);
		$this->assertSame([], (new Gaps($this->tripMapper, $this->readingMapper))->of($this->vehicle()));
		$this->assertSame([120000 => false, 120200 => false, 120300 => false], $this->chain());
	}

	/**
	 * Under the mode the closing trip is recorded like every other, and its row says the kilometres
	 * are the app's arithmetic and not a journey somebody observed - inside the same transaction.
	 */
	public function testAClosedGapIsRecordedInTheTrailAsDerived(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$claiming = $this->gapped($service);
		$before = count($this->calls);

		$closing = $service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);

		$this->assertCount(3, $this->audits);
		$row = $this->audits[2];
		$this->assertSame((int)$closing->getId(), $row->getEntityId());
		$this->assertSame([
			'change' => 'created',
			'fields' => [
				'started_at' => [null, self::GAP_FROM_AT],
				'started_at_off' => [null, 120],
				'ended_at' => [null, self::GAP_TO_AT],
				'ended_at_off' => [null, 120],
				'distance' => [null, 200],
				'category' => [null, Trip::PRIVATE],
				'reconciled' => [null, true],
			],
			'derived' => true,
			'updated_at' => self::ENTERED_AT,
		], $row->getDiffJson());
		$this->assertSame(['begin', 'hold', 'trips read', 'trip', 'audit', 'commit'], array_slice($this->calls, $before));
	}

	/**
	 * What the driver confirmed, each part of it wrong in turn. The confirmation names the kilometres
	 * and the two moments because those are what the driver agreed to; a Gap that differs in any of
	 * them is a different Gap.
	 *
	 * @return array<string, array{int, int, int}>
	 */
	public static function unconfirmedGaps(): array {
		return [
			'other kilometres' => [150, self::GAP_FROM_AT, self::GAP_TO_AT],
			'counted from elsewhere' => [200, self::GAP_FROM_AT - 1, self::GAP_TO_AT],
			'ending elsewhere' => [200, self::GAP_FROM_AT, self::GAP_TO_AT + 1],
		];
	}

	/** @dataProvider unconfirmedGaps */
	public function testAGapOtherThanTheOneConfirmedIsNotClosed(int $distance, int $fromAt, int $toAt): void {
		$service = $this->service();
		$claiming = $this->gapped($service);

		try {
			$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), $distance, $fromAt, $toAt);
			$this->fail('a Gap nobody confirmed was closed');
		} catch (StaleUpdateException) {
		}

		$this->assertCount(2, $this->trips);
	}

	/**
	 * A counter read between the two journeys moves neither end of the Gap. A confirmation of the
	 * Gap as it was measured from that Reading is a Gap that no longer exists, and the closing trip
	 * still counts from the last trip onto the claim - over the Reading, which it contradicts nowhere.
	 */
	public function testAReadingBetweenTheTripsIsClosedOverAndNotCountedFrom(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120000));
		$this->odometer()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750050000,
			'read_at_off' => 120,
			'value' => 120120,
		]);
		$claiming = $service->record(self::OWNER, self::VEHICLE, ['start_odo' => 120200] + $this->drove(self::GAP_TO_AT, 120300));

		try {
			$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 80, 1750050000, self::GAP_TO_AT);
			$this->fail('a Gap measured from the Reading was closed');
		} catch (StaleUpdateException) {
		}
		$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);

		$this->assertCount(3, $this->trips);
		$this->assertSame([], (new Gaps($this->tripMapper, $this->readingMapper))->of($this->vehicle()));
		$this->assertSame([120000 => false, 120120 => false, 120200 => false, 120300 => false], $this->chain());
	}

	/**
	 * One Gap, closed once. A second confirmation of the same one - a double tap, a second tab - finds
	 * nothing left to close rather than counting the kilometres twice.
	 */
	public function testAClosedGapCannotBeClosedAgain(): void {
		$service = $this->service();
		$claiming = $this->gapped($service);
		$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);

		$this->expectException(StaleUpdateException::class);

		$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);
	}

	/**
	 * Afterwards it is a trip like any other: an edit is audited and, past the lock delay, late. What
	 * it cannot do is say the trip was never reconciled - the mode documents writes, it refuses none,
	 * so the flag is simply not a field a request reaches.
	 */
	public function testAClosingTripEditsLikeAnyOtherAndStaysReconciled(): void {
		$this->logbookMode = true;
		$service = $this->service();
		$closing = $service->reconcile(self::OWNER, self::VEHICLE, $this->gapped($service)->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);
		$this->now = self::GAP_TO_AT + self::LOCK_DELAY_DAYS * 86400 + 1;

		$edited = $service->update(self::OWNER, self::VEHICLE, $closing->getUuid(), $closing->getUpdatedAt(), [
			'started_at' => self::GAP_FROM_AT,
			'started_at_off' => 120,
			'ended_at' => self::GAP_TO_AT,
			'ended_at_off' => 120,
			'distance' => 200,
			'category' => Trip::PRIVATE,
			'purpose' => 'Urlaub',
			'reconciled' => false,
		]);

		$this->assertTrue($edited->getReconciled());
		$this->assertSame([
			'change' => 'edited',
			'fields' => ['purpose' => [null, 'Urlaub']],
			'late' => true,
			'updated_at' => self::EDITED_AT,
		], $this->audits[3]->getDiffJson());
	}

	/**
	 * Two confirmations of one Gap at once - two tabs, two drivers of a shared car - must not both
	 * find it open. The vehicle's row is held before the Gap is looked for, so the second waits for
	 * the first to commit and then finds nothing left to close.
	 */
	public function testTheVehicleIsHeldBeforeTheGapIsLookedFor(): void {
		$service = $this->service();
		$claiming = $this->gapped($service);
		$before = count($this->calls);

		$service->reconcile(self::OWNER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);

		$this->assertSame(['begin', 'hold', 'trips read', 'trip', 'commit'], array_slice($this->calls, $before));
	}

	/**
	 * Every trip write ends by reading the chain and caching the newest value. Two at once on one
	 * vehicle would each miss the other's Reading, and the later cache could hold the older number;
	 * held first, the second waits for the first to commit.
	 *
	 * @dataProvider settlingWrites
	 */
	public function testEveryTripWriteHoldsTheVehicleBeforeItReadsOrWritesAnything(string $method): void {
		$service = $this->service();
		$trip = $service->record(self::OWNER, self::VEHICLE, $this->drove(1750000000, 120450));
		if ($method === 'restore') {
			$trip = $service->delete(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt());
		}
		$before = count($this->calls);

		match ($method) {
			'record' => $service->record(self::OWNER, self::VEHICLE, $this->drove(1750100000, 120900)),
			'update' => $service->update(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt(), $this->drove(1750000000, 120460)),
			default => $service->$method(self::OWNER, self::VEHICLE, $trip->getUuid(), $trip->getUpdatedAt()),
		};

		$this->assertSame(['begin', 'hold'], array_slice($this->calls, $before, 2));
	}

	/**
	 * The trip writes that settle the odometer. Closing a Gap is the fifth, and
	 * testTheVehicleIsHeldBeforeTheGapIsLookedFor pins it.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function settlingWrites(): iterable {
		yield 'record' => ['record'];
		yield 'update' => ['update'];
		yield 'delete' => ['delete'];
		yield 'restore' => ['restore'];
	}

	/** Closing a Gap adds a trip, so it takes the right adding one does. */
	public function testAGrantToLookIsNotAGrantToCloseAGap(): void {
		$service = $this->service();
		$claiming = $this->gapped($service);

		$this->expectException(AccessDeniedException::class);

		$service->reconcile(self::VIEWER, self::VEHICLE, $claiming->getUuid(), 200, self::GAP_FROM_AT, self::GAP_TO_AT);
	}
}
