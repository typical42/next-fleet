<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The timeline against the real database: what the two queries behind it actually select, and what
 * survives being paged. The merge itself is TimelineServiceTest's, over stores that answer as the
 * queries are documented to - this is what says the queries answer that way.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TimelineTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';

	private ContainerInterface $container;
	private TimelineService $timeline;
	private TripService $trips;
	private TripMapper $tripRows;
	private OdometerService $odometer;
	private VehicleService $vehicles;
	private string $uuid;

	protected function setUp(): void {
		$container = $this->container = (new Application())->getContainer();
		$this->timeline = $container->get(TimelineService::class);
		$this->trips = $container->get(TripService::class);
		$this->tripRows = $container->get(TripMapper::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);

		$this->forgetTestRows();
		$this->uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 123'])->getUuid();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = [
			'fleet_trips' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_energy' => 'created_by',
			'fleet_maintenance' => 'created_by',
			'fleet_expenses' => 'created_by',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::AUTHOR)));
			$qb->executeStatement();
		}
	}

	/** One trip of an hour and a half, ending on the counter it is handed. */
	private function trip(int $startedAt, int $endOdo): Trip {
		return $this->trips->record(self::AUTHOR, $this->uuid, [
			'started_at' => $startedAt,
			'started_at_off' => 120,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 120,
			'end_odo' => $endOdo,
			'category' => Trip::BUSINESS,
		]);
	}

	/** One Odometer Entry: the Entry that is its own Reading (CONTEXT.md). */
	private function entry(int $readAt, int $value): OdoReading {
		return $this->odometer->record(self::AUTHOR, $this->uuid, [
			'read_at' => $readAt,
			'read_at_off' => 120,
			'value' => $value,
		]);
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
	 * The whole route in one read: both tables in one order, newest first, and each trip carrying
	 * the Reading it left on the counter. That Reading is not a row of its own - listing it as one
	 * would show every trip twice - and only the instance says the `source_type` predicate that
	 * keeps it out is a predicate the database applies.
	 */
	public function testTheTimelineIsBothTablesInOneOrderAndShowsNoTripTwice(): void {
		$older = $this->trip(1750000000, 120450);
		$entry = $this->entry(1750100000, 120500);
		$newer = $this->trip(1750200000, 120800);

		$page = $this->timeline->page(self::AUTHOR, $this->uuid, null, null);

		$this->assertSame(
			['trip ' . $newer->getUuid(), 'odometer ' . $entry->getUuid(), 'trip ' . $older->getUuid()],
			$this->shown($page),
		);
		$this->assertNull($page['next']);
		$this->assertSame(120800, $page['rows'][0]['reading']->getValue());
		$this->assertSame(120450, $page['rows'][2]['reading']->getValue());
	}

	/**
	 * A voided trip is out of the timeline - the screen shows what stands, and the export is what
	 * asks for what was struck (PRD, task 17). `deleted_at IS NULL` is in the shared query, so only
	 * the instance says it reaches both tables.
	 */
	public function testAVoidedTripIsNoLongerOnTheTimeline(): void {
		$standing = $this->trip(1750000000, 120450);
		$voided = $this->trip(1750200000, 120800);
		$this->tripRows->softDelete($voided, $voided->getUpdatedAt());

		$page = $this->timeline->page(self::AUTHOR, $this->uuid, null, null);

		$this->assertSame(['trip ' . $standing->getUuid()], $this->shown($page));
	}

	/**
	 * Paging over both tables against real SQL. The first page is asked for with no cursor, which
	 * is `PHP_INT_MAX` on a BIGINT column, and the boundary falls inside one instant that a trip
	 * and an Odometer Entry share - the case the cursor's tie-break exists for, and the one a
	 * database that compared only the instant would lose a row over.
	 */
	public function testAPageBoundaryInsideOneInstantLosesAndRepeatsNothing(): void {
		// One row above every pair, so the fiftieth falls between a trip and the Entry sharing its
		// instant rather than after the two of them.
		$expected = ['odometer ' . $this->entry(1760000000, 400000)->getUuid()];
		for ($i = 0; $i < 26; $i++) {
			$at = 1750000000 - $i * 1000;
			$trip = $this->trip($at, 200000 + $i);
			$entry = $this->entry($at, 300000 + $i);
			// The tie-break: at one instant the trip is the older-ranking row and shows first.
			$expected[] = 'trip ' . $trip->getUuid();
			$expected[] = 'odometer ' . $entry->getUuid();
		}

		$first = $this->timeline->page(self::AUTHOR, $this->uuid, null, null);
		$this->assertCount(TimelineService::PAGE, $first['rows']);
		$this->assertNotNull($first['next']);

		$second = $this->timeline->page(self::AUTHOR, $this->uuid, null, $first['next']);
		$this->assertNull($second['next']);
		$this->assertSame($expected, array_merge($this->shown($first), $this->shown($second)));
	}

	/**
	 * The container wires the vehicle's own ruleset into the page, on a vehicle not under Logbook
	 * Mode (docs/features.md#logbook-mode). What Germany requires is tests/Country/De's; this only
	 * needs one field it requires that the trip leaves out, and one the trip states.
	 */
	public function testABusinessTripMissingAFieldDeRequiresIsListedAsIncomplete(): void {
		$this->assertSame('de', $this->vehicles->find(self::AUTHOR, $this->uuid)->getJurisdiction());
		$this->trip(1750000000, 120450);
		$this->entry(1750100000, 120500);

		$rows = $this->timeline->page(self::AUTHOR, $this->uuid, null, null)['rows'];

		$this->assertArrayNotHasKey('missing', $rows[0]);
		$this->assertContains('partner', $rows[1]['missing']);
		$this->assertNotContains('end_odo', $rows[1]['missing']);
	}

	/**
	 * A Gap against the real chain, and gone from it with the Reading it was measured against:
	 * voiding a trip voids its Reading (rule 5), and only the instance says the chain the Gaps are
	 * read off leaves a voided Reading out.
	 */
	public function testAClaimAboveTheReadingBeforeItIsAGapMeasuredOnlyAgainstWhatStands(): void {
		$this->entry(1750000000, 120000);
		$before = $this->trip(1750100000, 120450);
		$claiming = $this->trips->record(self::AUTHOR, $this->uuid, [
			'started_at' => 1750200000,
			'started_at_off' => 120,
			'ended_at' => 1750205400,
			'ended_at_off' => 120,
			'start_odo' => 120600,
			'end_odo' => 120700,
			'category' => Trip::PRIVATE,
		]);

		$this->assertSame([[
			'trip' => $claiming->getUuid(),
			'distance' => 150,
			'from_at' => 1750105400,
			'from_at_off' => 120,
			'to_at' => 1750200000,
			'to_at_off' => 120,
		]], $this->timeline->gaps(self::AUTHOR, $this->uuid));

		$this->trips->delete(self::AUTHOR, $this->uuid, $before->getUuid(), $before->getUpdatedAt());

		$this->assertSame([600], array_column($this->timeline->gaps(self::AUTHOR, $this->uuid), 'distance'));
	}

	/**
	 * The chip, as the database narrows it: one kind of Entry and nothing else about the vehicle.
	 */
	public function testAChipNarrowsTheTimelineToOneKind(): void {
		$this->trip(1750000000, 120450);
		$entry = $this->entry(1750100000, 120500);

		$page = $this->timeline->page(self::AUTHOR, $this->uuid, TimelineService::ODOMETER, null);

		$this->assertSame(['odometer ' . $entry->getUuid()], $this->shown($page));
	}

	/**
	 * Fill-ups, maintenance and expenses in the one order over real SQL, each read by its own
	 * table's instant column, and each counter-carrying Entry with the Readings it wrote - which are
	 * not rows of their own.
	 */
	public function testTheCostTablesJoinTheOneOrderWithTheirReadings(): void {
		$energy = $this->container->get(EnergyService::class);
		$maintenance = $this->container->get(MaintenanceService::class);
		$expenses = $this->container->get(ExpenseService::class);

		$trip = $this->trip(1750000000, 120450);
		$fill = $energy->record(self::AUTHOR, $this->uuid, [
			'filled_at' => 1750100000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 40000, 'odo' => 120500,
		]);
		$work = $maintenance->record(self::AUTHOR, $this->uuid, [
			'done_at' => 1750200000, 'done_at_off' => 120, 'title' => 'Brake pads', 'odo' => 120600,
		]);
		$spent = $expenses->record(self::AUTHOR, $this->uuid, [
			'spent_at' => 1750300000, 'spent_at_off' => 120, 'amount' => 1200, 'category' => 'parking',
		]);

		$page = $this->timeline->page(self::AUTHOR, $this->uuid, null, null);

		$this->assertSame(
			[
				'expense ' . $spent['uuid'],
				'maintenance ' . $work['uuid'],
				'energy ' . $fill['uuid'],
				'trip ' . $trip->getUuid(),
			],
			$this->shown($page),
		);
		$this->assertSame([120600], array_map(static fn (OdoReading $r): int => $r->getValue(), $page['rows'][1]['readings']));
		$this->assertSame([120500], array_map(static fn (OdoReading $r): int => $r->getValue(), $page['rows'][2]['readings']));
		// No total, on a vehicle that takes no energy yet.
		$this->assertSame([EnergyService::FOREIGN_ENERGY, EnergyService::NO_PRICE], $page['rows'][2]['flags']);

		$costs = $this->timeline->page(self::AUTHOR, $this->uuid, TimelineService::EXPENSE, null);
		$this->assertSame(['expense ' . $spent['uuid']], $this->shown($costs));
	}

	/**
	 * A fill-up closing a full-to-full segment carries its consumption over real SQL, with the
	 * partial in between counted and a deleted fill-up not.
	 */
	public function testAFillUpClosingASegmentCarriesItsConsumption(): void {
		$energy = $this->container->get(EnergyService::class);
		$fill = fn (int $at, int $amount, bool $full, int $odo): array => $energy->record(self::AUTHOR, $this->uuid, [
			'filled_at' => $at, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => $amount,
			'full_tank' => $full, 'odo' => $odo,
		]);
		$fill(1750100000, 50000, true, 120000);
		$fill(1750150000, 10000, false, 120200);
		$gone = $fill(1750160000, 99000, false, 120300);
		$energy->delete(self::AUTHOR, $this->uuid, $gone['uuid'], $gone['updated_at']);
		$closing = $fill(1750200000, 26000, true, 120600);

		$row = $this->timeline->page(self::AUTHOR, $this->uuid, TimelineService::ENERGY, null)['rows'][0];

		$this->assertSame($closing['uuid'], $row['energy']->getUuid());
		$this->assertSame([36000, 600, 6.0], [$row['consumption']['amount'], $row['consumption']['distance'], $row['consumption']['value']]);
	}

	/**
	 * One Entry read back as its row, for the sheet that edits it to write under a fresh token. It
	 * carries what the page would: the Readings and the flags. A Reading another Entry wrote is not
	 * an Entry, and a deleted row is not there.
	 */
	public function testOneEntryReadsBackAsItsRow(): void {
		$energy = $this->container->get(EnergyService::class);
		$fill = $energy->record(self::AUTHOR, $this->uuid, [
			'filled_at' => 1750100000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 40000, 'odo' => 120500,
		]);

		$row = $this->timeline->one(self::AUTHOR, $this->uuid, TimelineService::ENERGY, $fill['uuid']);

		$this->assertSame([TimelineService::ENERGY, 1750100000, 120], [$row['type'], $row['occurred_at'], $row['occurred_at_off']]);
		$this->assertSame($fill['uuid'], $row['energy']->getUuid());
		$this->assertSame([120500], array_map(static fn (OdoReading $r): int => $r->getValue(), $row['readings']));
		$this->assertSame([EnergyService::FOREIGN_ENERGY, EnergyService::NO_PRICE], $row['flags']);

		$trip = $this->trip(1750200000, 120800);
		$tripReading = $this->timeline->one(self::AUTHOR, $this->uuid, TimelineService::TRIP, $trip->getUuid())['reading'];
		$this->assertSame(120800, $tripReading->getValue());
		$this->assertMissing(TimelineService::ODOMETER, $tripReading->getUuid());

		$energy->delete(self::AUTHOR, $this->uuid, $fill['uuid'], $fill['updated_at']);
		$this->assertMissing(TimelineService::ENERGY, $fill['uuid']);
	}

	private function assertMissing(string $type, string $uuid): void {
		$found = true;
		try {
			$this->timeline->one(self::AUTHOR, $this->uuid, $type, $uuid);
		} catch (DoesNotExistException) {
			$found = false;
		}
		$this->assertFalse($found, $type . ' ' . $uuid . ' was found');
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to build
	 * the whole chain from constructor types alone.
	 */
	public function testTheServiceIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			TimelineService::class,
			(new Application())->getContainer()->get(TimelineService::class),
		);
	}
}
