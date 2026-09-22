<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\Completeness;
use OCA\NextFleet\Service\ConsumptionService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\Gaps;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The one timeline a vehicle has (docs/ui.md): trips and Odometer Entries merged into one order,
 * newest first, a page at a time.
 *
 * The mappers are stores rather than expectations, the way TripServiceTest keeps them - what the
 * screen shows is the merge, not which query produced a row. What the two queries do against a real
 * database is tests/Integration/TimelineTest.php's.
 */
class TimelineServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	/** What the gate hands back for that uuid, and so the id every row here is written under. */
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const STRANGER = 'bob';

	/** @var list<Trip> */
	private array $tripRows = [];
	/** @var list<OdoReading> */
	private array $readingRows = [];
	/** @var list<Energy|Maintenance|Expense> */
	private array $costRows = [];
	private int $nextTripId = 1;
	private int $nextReadingId = 1;
	private int $nextCostId = 1;
	/** What the gate hands back: the vehicle's own fields, over its id and uuid. */
	private array $vehicleRow = [];

	private TripMapper&MockObject $trips;
	private OdoReadingMapper&MockObject $readings;
	private EnergyMapper&MockObject $energy;
	private MaintenanceMapper&MockObject $maintenance;
	private ExpenseMapper&MockObject $expenses;
	private VehicleService&MockObject $fleet;

	protected function setUp(): void {
		$this->tripRows = [];
		$this->readingRows = [];
		$this->costRows = [];
		$this->nextTripId = 1;
		$this->nextReadingId = 1;
		$this->nextCostId = 1;
		$this->vehicleRow = [];

		// The three cost tables page alike, each over its own rows.
		foreach ([
			'energy' => [EnergyMapper::class, Energy::class],
			'maintenance' => [MaintenanceMapper::class, Maintenance::class],
			'expenses' => [ExpenseMapper::class, Expense::class],
		] as $property => [$mapper, $entity]) {
			$this->$property = $this->createMock($mapper);
			$this->$property->method('findBefore')->willReturnCallback(
				fn (int $vehicleId, int $at, int $id, int $limit): array => $this->before(
					array_values(array_filter($this->costRows, static fn (object $row): bool => $row instanceof $entity)),
					$vehicleId,
					[$at, $id],
					$limit,
				),
			);
		}

		// What consumption reads: every fill-up, oldest first.
		$this->energy->method('findAllForVehicle')->willReturnCallback(
			fn (int $vehicleId): array => array_reverse($this->before(
				array_values(array_filter($this->costRows, static fn (object $row): bool => $row instanceof Energy)),
				$vehicleId,
				[PHP_INT_MAX, PHP_INT_MAX],
				PHP_INT_MAX,
			)),
		);

		$this->trips = $this->createMock(TripMapper::class);
		$this->trips->method('findBefore')->willReturnCallback(
			fn (int $vehicleId, int $at, int $id, int $limit): array => $this->before(
				$this->tripRows,
				$vehicleId,
				[$at, $id],
				$limit,
			),
		);

		$this->readings = $this->createMock(OdoReadingMapper::class);
		$this->readings->method('findEntriesBefore')->willReturnCallback(
			fn (int $vehicleId, int $at, int $id, int $limit): array => $this->before(
				array_values(array_filter(
					$this->readingRows,
					static fn (OdoReading $row): bool => $row->getSourceType() === OdoReading::MANUAL,
				)),
				$vehicleId,
				[$at, $id],
				$limit,
			),
		);

		// What gap detection reads: every trip and the whole chain, oldest first. What it makes of
		// them is GapsTest's.
		$this->trips->method('findAllForVehicle')->willReturnCallback(
			fn (int $vehicleId): array => array_reverse($this->before($this->tripRows, $vehicleId, [PHP_INT_MAX, PHP_INT_MAX], PHP_INT_MAX)),
		);
		$this->readings->method('findChain')->willReturnCallback(
			fn (int $vehicleId): array => array_reverse($this->before($this->readingRows, $vehicleId, [PHP_INT_MAX, PHP_INT_MAX], PHP_INT_MAX)),
		);

		$this->readings->method('findForSources')->willReturnCallback(
			fn (int $vehicleId, string $sourceType, array $sourceIds): array => array_values(array_filter(
				$this->readingRows,
				static fn (OdoReading $row): bool => $row->getVehicleId() === $vehicleId
					&& $row->getSourceType() === $sourceType
					&& in_array((int)$row->getSourceId(), $sourceIds, true),
			)),
		);

		// Who reaches which vehicle is VehicleAccessTest's; what this states is which operation
		// reading the timeline asks the gate for.
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

				return Vehicle::fromRow(['id' => self::VEHICLE_ID, 'uuid' => self::VEHICLE] + $this->vehicleRow);
			},
		);
	}

	/**
	 * What both mapper reads answer: one vehicle's rows strictly before `(instant, id)`, newest
	 * first, at most `$limit` of them.
	 *
	 * @param list<Trip|OdoReading|Energy|Maintenance|Expense> $rows
	 * @param array{int, int} $before
	 * @return list<Trip|OdoReading|Energy|Maintenance|Expense>
	 */
	private function before(array $rows, int $vehicleId, array $before, int $limit): array {
		$mine = array_values(array_filter(
			$rows,
			static fn (object $row): bool => $row->getVehicleId() === $vehicleId
				&& self::key($row) < $before,
		));
		usort($mine, static fn (object $a, object $b): int => self::key($b) <=> self::key($a));

		return array_slice($mine, 0, $limit);
	}

	/**
	 * What every read orders by: when the row happened, then its id. Each table names that moment
	 * its own way - one fact under five names.
	 *
	 * @return array{int, int}
	 */
	private static function key(Trip|OdoReading|Energy|Maintenance|Expense $row): array {
		return [
			match (true) {
				$row instanceof Trip => $row->getStartedAt(),
				$row instanceof OdoReading => $row->getReadAt(),
				$row instanceof Energy => $row->getFilledAt(),
				$row instanceof Maintenance => $row->getDoneAt(),
				$row instanceof Expense => $row->getSpentAt(),
			},
			(int)$row->getId(),
		];
	}

	private function service(): TimelineService {
		return new TimelineService(
			$this->trips,
			$this->readings,
			$this->energy,
			$this->maintenance,
			$this->expenses,
			// No record here closes a reminder: that lookup is TimelineTest's, over real SQL.
			$this->createMock(ReminderMapper::class),
			$this->fleet,
			$this->completeness(),
			new Gaps($this->trips, $this->readings),
			new ConsumptionService($this->energy, $this->readings),
		);
	}

	/**
	 * Measured against a ruleset that asks a business trip for its partner and nothing else. What a
	 * trip misses is CompletenessTest's; what this states is that the row carries the answer.
	 */
	private function completeness(): Completeness {
		$rules = $this->createMock(ILogbookRules::class);
		$rules->method('mandatoryFields')->willReturnCallback(
			static fn (string $category): array => $category === Trip::BUSINESS ? ['partner'] : [],
		);
		$profile = $this->createMock(IJurisdiction::class);
		$profile->method('logbookRules')->willReturn($rules);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($profile);

		return new Completeness(new Jurisdictions($container));
	}

	/** One journey in the store, dated as the driver entered it. */
	private function trip(int $startedAt, int $endOdo = 120450): Trip {
		$trip = new Trip();
		$trip->setId($this->nextTripId);
		$trip->setUuid('0195e2f1-1111-4000-8000-' . sprintf('%012d', $this->nextTripId++));
		$trip->setVehicleId(self::VEHICLE_ID);
		$trip->setStartedAt($startedAt);
		$trip->setStartedAtOff(120);
		$trip->setEndedAt($startedAt + 5400);
		$trip->setEndedAtOff(120);
		$trip->setEndOdo($endOdo);
		$trip->setCategory(Trip::BUSINESS);
		$trip->setCreatedBy(self::OWNER);
		$this->tripRows[] = $trip;

		return $trip;
	}

	/** One Odometer Entry in the store: the Entry that is its own Reading (CONTEXT.md). */
	private function entry(int $readAt, int $value = 120000): OdoReading {
		return $this->reading($readAt, $value, OdoReading::MANUAL, null);
	}

	private function reading(int $readAt, int $value, string $sourceType, ?int $sourceId): OdoReading {
		$reading = new OdoReading();
		$reading->setId($this->nextReadingId);
		$reading->setUuid('0195e2f1-2222-4000-8000-' . sprintf('%012d', $this->nextReadingId++));
		$reading->setVehicleId(self::VEHICLE_ID);
		$reading->setReadAt($readAt);
		$reading->setReadAtOff(120);
		$reading->setValue($value);
		$reading->setKind('reading');
		$reading->setOrigin('observed');
		$reading->setSourceType($sourceType);
		$reading->setSourceId($sourceId);
		$this->readingRows[] = $reading;

		return $reading;
	}

	/** One fill-up in the store, full and priced unless told otherwise. */
	private function fillUp(int $filledAt, int $amount = 42000, ?int $total = 7500, string $energy = 'petrol'): Energy {
		$fill = new Energy();
		$fill->setFilledAt($filledAt);
		$fill->setFilledAtOff(120);
		$fill->setEnergy($energy);
		$fill->setAmount($amount);
		$fill->setTotal($total);
		$fill->setFullTank(true);

		return $this->cost($fill);
	}

	private function work(int $doneAt): Maintenance {
		$work = new Maintenance();
		$work->setDoneAt($doneAt);
		$work->setDoneAtOff(120);
		$work->setTitle('Brake pads');

		return $this->cost($work);
	}

	private function expense(int $spentAt): Expense {
		$expense = new Expense();
		$expense->setSpentAt($spentAt);
		$expense->setSpentAtOff(120);
		$expense->setAmount(1200);

		return $this->cost($expense);
	}

	/**
	 * @template T of Energy|Maintenance|Expense
	 * @param T $row
	 * @return T
	 */
	private function cost(Energy|Maintenance|Expense $row): Energy|Maintenance|Expense {
		$row->setId($this->nextCostId);
		$row->setUuid('0195e2f1-3333-4000-8000-' . sprintf('%012d', $this->nextCostId++));
		$row->setVehicleId(self::VEHICLE_ID);
		$row->setCreatedBy(self::OWNER);
		$this->costRows[] = $row;

		return $row;
	}

	/**
	 * The page as a reader sees it: what kind each row is and which uuid it names, in the order
	 * they are on screen.
	 *
	 * @param array{rows: list<array<string, mixed>>, next: ?string} $page
	 * @return list<string>
	 */
	private function shown(array $page): array {
		return array_map(
			static function (array $row): string {
				$entry = $row[$row['type']];

				return $row['type'] . ' ' . $entry->getUuid();
			},
			$page['rows'],
		);
	}

	/**
	 * The whole point of the route: one order over two tables, newest first, whichever table a row
	 * came from (docs/ui.md). A tab per table would have answered no question anybody asks.
	 */
	public function testTripsAndOdometerEntriesAreOneOrder(): void {
		$older = $this->trip(1750000000);
		$entry = $this->entry(1750100000);
		$newer = $this->trip(1750200000);

		$page = $this->service()->page(self::OWNER, self::VEHICLE, null, null);

		$this->assertSame(
			['trip ' . $newer->getUuid(), 'odometer ' . $entry->getUuid(), 'trip ' . $older->getUuid()],
			$this->shown($page),
		);
		$this->assertNull($page['next']);
	}

	/**
	 * The chips above the timeline (docs/ui.md), which narrow it to one kind of Entry and nothing
	 * else about it.
	 */
	public function testAChipNarrowsTheTimelineToOneKind(): void {
		$this->trip(1750000000);
		$entry = $this->entry(1750100000);
		$this->trip(1750200000);

		$page = $this->service()->page(self::OWNER, self::VEHICLE, TimelineService::ODOMETER, null);

		$this->assertSame(['odometer ' . $entry->getUuid()], $this->shown($page));
	}

	/**
	 * Fill-ups, maintenance and expenses are things that happened to the vehicle as much as a trip
	 * is, so they are in the same one order (docs/ui.md), not a tab each.
	 */
	public function testEveryKindOfEntryIsInTheOneOrder(): void {
		$expense = $this->expense(1750000000);
		$trip = $this->trip(1750100000);
		$work = $this->work(1750200000);
		$entry = $this->entry(1750300000);
		$fill = $this->fillUp(1750400000);

		$this->assertSame(
			[
				'energy ' . $fill->getUuid(),
				'odometer ' . $entry->getUuid(),
				'maintenance ' . $work->getUuid(),
				'trip ' . $trip->getUuid(),
				'expense ' . $expense->getUuid(),
			],
			$this->shown($this->service()->page(self::OWNER, self::VEHICLE, null, null)),
		);
	}

	/** Each chip is one kind; Costs is the expenses (docs/ui.md). */
	public function testEachNewChipNarrowsTheTimelineToItsKind(): void {
		$fill = $this->fillUp(1750000000);
		$work = $this->work(1750100000);
		$expense = $this->expense(1750200000);
		$this->trip(1750300000);

		$service = $this->service();
		$this->assertSame(['energy ' . $fill->getUuid()], $this->shown($service->page(self::OWNER, self::VEHICLE, TimelineService::ENERGY, null)));
		$this->assertSame(['maintenance ' . $work->getUuid()], $this->shown($service->page(self::OWNER, self::VEHICLE, TimelineService::MAINTENANCE, null)));
		$this->assertSame(['expense ' . $expense->getUuid()], $this->shown($service->page(self::OWNER, self::VEHICLE, TimelineService::EXPENSE, null)));
	}

	/**
	 * The cursor's tie-break holds across the new tables too: rows of three kinds at one instant,
	 * with the page ending among them, are each served exactly once.
	 */
	public function testAPageBoundaryAmongTheNewKindsLosesAndRepeatsNothing(): void {
		$expected = [];
		for ($i = 0; $i < 20; $i++) {
			$at = 1750000000 - $i * 1000;
			// Inserted out of rank order, so the order below is the tie-break's and not the store's.
			$expense = $this->expense($at);
			$fill = $this->fillUp($at);
			$work = $this->work($at);
			$expected[] = 'expense ' . $expense->getUuid();
			$expected[] = 'maintenance ' . $work->getUuid();
			$expected[] = 'energy ' . $fill->getUuid();
		}

		$shown = [];
		$cursor = null;
		$pages = 0;
		do {
			$page = $this->service()->page(self::OWNER, self::VEHICLE, null, $cursor);
			$shown = array_merge($shown, $this->shown($page));
			$cursor = $page['next'];
			$pages++;
		} while ($cursor !== null && $pages < 10);

		$this->assertSame($expected, $shown);
		$this->assertSame(2, $pages);
	}

	/**
	 * A fill-up's flags are computed on read (docs/architecture.md#data-model), and the timeline is
	 * the read that says them. Overfill is measured against the vehicle's tank or battery, per energy.
	 */
	public function testAFillUpRowSaysWhatItIsFlaggedFor(): void {
		$this->vehicleRow = ['energy_types' => '["petrol","electric"]', 'tank_ml' => 45000, 'battery_wh' => 13000];
		$fine = $this->fillUp(1750000000, 40000);
		$unpriced = $this->fillUp(1750100000, 40000, null);
		$overfilled = $this->fillUp(1750200000, 60000);
		$overcharged = $this->fillUp(1750300000, 20000, 500, 'electric');
		$foreign = $this->fillUp(1750400000, 40000, 7000, 'diesel');

		$flags = [];
		foreach ($this->service()->page(self::OWNER, self::VEHICLE, null, null)['rows'] as $row) {
			$flags[$row['energy']->getUuid()] = $row['flags'];
		}

		$this->assertSame([], $flags[$fine->getUuid()]);
		$this->assertSame([EnergyService::NO_PRICE], $flags[$unpriced->getUuid()]);
		$this->assertSame([EnergyService::OVERFILLED], $flags[$overfilled->getUuid()]);
		$this->assertSame([EnergyService::OVERFILLED], $flags[$overcharged->getUuid()]);
		$this->assertSame([EnergyService::FOREIGN_ENERGY], $flags[$foreign->getUuid()]);
	}

	/** Without a tank or battery on file there is nothing to overfill. */
	public function testNoCapacityOnFileFlagsNoOverfill(): void {
		$this->vehicleRow = ['energy_types' => '["petrol"]'];
		$this->fillUp(1750000000, 900000);

		$this->assertSame([], $this->service()->page(self::OWNER, self::VEHICLE, null, null)['rows'][0]['flags']);
	}

	/**
	 * A fill-up or maintenance counter writes a Reading per counter (docs/architecture.md, rule 5),
	 * and a flag on either is a question the row it belongs to carries - as a trip's does.
	 */
	public function testAFillUpAndAMaintenanceRecordCarryTheReadingsTheyWrote(): void {
		$fill = $this->fillUp(1750000000);
		$km = $this->reading(1750000000, 120450, OdoReading::ENERGY, (int)$fill->getId());
		$hours = $this->reading(1750000000, 3100, OdoReading::ENERGY, (int)$fill->getId());
		$hours->setCounter(OdoReading::SECOND);
		$work = $this->work(1750100000);
		// Same id as the fill-up, other table: it must not borrow the fill-up's Readings.
		$work->setId($fill->getId());
		$bare = $this->expense(1750200000);

		$rows = $this->service()->page(self::OWNER, self::VEHICLE, null, null)['rows'];

		$this->assertSame($bare, $rows[0]['expense']);
		$this->assertArrayNotHasKey('readings', $rows[0]);
		$this->assertSame([], $rows[1]['readings']);
		$this->assertSame([$km, $hours], $rows[2]['readings']);
	}

	/**
	 * A fill-up closing a segment states its consumption (docs/ui.md), even when the one that opened
	 * it is on an earlier page; one that closes none says so with null. Which segments count is
	 * ConsumptionServiceTest's.
	 */
	public function testAFillUpClosingASegmentCarriesItsConsumption(): void {
		$this->vehicleRow = ['energy_types' => '["petrol"]', 'odo_unit' => 'km'];
		$opening = $this->fillUp(1750000000, 40000);
		$this->reading(1750000000, 10000, OdoReading::ENERGY, (int)$opening->getId());
		$closing = $this->fillUp(1750100000, 30000);
		$this->reading(1750100000, 10500, OdoReading::ENERGY, (int)$closing->getId());
		for ($i = 1; $i <= TimelineService::PAGE; $i++) {
			$this->expense(1750000000 + $i);
		}

		$first = $this->service()->page(self::OWNER, self::VEHICLE, null, null);
		$second = $this->service()->page(self::OWNER, self::VEHICLE, null, $first['next']);

		$this->assertSame($closing, $first['rows'][0]['energy']);
		$this->assertSame(6.0, $first['rows'][0]['consumption']['value']);
		$this->assertSame('km', $first['rows'][0]['consumption']['per']);
		$oldest = end($second['rows']);
		$this->assertSame($opening, $oldest['energy']);
		$this->assertNull($oldest['consumption']);
		$this->energy->method('findOnVehicle')->willReturn($closing);
		$this->assertSame(
			6.0,
			$this->service()->one(self::OWNER, self::VEHICLE, TimelineService::ENERGY, $closing->getUuid())['consumption']['value'],
		);
	}

	/**
	 * A kind nobody serves is refused rather than answered with everything, which would look to a
	 * client like a filter that silently does nothing.
	 */
	public function testAKindTheTimelineDoesNotServeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->page(self::OWNER, self::VEHICLE, 'fuel', null);
	}

	/**
	 * Fifty rows, then more on scroll (docs/ui.md). The page says there is more by handing back a
	 * cursor, and says there is none by handing back null - a client that had to ask again to find
	 * out would fetch an empty page at the bottom of every timeline.
	 */
	public function testAPageIsFiftyRowsAndSaysWhetherMoreFollow(): void {
		for ($i = 0; $i < 51; $i++) {
			$this->trip(1750000000 + $i * 1000);
		}

		$page = $this->service()->page(self::OWNER, self::VEHICLE, null, null);

		$this->assertCount(50, $page['rows']);
		$this->assertNotNull($page['next']);
		$this->assertCount(1, $this->service()->page(self::OWNER, self::VEHICLE, null, $page['next'])['rows']);
	}

	/**
	 * The case the cursor exists for. Two rows can carry the same instant - a trip entered at the
	 * moment the counter was read, an import that dates a day's rows alike - and a page can end
	 * between them. Paging on the instant alone would then either skip the second row or serve the
	 * first one twice, and both are silent.
	 *
	 * Walked with the boundary inside a trip's instant and inside an Odometer Entry's, because the
	 * two sides of the tie-break are different arithmetic.
	 *
	 * @dataProvider boundaries
	 */
	public function testAPageBoundaryInsideOneInstantLosesAndRepeatsNothing(int $lead): void {
		$expected = [];
		// Above every pair, so the fiftieth row falls inside a pair rather than after one.
		for ($i = 0; $i < $lead; $i++) {
			$expected[] = 'odometer ' . $this->entry(1760000000 - $i * 1000)->getUuid();
		}
		for ($pair = 0; $pair < 30; $pair++) {
			$at = 1750000000 - $pair * 1000;
			$trip = $this->trip($at);
			$entry = $this->entry($at);
			// The tie-break: at one instant the trip is the older-ranking row and shows first.
			$expected[] = 'trip ' . $trip->getUuid();
			$expected[] = 'odometer ' . $entry->getUuid();
		}

		$shown = [];
		$pages = 0;
		$cursor = null;
		do {
			$page = $this->service()->page(self::OWNER, self::VEHICLE, null, $cursor);
			$shown = array_merge($shown, $this->shown($page));
			$cursor = $page['next'];
			$pages++;
		} while ($cursor !== null && $pages < 10);

		$this->assertSame($expected, $shown);
		$this->assertSame(2, $pages);
	}

	/**
	 * @return iterable<string, array{int}>
	 */
	public static function boundaries(): iterable {
		yield 'the page ends on a trip' => [1];
		yield 'the page ends on an odometer entry' => [2];
	}

	/**
	 * One row for the two (docs/architecture.md#odometer-rules, rule 5): the journey and the
	 * counter it left behind are one thing that happened, and the screen shows them as one. A
	 * distance-only trip carries no counter of its own, so without the Reading its row could not
	 * say what the vehicle then stood at, nor that the number is in question.
	 */
	public function testATripCarriesTheReadingItLeftOnTheCounter(): void {
		$trip = $this->trip(1750000000);
		$reading = $this->reading(1750005400, 120450, OdoReading::TRIP, (int)$trip->getId());

		$rows = $this->service()->page(self::OWNER, self::VEHICLE, null, null)['rows'];

		$this->assertSame($reading, $rows[0]['reading']);
	}

	/** The vehicle the gate hands back here is not under Logbook Mode (docs/features.md#logbook-mode). */
	public function testATripRowSaysWhatItLeavesUnstatedWhetherOrNotTheModeIsOn(): void {
		$bare = $this->trip(1750000000);
		$stated = $this->trip(1750100000);
		$stated->setPartner('Muster GmbH');
		$this->entry(1750200000);

		$rows = $this->service()->page(self::OWNER, self::VEHICLE, null, null)['rows'];

		$this->assertArrayNotHasKey('missing', $rows[0]);
		$this->assertSame($stated, $rows[1]['trip']);
		$this->assertSame([], $rows[1]['missing']);
		$this->assertSame($bare, $rows[2]['trip']);
		$this->assertSame(['partner'], $rows[2]['missing']);
	}

	/**
	 * A co-driver who may look at the vehicle may read its timeline: the route shows what is
	 * already theirs to see, and asks the gate for exactly that.
	 */
	public function testAGrantToLookIsAGrantToReadTheTimeline(): void {
		$trip = $this->trip(1750000000);

		$page = $this->service()->page(self::DRIVER, self::VEHICLE, null, null);

		$this->assertSame(['trip ' . $trip->getUuid()], $this->shown($page));
	}

	/**
	 * A uuid is all it takes to name a vehicle, and everything hanging off one goes through the
	 * same gate (docs/security.md). A timeline is the whole movement profile, so this is the
	 * refusal that matters most.
	 */
	public function testAStrangerReadsNoTimeline(): void {
		$this->trip(1750000000);
		$this->expectException(AccessDeniedException::class);

		$this->service()->page(self::STRANGER, self::VEHICLE, null, null);
	}

	/**
	 * The Gaps are read through the timeline's gate: a co-driver who may look at the vehicle may see
	 * what its logbook lacks, as they may see its trips.
	 */
	public function testAGrantToLookIsAGrantToReadTheGaps(): void {
		$this->entry(1750000000, 120000);
		$trip = $this->trip(1750100000);
		$trip->setStartOdo(120040);

		$this->assertSame(
			[[$trip->getUuid(), 40]],
			array_map(
				static fn (array $gap): array => [$gap['trip'], $gap['distance']],
				$this->service()->gaps(self::DRIVER, self::VEHICLE),
			),
		);
	}

	/** Where a vehicle went unrecorded is as much a movement profile as where it went. */
	public function testAStrangerReadsNoGaps(): void {
		$this->expectException(AccessDeniedException::class);

		$this->service()->gaps(self::STRANGER, self::VEHICLE);
	}

	/**
	 * A cursor is the server's own word handed back. One a client invented names a place in an
	 * order it cannot see, so it is refused rather than read as "start at the top".
	 *
	 * @dataProvider forgedCursors
	 */
	public function testACursorThisTimelineDidNotHandOutIsRefused(string $cursor): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->page(self::OWNER, self::VEHICLE, null, $cursor);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function forgedCursors(): iterable {
		yield 'a kind nobody serves' => ['1750000000:fuel:4'];
		yield 'an instant that is not a number' => ['soon:trip:4'];
		yield 'an id that is not a number' => ['1750000000:trip:'];
		yield 'nothing to break a tie with' => ['1750000000:trip'];
		yield 'a word' => ['top'];
	}
}
