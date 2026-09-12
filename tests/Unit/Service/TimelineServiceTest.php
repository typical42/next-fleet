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
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
	private int $nextTripId = 1;
	private int $nextReadingId = 1;

	private TripMapper&MockObject $trips;
	private OdoReadingMapper&MockObject $readings;
	private VehicleService&MockObject $fleet;

	protected function setUp(): void {
		$this->tripRows = [];
		$this->readingRows = [];
		$this->nextTripId = 1;
		$this->nextReadingId = 1;

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

		$this->readings->method('findForTrips')->willReturnCallback(
			fn (int $vehicleId, array $tripIds): array => array_values(array_filter(
				$this->readingRows,
				static fn (OdoReading $row): bool => $row->getVehicleId() === $vehicleId
					&& $row->getSourceType() === OdoReading::TRIP
					&& in_array((int)$row->getSourceId(), $tripIds, true),
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

				return Vehicle::fromRow(['id' => self::VEHICLE_ID, 'uuid' => self::VEHICLE]);
			},
		);
	}

	/**
	 * What both mapper reads answer: one vehicle's rows strictly before `(instant, id)`, newest
	 * first, at most `$limit` of them.
	 *
	 * @param list<Trip|OdoReading> $rows
	 * @param array{int, int} $before
	 * @return list<Trip|OdoReading>
	 */
	private function before(array $rows, int $vehicleId, array $before, int $limit): array {
		$mine = array_values(array_filter(
			$rows,
			static fn (Trip|OdoReading $row): bool => $row->getVehicleId() === $vehicleId
				&& self::key($row) < $before,
		));
		usort($mine, static fn (Trip|OdoReading $a, Trip|OdoReading $b): int => self::key($b) <=> self::key($a));

		return array_slice($mine, 0, $limit);
	}

	/**
	 * What both reads order by: when the row happened, then its id. A trip is dated by when it set
	 * off and a Reading by when it was read - one fact under two tables' names.
	 *
	 * @return array{int, int}
	 */
	private static function key(Trip|OdoReading $row): array {
		return [
			$row instanceof Trip ? $row->getStartedAt() : $row->getReadAt(),
			(int)$row->getId(),
		];
	}

	private function service(): TimelineService {
		return new TimelineService($this->trips, $this->readings, $this->fleet);
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
	 * A kind nobody serves is refused rather than answered with everything, which would look to a
	 * client like a filter that silently does nothing.
	 */
	public function testAKindTheTimelineDoesNotServeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->page(self::OWNER, self::VEHICLE, 'energy', null);
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
		yield 'a kind nobody serves' => ['1750000000:energy:4'];
		yield 'an instant that is not a number' => ['soon:trip:4'];
		yield 'an id that is not a number' => ['1750000000:trip:'];
		yield 'nothing to break a tie with' => ['1750000000:trip'];
		yield 'a word' => ['top'];
	}
}
