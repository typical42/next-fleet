<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The six rules of docs/architecture.md#odometer-rules, one test each: what a Reading is worth
 * against the ones around it, and what the vehicle then shows.
 */
class OdometerServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const STRANGER = 'bob';

	/** The readings the mapper stands for, in insertion order. @var list<OdoReading> */
	private array $rows = [];
	private int $nextId = 1;
	private ?int $cached = null;

	private OdoReadingMapper&MockObject $readings;
	private VehicleMapper&MockObject $vehicles;
	private VehicleService&MockObject $fleet;

	protected function setUp(): void {
		$this->rows = [];
		$this->nextId = 1;
		$this->cached = null;

		// A store rather than an expectation: every rule here is about a reading read back
		// against its neighbours, which a per-call mock cannot say anything about.
		$this->readings = $this->createMock(OdoReadingMapper::class);
		$this->readings->method('insert')->willReturnCallback(function (OdoReading $reading): OdoReading {
			// The identity and the dating are the base mapper's (BaseMapperTest), and a store
			// that handed out neither could not tell two readings apart.
			$reading->setId($this->nextId);
			$reading->setUuid('0195e2f1-0000-4000-8000-00000000000' . $this->nextId++);
			$this->rows[] = $reading;

			return $reading;
		});
		$this->readings->method('flag')->willReturnCallback(
			static function (OdoReading $reading, bool $flagged): void {
				$reading->setFlagged($flagged);
			},
		);
		$this->readings->method('findAllForVehicle')
			->willReturnCallback(fn (int $vehicleId): array => $this->ordered($vehicleId));
		$this->readings->method('findNewestAtOrBefore')->willReturnCallback(
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

		// Who reaches which vehicle is VehicleAccessTest's; here the gate stands for the answer,
		// so what these tests state is which operation the odometer asks it for.
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
	}

	private function service(): OdometerService {
		return new OdometerService($this->readings, $this->vehicles, $this->fleet);
	}

	private function vehicle(): Vehicle {
		return Vehicle::fromRow([
			'id' => 7,
			'uuid' => self::VEHICLE,
			'user_id' => self::OWNER,
			'odo_unit' => 'km',
			'updated_at' => 1750000000,
		]);
	}

	/**
	 * Readings come back in `(read_at, id)` order, which is the mapper's contract
	 * (OdometerTest checks the query itself keeps it).
	 *
	 * @return list<OdoReading>
	 */
	private function ordered(int $vehicleId): array {
		$rows = array_values(array_filter(
			$this->rows,
			static fn (OdoReading $reading): bool => $reading->getVehicleId() === $vehicleId,
		));
		usort($rows, static fn (OdoReading $a, OdoReading $b): int
			=> [$a->getReadAt(), $a->getId()] <=> [$b->getReadAt(), $b->getId()]);

		return $rows;
	}

	/**
	 * The plain case, and rule 2 with it: a number somebody read off the counter is observed, and
	 * the vehicle caches it rather than counting anything up.
	 */
	public function testANumberOffTheCounterIsWhatTheVehicleThenShows(): void {
		$reading = $this->service()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750000000,
			'read_at_off' => 120,
			'value' => '120450',
		]);

		$this->assertSame(120450, $reading->getValue());
		$this->assertSame('observed', $reading->getOrigin());
		$this->assertFalse($reading->getFlagged());
		$this->assertSame(120450, $this->cached);
	}

	/**
	 * Rule 3: cluster swaps and imports really do reset the counter, and the app cannot tell one
	 * from a typo - so the row is saved, flagged, and the timeline asks.
	 */
	public function testAReadingThatWentBackwardsIsFlaggedAndNotRefused(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120450));

		$back = $service->record(self::OWNER, self::VEHICLE, $this->at(1750086400, 4200));

		$this->assertTrue($back->getFlagged());
		$this->assertSame(4200, $back->getValue());
		// The counter is what the counter says, flag or no flag (rule 2).
		$this->assertSame(4200, $this->cached);
	}

	/**
	 * Rule 6: a driver who knows the distance and not the counter leaves the number out, and the
	 * Reading is computed from the last one - marked as computed, because consumption may not
	 * rest on it.
	 */
	public function testADistanceInsteadOfANumberDerivesTheReading(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120450));

		$derived = $service->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750086400,
			'read_at_off' => 120,
			'distance' => '137',
		]);

		$this->assertSame(120587, $derived->getValue());
		$this->assertSame(OdometerService::DERIVED, $derived->getOrigin());
		$this->assertFalse($derived->getFlagged());
		$this->assertSame(120587, $this->cached);
	}

	/**
	 * The other half of rule 6: a number somebody actually read wins over the ones the app
	 * computed, so the derived rows it contradicts are flagged - never silently corrected, and
	 * never at the cost of flagging the one row that is certainly right.
	 */
	public function testAnObservedReadingBeatsTheDerivedChainItContradicts(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750086400, 400));
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750172800, 400));

		$observed = $service->record(self::OWNER, self::VEHICLE, $this->at(1750259200, 120300));

		$this->assertFalse($observed->getFlagged());
		$this->assertSame(
			[120000 => false, 120400 => true, 120800 => true, 120300 => false],
			$this->chain($service),
		);
		$this->assertSame(120300, $this->cached);
	}

	/**
	 * Rules 3 and 6 are not alternatives. A derived row between the two observed ones is
	 * contradicted and flagged - and the counter still went back on numbers somebody read, so
	 * that row is in question too. Blaming the computed row alone would hide a cluster swap.
	 */
	public function testAnObservedReadingBelowTheLastObservedOneIsInQuestionToo(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120000));
		$service->record(self::OWNER, self::VEHICLE, $this->drove(1750086400, 50));

		$service->record(self::OWNER, self::VEHICLE, $this->at(1750172800, 119000));

		$this->assertSame([120000 => false, 120050 => true, 119000 => true], $this->chain($service));
	}

	/**
	 * Rule 1: a Reading entered three days late is dated three days back, so it lands between
	 * two that were already there - and both of its new neighbours are judged again. The row
	 * that read backwards now follows a smaller number and is no longer in question; the late
	 * row has taken its place.
	 */
	public function testAReadingEnteredLateIsJudgedWhereItHappened(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120000));
		$backwards = $service->record(self::OWNER, self::VEHICLE, $this->at(1750172800, 119000));
		$this->assertTrue($backwards->getFlagged());

		$late = $service->record(self::OWNER, self::VEHICLE, $this->at(1750086400, 118000));

		$this->assertTrue($late->getFlagged());
		$this->assertSame([120000 => false, 118000 => true, 119000 => false], $this->chain($service));
		// The newest reading is the newest by date, not the largest number.
		$this->assertSame(119000, $this->cached);
	}

	/**
	 * A column a property default merely agrees with is not dirty, so QBMapper leaves it out of
	 * the INSERT and the NOT NULL constraint refuses the row. Every one of them is written.
	 *
	 * @dataProvider notNullColumns
	 */
	public function testARecordWritesEveryColumnTheDatabaseWillNotDefault(string $property): void {
		$this->service()->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120450));

		$this->assertArrayHasKey($property, $this->rows[0]->getUpdatedFields());
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function notNullColumns(): iterable {
		yield 'vehicle_id' => ['vehicleId'];
		yield 'created_by' => ['createdBy'];
		yield 'read_at' => ['readAt'];
		yield 'read_at_off' => ['readAtOff'];
		yield 'value' => ['value'];
		yield 'kind' => ['kind'];
		yield 'origin' => ['origin'];
		yield 'source_type' => ['sourceType'];
	}

	/**
	 * The sheet toggles between the counter and the distance (docs/ui.md), so both at once is a
	 * client that does not know which it means. Picking one would silently throw away a number
	 * somebody read, which is the opposite of rule 6.
	 */
	public function testANumberAndADistanceAtOnceIsRefused(): void {
		$service = $this->service();
		$service->record(self::OWNER, self::VEHICLE, $this->at(1750000000, 120000));

		$this->expectException(\InvalidArgumentException::class);
		$service->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750086400,
			'read_at_off' => 120,
			'value' => 120450,
			'distance' => 137,
		]);
	}

	/**
	 * The moment is stored with the offset it was read at (docs/architecture.md#time), and half
	 * the world's offsets are negative.
	 */
	public function testAReadingKeepsTheOffsetItWasReadAt(): void {
		$reading = $this->service()->record(self::OWNER, self::VEHICLE, [
			'read_at' => 1750000000,
			'read_at_off' => '-300',
			'value' => 120450,
		]);

		$this->assertSame(1750000000, $reading->getReadAt());
		$this->assertSame(-300, $reading->getReadAtOff());
	}

	/**
	 * The database would refuse each of these too, but as a 500 that names no field. Refusing
	 * them here is what makes the answer a 400 the sheet can point at - which is not the same as
	 * blocking on an implausible number, and rule 3 is why (docs/ui.md).
	 *
	 * @param array<string, mixed> $fields
	 * @dataProvider misshapenReadings
	 */
	public function testARecordTheColumnsCannotHoldIsRefused(array $fields): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->record(self::OWNER, self::VEHICLE, $fields);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function misshapenReadings(): iterable {
		yield 'no moment' => [['read_at_off' => 120, 'value' => 120450]];
		yield 'no number at all' => [['read_at' => 1750000000, 'read_at_off' => 120]];
		yield 'a number that is not one' => [['read_at' => 1750000000, 'read_at_off' => 120, 'value' => 'full']];
		yield 'a counter below zero' => [['read_at' => 1750000000, 'read_at_off' => 120, 'value' => -1]];
		yield 'a fractional counter' => [['read_at' => 1750000000, 'read_at_off' => 120, 'value' => '4.5']];
		yield 'an offset no clock has' => [['read_at' => 1750000000, 'read_at_off' => 2000, 'value' => 120450]];
		yield 'a distance with nothing before it' => [['read_at' => 1750000000, 'read_at_off' => 120, 'distance' => 137]];
	}

	/**
	 * A uuid is all it takes to name a vehicle, and everything hanging off one goes through the
	 * same gate (docs/security.md). Nothing is written on the way to the refusal.
	 */
	public function testAStrangerNeitherReadsNorWritesAnOdometer(): void {
		try {
			$this->service()->record(self::STRANGER, self::VEHICLE, $this->at(1750000000, 120450));
			$this->fail('a stranger wrote a reading');
		} catch (AccessDeniedException) {
			$this->assertSame([], $this->rows);
			$this->assertNull($this->cached);
		}

		$this->expectException(AccessDeniedException::class);
		$this->service()->list(self::STRANGER, self::VEHICLE);
	}

	/**
	 * A co-driver who may look at the vehicle may not move its odometer: writing a Reading
	 * rewrites what the vehicle shows, so it asks to edit, not to view.
	 */
	public function testAGrantToLookIsNotAGrantToWriteAReading(): void {
		$this->assertSame([], $this->service()->list(self::DRIVER, self::VEHICLE));

		$this->expectException(AccessDeniedException::class);
		$this->service()->record(self::DRIVER, self::VEHICLE, $this->at(1750000000, 120450));
	}

	/**
	 * The vehicle's odometer as the timeline shows it: what each Reading holds, and whether it
	 * is still waiting for its follow-up question.
	 *
	 * @return array<int, bool>
	 */
	private function chain(OdometerService $service): array {
		$chain = [];
		foreach ($service->list(self::OWNER, self::VEHICLE) as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}

		return $chain;
	}

	/**
	 * An Entry that knows the distance and not the counter.
	 *
	 * @return array<string, mixed>
	 */
	private function drove(int $readAt, int $distance): array {
		return ['read_at' => $readAt, 'read_at_off' => 120, 'distance' => $distance];
	}

	/**
	 * A Reading as the sheet posts one: the moment, its offset, the number.
	 *
	 * @return array<string, mixed>
	 */
	private function at(int $readAt, int $value): array {
		return ['read_at' => $readAt, 'read_at_off' => 120, 'value' => $value];
	}
}
