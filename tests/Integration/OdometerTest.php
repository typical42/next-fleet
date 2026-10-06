<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The odometer against the real database: the order the readings come back in, and what the
 * vehicle shows once they have.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class OdometerTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
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
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_trips' => 'created_by', 'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_audit' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * Rule 2 as the column sees it: the vehicle caches the newest reading, and the cache is not
	 * what a client edited - so writing it must leave the row's concurrency token where it was,
	 * or every open sheet loses its next save to an odometer entry.
	 */
	public function testTheVehicleCachesTheNewestReadingWithoutMovingItsToken(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->odometer->record(self::OWNER, $vehicle->getUuid(), $this->at(1750000000, 120450));

		$read = $this->vehicles->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame(120450, $read->getOdoValue());
		$this->assertSame($vehicle->getUpdatedAt(), $read->getUpdatedAt());
	}

	/**
	 * Rule 1 through the query that has to hold it up: the readings come back by when they were
	 * read, not by when they were entered, and the flags follow that order.
	 */
	public function testAReadingEnteredLateLandsWhereItHappened(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$backwards = $this->odometer->record(self::OWNER, $uuid, $this->at(1750172800, 119000));
		$this->assertTrue($backwards->getFlagged());

		$this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 118000));

		$chain = [];
		foreach ($this->odometer->list(self::OWNER, $uuid) as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}
		$this->assertSame([120000 => false, 118000 => true, 119000 => false], $chain);
		$this->assertSame(119000, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		// A flag that changed moves the token, so sync sends that row; one that held does not.
		$this->assertGreaterThan($backwards->getUpdatedAt(), $this->reading($uuid, 119000)->getUpdatedAt());
	}

	/**
	 * A sheet opened on a Reading before another entry changed its flag holds a token that moved:
	 * its save is refused, and the sheet shows the Reading as it now stands.
	 */
	public function testAFlagChangedSinceTheReadRefusesTheSave(): void {
		$uuid = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$open = $this->odometer->record(self::OWNER, $uuid, $this->at(1750172800, 121000));
		$untouched = $this->odometer->record(self::OWNER, $uuid, $this->at(1750259200, 125000));

		$this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 122000));

		$this->assertSame($untouched->getUpdatedAt(), $this->reading($uuid, 125000)->getUpdatedAt());
		$this->expectException(StaleUpdateException::class);
		$this->odometer->update(self::OWNER, $uuid, $open->getUuid(), $open->getUpdatedAt(), $this->at(1750172800, 121500));
	}

	private function reading(string $vehicleUuid, int $value): OdoReading {
		foreach ($this->odometer->list(self::OWNER, $vehicleUuid) as $reading) {
			if ($reading->getValue() === $value) {
				return $reading;
			}
		}

		$this->fail('no reading of ' . $value . ' on this vehicle');
	}

	/**
	 * Rule 6 through the same query: a distance counts from the newest reading at or before the
	 * moment it was driven, and a reading after that moment is not it.
	 */
	public function testADistanceCountsFromTheNewestReadingBeforeIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750259200, 121000));

		$derived = $this->odometer->record(self::OWNER, $uuid, [
			'read_at' => 1750086400,
			'read_at_off' => -300,
			'distance' => 137,
		]);

		$this->assertSame(120137, $derived->getValue());
		$this->assertSame(OdometerService::DERIVED, $derived->getOrigin());
		$this->assertSame(-300, $derived->getReadAtOff());
		// And the derived row sits between the two, not after them.
		$this->assertSame(
			[120000, 120137, 121000],
			array_map(
				static fn (OdoReading $reading): int => $reading->getValue(),
				$this->odometer->list(self::OWNER, $uuid),
			),
		);
	}

	/**
	 * A Reading written before the `counter` column has none and lives on the km chain: a distance
	 * counts from it and the vehicle caches it, while the hour chain never sees it.
	 */
	public function testAReadingWrittenBeforeTheCounterColumnIsOnTheMainChain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'second_unit' => 'h']);
		$legacy = new OdoReading();
		$legacy->setVehicleId((int)$vehicle->getId());
		$legacy->setCreatedBy(self::OWNER);
		$legacy->setReadAt(1750000000);
		$legacy->setReadAtOff(0);
		$legacy->setValue(120000);
		$legacy->setKind('reading');
		$legacy->setOrigin(OdometerService::OBSERVED);
		$legacy->setSourceType(OdoReading::MANUAL);
		$readings = \OCP\Server::get(OdoReadingMapper::class);
		$readings->insert($legacy);

		$derived = $this->odometer->record(self::OWNER, $vehicle->getUuid(), [
			'read_at' => 1750086400,
			'read_at_off' => 0,
			'distance' => 137,
		]);

		$this->assertSame(120137, $derived->getValue());
		$this->assertSame([], $readings->findChain((int)$vehicle->getId(), OdoReading::SECOND));
		$this->assertSame(120137, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * `read_at_off` 0 (UTC) and `value` 0 equal the property defaults, which QBMapper reads as
	 * unchanged and leaves out of the INSERT, where the NOT NULL columns would refuse the row.
	 * Only the real database shows it is written.
	 */
	public function testAReadingOfZeroInUtcIsARowTheDatabaseAccepts(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->odometer->record(self::OWNER, $vehicle->getUuid(), [
			'read_at' => 1750000000,
			'read_at_off' => 0,
			'value' => 0,
		]);

		$read = $this->odometer->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(1, $read);
		$this->assertSame($written->getUuid(), $read[0]->getUuid());
		$this->assertSame(0, $read[0]->getValue());
		$this->assertSame(0, $read[0]->getReadAtOff());
	}

	/**
	 * An Odometer Entry edited from its row: the same Reading takes the new number and moment, and
	 * the chain around it is flagged and cached again.
	 */
	public function testAnEditedEntryIsRestatedWithTheChain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$typo = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 12050));
		$this->assertTrue($typo->getFlagged());

		$fixed = $this->odometer->update(self::OWNER, $uuid, $typo->getUuid(), $typo->getUpdatedAt(), [
			'read_at' => 1750090000,
			'read_at_off' => 60,
			'value' => 120500,
		]);

		$this->assertSame($typo->getUuid(), $fixed->getUuid());
		$this->assertSame([120500, 1750090000, 60, false], [$fixed->getValue(), $fixed->getReadAt(), $fixed->getReadAtOff(), $fixed->getFlagged()]);
		$this->assertGreaterThan($typo->getUpdatedAt(), $fixed->getUpdatedAt());
		$this->assertSame(120500, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		$this->expectException(StaleUpdateException::class);
		$this->odometer->update(self::OWNER, $uuid, $typo->getUuid(), $typo->getUpdatedAt(), $this->at(1750090000, 120600));
	}

	/**
	 * A number put on the wrong counter moves to the other one, and both chains are settled: the
	 * one it left and the one it joined.
	 */
	public function testAnEditMovesAnEntryToTheOtherCounter(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'second_unit' => 'h']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$hours = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 3400));

		$this->odometer->update(self::OWNER, $uuid, $hours->getUuid(), $hours->getUpdatedAt(), $this->at(1750086400, 3400) + ['counter' => 'second']);

		$read = $this->vehicles->find(self::OWNER, $uuid);
		$this->assertSame([120000, 3400], [$read->getOdoValue(), $read->getSecondValue()]);
	}

	/**
	 * Delete and undo, on the token the delete answered with: the counter falls back to the Reading
	 * before, and comes back with it.
	 */
	public function testADeletedEntryLeavesTheChainAndItsUndoBringsItBack(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$newest = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 120500));

		$deleted = $this->odometer->delete(self::OWNER, $uuid, $newest->getUuid(), $newest->getUpdatedAt());

		$this->assertNotNull($deleted->getDeletedAt());
		$this->assertSame(120000, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());

		$back = $this->odometer->restore(self::OWNER, $uuid, $newest->getUuid(), $deleted->getUpdatedAt());

		$this->assertNull($back->getDeletedAt());
		$this->assertGreaterThan($deleted->getUpdatedAt(), $back->getUpdatedAt());
		$this->assertSame(120500, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/**
	 * A Reading a trip or a fill-up wrote is that Entry's, and is edited through it (rule 5). Here
	 * it is not found, as a Reading on another vehicle is.
	 */
	public function testOnlyAnOdometerEntryIsEditedAsOne(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$trip = \OCP\Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 120450,
			'category' => 'private',
		]);
		$reading = $this->reading($vehicle->getUuid(), 120450);
		$this->assertSame($trip->getId(), $reading->getSourceId());

		$this->expectException(DoesNotExistException::class);
		$this->odometer->delete(self::OWNER, $vehicle->getUuid(), $reading->getUuid(), $reading->getUpdatedAt());
	}

	/** A client ties each Reading to the Entry that wrote it by uuid; an Odometer Entry names none. */
	public function testEachListedReadingNamesTheEntryThatWroteIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->odometer->record(self::OWNER, $vehicle->getUuid(), $this->at(1749900000, 120000));
		$trip = \OCP\Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 120450,
			'category' => 'private',
		]);
		$fillUp = \OCP\Server::get(EnergyService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'filled_at' => 1750100000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'total' => 7350, 'full_tank' => true, 'odo' => 120900,
		]);
		$work = \OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'done_at' => 1750200000, 'done_at_off' => 120, 'title' => 'Oil change', 'cost' => 18990, 'odo' => 121300,
		]);

		$named = array_map(
			static fn (OdoReading $reading): array => [$reading->getValue(), $reading->jsonSerialize()['source_uuid']],
			$this->odometer->list(self::OWNER, $vehicle->getUuid()),
		);

		$this->assertSame([null, $trip->getUuid(), $fillUp['uuid'], $work['uuid']], array_column($named, 1));
		$this->assertSame([120000, 120450, 120900, 121300], array_column($named, 0));
	}

	/**
	 * Rule 3's answer "the counter was replaced": the lower Reading stands as a reset, and the
	 * Readings after it are judged from the new counter, not the old one.
	 */
	public function testACounterReplacedStandsAndTheChainCountsOnFromIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$swap = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 30));
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750172800, 400));
		$this->assertTrue($swap->getFlagged());

		$answered = $this->odometer->reset(self::OWNER, $uuid, $swap->getUuid(), $swap->getUpdatedAt());

		$this->assertSame(OdoReading::RESET, $answered->getKind());
		$this->assertFalse($answered->getFlagged());
		$chain = [];
		foreach ($this->odometer->list(self::OWNER, $uuid) as $reading) {
			$chain[$reading->getValue()] = $reading->getFlagged();
		}
		$this->assertSame([120000 => false, 30 => false, 400 => false], $chain);
		$this->assertSame(400, $this->vehicles->find(self::OWNER, $uuid)->getOdoValue());
	}

	/** Only a Reading in question asks it; a counter that rose was not replaced. */
	public function testAReadingNotInQuestionIsNoReset(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$read = $this->odometer->record(self::OWNER, $vehicle->getUuid(), $this->at(1750000000, 120000));

		try {
			$this->odometer->reset(self::OWNER, $vehicle->getUuid(), $read->getUuid(), $read->getUpdatedAt());
			$this->fail('a reading nobody questioned became a reset');
		} catch (RefusedException $e) {
			$this->assertSame('not_in_question', $e->reason);
		}
		$this->assertSame(OdoReading::READING, $this->reading($vehicle->getUuid(), 120000)->getKind());
	}

	/** The reset is the number's answer; a number edited since asks the question afresh. */
	public function testAnEditedResetIsAReadingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$swap = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 30));
		$answered = $this->odometer->reset(self::OWNER, $uuid, $swap->getUuid(), $swap->getUpdatedAt());

		$typo = $this->odometer->update(self::OWNER, $uuid, $swap->getUuid(), $answered->getUpdatedAt(), $this->at(1750086400, 3000));

		$this->assertSame(OdoReading::READING, $typo->getKind());
		$this->assertTrue($typo->getFlagged());
	}

	/** Moved to the hour counter, the number answers nothing about the kilometres it left. */
	public function testAResetMovedToTheOtherCounterIsAReadingAgain(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'second_unit' => 'h']);
		$uuid = $vehicle->getUuid();
		$this->odometer->record(self::OWNER, $uuid, $this->at(1750000000, 120000));
		$swap = $this->odometer->record(self::OWNER, $uuid, $this->at(1750086400, 30));
		$answered = $this->odometer->reset(self::OWNER, $uuid, $swap->getUuid(), $swap->getUpdatedAt());

		$moved = $this->odometer->update(self::OWNER, $uuid, $swap->getUuid(), $answered->getUpdatedAt(), ['counter' => OdoReading::SECOND] + $this->at(1750086400, 30));

		$this->assertSame(OdoReading::READING, $moved->getKind());
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to
	 * build the whole chain from constructor types alone.
	 */
	public function testTheServiceIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			OdometerService::class,
			\OCP\Server::get(OdometerService::class),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function at(int $readAt, int $value): array {
		return ['read_at' => $readAt, 'read_at_off' => 120, 'value' => $value];
	}
}
