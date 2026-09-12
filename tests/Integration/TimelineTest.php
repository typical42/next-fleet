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
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

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

	private TimelineService $timeline;
	private TripService $trips;
	private TripMapper $tripRows;
	private OdometerService $odometer;
	private VehicleService $vehicles;
	private string $uuid;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
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
	 * The chip, as the database narrows it: one kind of Entry and nothing else about the vehicle.
	 */
	public function testAChipNarrowsTheTimelineToOneKind(): void {
		$this->trip(1750000000, 120450);
		$entry = $this->entry(1750100000, 120500);

		$page = $this->timeline->page(self::AUTHOR, $this->uuid, TimelineService::ODOMETER, null);

		$this->assertSame(['odometer ' . $entry->getUuid()], $this->shown($page));
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
