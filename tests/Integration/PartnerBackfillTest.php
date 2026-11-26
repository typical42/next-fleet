<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\PartnerBackfill;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Migration\Version000013Date20261125000000;
use OCA\NextFleet\Repair\UnlinkedPartners;
use OCA\NextFleet\Service\PartnerLinks;
use OCA\NextFleet\Service\SyncEpoch;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every partner text becomes a record in its owner's list and the trip links it, while no trip's
 * text or token moves (ADR 0013). The trips are written as a release before the links wrote them.
 */
class PartnerBackfillTest extends TestCase {
	private const OWNER = 'nextfleet-test-ptn-owner';
	private const OTHER = 'nextfleet-test-ptn-other';

	/** A token from long before the run, so a run that stamped one would show. */
	private const OLD_TOKEN = 1700000000;

	private IDBConnection $db;
	private SyncEpoch&MockObject $epoch;
	private PartnerBackfill $backfill;

	/** The run reaches every trip on the instance, so each test is rolled back. */
	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->epoch = $this->createMock(SyncEpoch::class);
		$this->backfill = new PartnerBackfill($this->db, Server::get(ISecureRandom::class), Server::get(ITimeFactory::class), $this->epoch);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	public function testEachDistinctTextOfAnOwnerIsOneRecordForAllTheirVehicles(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$van = $this->vehicle(self::OWNER, 'NF-PB 2');
		$a = $this->trip($car, 'Müller GmbH');
		$b = $this->trip($van, 'Müller GmbH');
		$c = $this->trip($car, 'Schneider AG');

		$this->assertSame(3, $this->backfill->run());

		$this->assertSame(['Müller GmbH', 'Schneider AG'], $this->names(self::OWNER));
		$this->assertSame($this->link($a), $this->link($b));
		$this->assertNotSame($this->link($a), $this->link($c));
	}

	/** The record carries the trimmed NFC name; the trip keeps what was typed. */
	public function testTrimmedAndComposedTextsAreOneRecordAndKeepTheirText(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$padded = $this->trip($car, '  Müller ');
		$decomposed = $this->trip($car, "Mu\u{0308}ller");

		$this->backfill->run();

		$this->assertSame(["M\u{00FC}ller"], $this->names(self::OWNER));
		$this->assertSame($this->link($padded), $this->link($decomposed));
		$this->assertSame(['  Müller ', "Mu\u{0308}ller"], [$this->text($padded), $this->text($decomposed)]);
	}

	/** A variant spelling stays its own record until the owner merges it. */
	public function testTheCaseIsKept(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$this->trip($car, 'Müller');
		$this->trip($car, 'müller');

		$this->backfill->run();

		$this->assertSame(['Müller', 'müller'], $this->names(self::OWNER));
	}

	public function testAVoidedTripIsLinkedToo(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$voided = $this->trip($car, 'Müller', voided: true);

		$this->backfill->run();

		$this->assertNotNull($this->link($voided));
	}

	public function testTwoOwnersWithOneTextGetARecordEach(): void {
		$mine = $this->trip($this->vehicle(self::OWNER, 'NF-PB 1'), 'Müller');
		$theirs = $this->trip($this->vehicle(self::OTHER, 'NF-PB 3'), 'Müller');

		$this->backfill->run();

		$this->assertSame(['Müller'], $this->names(self::OWNER));
		$this->assertSame(['Müller'], $this->names(self::OTHER));
		$this->assertNotSame($this->link($mine), $this->link($theirs));
	}

	/** A trip without a partner, or with only blanks, names nobody. */
	public function testATripThatNamesNobodyGetsNoRecord(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$none = $this->trip($car, null);
		$blank = $this->trip($car, '   ');

		$this->assertSame(0, $this->backfill->run());

		$this->assertSame([], $this->names(self::OWNER));
		$this->assertSame([null, null], [$this->link($none), $this->link($blank)]);
	}

	public function testNoTextAndNoTokenMoves(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$trips = [$this->trip($car, 'Müller'), $this->trip($car, ' Schneider '), $this->trip($car, 'Weber', voided: true)];
		$before = $this->stored($trips);

		$this->backfill->run();

		$this->assertSame($before, $this->stored($trips));
	}

	/** Clients learn the links by starting their sync over, as no token moved. */
	public function testARunThatLinksStartsEverySyncOver(): void {
		$this->trip($this->vehicle(self::OWNER, 'NF-PB 1'), 'Müller');
		$this->epoch->expects($this->once())->method('reset');

		$this->backfill->run();
	}

	public function testASecondRunCreatesAndChangesNothing(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$trips = [$this->trip($car, 'Müller'), $this->trip($car, 'Weber', voided: true)];
		$this->backfill->run();
		$records = $this->records(self::OWNER);
		$after = $this->stored($trips);

		$second = new PartnerBackfill($this->db, Server::get(ISecureRandom::class), Server::get(ITimeFactory::class), $epoch = $this->createMock(SyncEpoch::class));
		$epoch->expects($this->never())->method('reset');

		$this->assertSame(0, $second->run());
		$this->assertSame($records, $this->records(self::OWNER));
		$this->assertSame($after, $this->stored($trips));
	}

	/** A run that died after writing a record finds it by name instead of writing another. */
	public function testARecordOfTheNameIsTakenRatherThanASecond(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$first = $this->trip($car, 'Müller');
		$this->backfill->run();
		$later = $this->trip($car, 'Müller');

		$this->backfill->run();

		$this->assertSame(['Müller'], $this->names(self::OWNER));
		$this->assertSame($this->link($first), $this->link($later));
	}

	/**
	 * More records and trips than one `IN` list takes on Oracle (InList), and one partner with
	 * more trips than that.
	 */
	public function testMoreThanAThousandPartnersAreAllLinked(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$trips = [];
		for ($i = 1; $i <= 1001; $i++) {
			$trips[] = $this->trip($car, 'Partner ' . $i);
		}
		for ($i = 1; $i <= 1001; $i++) {
			$trips[] = $this->trip($car, 'Partner 1');
		}

		$this->assertSame(2002, $this->backfill->run());

		$this->assertCount(1001, $this->names(self::OWNER));
		$this->assertNotContains(null, array_map(fn (Trip $trip): ?int => $this->link($trip), $trips));
		$this->assertCount(1, array_unique(array_map(fn (Trip $trip): ?int => $this->link($trip), [$trips[0], ...array_slice($trips, 1001)])));
	}

	/** The step runs the backfill after its schema change. */
	public function testTheStepRunsTheBackfill(): void {
		$trip = $this->trip($this->vehicle(self::OWNER, 'NF-PB 1'), 'Müller');
		$step = new Version000013Date20261125000000($this->db, $this->backfill);

		$step->postSchemaChange($this->createMock(IOutput::class), fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		$this->assertNotNull($this->link($trip));
	}

	/**
	 * `occ maintenance:repair` links what `nextfleet:check` reports as `partner`, and says so. It
	 * runs on a live server, so the link moves the token as any write does, and sync sees it.
	 */
	public function testTheRepairStepLinksWhatTheCheckFinds(): void {
		$trip = $this->trip($this->vehicle(self::OWNER, 'NF-PB 1'), 'Müller');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringStartsWith('Trips linked to a partner record: '));

		Server::get(UnlinkedPartners::class)->run($output);

		$this->assertNotNull($this->link($trip));
		$this->assertSame(['Müller'], $this->names(self::OWNER));
		$this->assertSame('Müller', $this->text($trip));
		$this->assertNotSame(self::OLD_TOKEN, (int)$this->row($trip)['updated_at']);
	}

	/** As a trip written in the app would: a spelling merged away finds the record it went into. */
	public function testTheRepairStepFindsTheRecordAnotherTripWithTheTextLinks(): void {
		$car = $this->vehicle(self::OWNER, 'NF-PB 1');
		$merged = $this->trip($car, 'Mueller GmbH');
		$this->backfill->run();
		$this->rename((int)$this->link($merged), 'Müller GmbH');
		$late = $this->trip($car, 'Mueller GmbH');

		Server::get(UnlinkedPartners::class)->run($this->createMock(IOutput::class));

		$this->assertSame($this->link($merged), $this->link($late));
		$this->assertSame(['Müller GmbH'], $this->names(self::OWNER));
	}

	/** With nothing to link, the repair says nothing. */
	public function testTheRepairStepIsSilentWithNothingToLink(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		Server::get(UnlinkedPartners::class)->run($output);
	}

	/** The upgrade to 1.0.0 linked every trip in its migration step, just before. */
	public function testTheRepairStepLeavesTheUpgradeTo100ToTheMigration(): void {
		$trip = $this->trip($this->vehicle(self::OWNER, 'NF-PB 1'), 'Müller');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0.6.2');

		(new UnlinkedPartners(
			Server::get(TripMapper::class),
			Server::get(VehicleMapper::class),
			Server::get(PartnerLinks::class),
			Server::get(TripService::class),
			$config,
			$this->db,
		))->run($this->createMock(IOutput::class));

		$this->assertNull($this->link($trip));
	}

	private function vehicle(string $owner, string $plate): Vehicle {
		return Server::get(VehicleService::class)->create($owner, ['plate' => $plate]);
	}

	/** A trip as a release before the links wrote it: no link, an old token. */
	private function trip(Vehicle $vehicle, ?string $partner, bool $voided = false): Trip {
		$trip = new Trip();
		$trip->setVehicleId((int)$vehicle->getId());
		$trip->setCreatedBy($vehicle->getUserId());
		$trip->setStartedAt(1750000000);
		$trip->setEndedAt(1750003600);
		$trip->setDistance(12);
		$trip->setCategory(Trip::BUSINESS);
		$trip = Server::get(TripMapper::class)->insert($trip);

		// Written past the service and the mapper, which trim a text and stamp a token.
		$qb = $this->db->getQueryBuilder();
		$qb->update('fleet_trips')
			->set('partner', $qb->createNamedParameter($partner))
			->set('updated_at', $qb->createNamedParameter(self::OLD_TOKEN, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($voided ? self::OLD_TOKEN : null, $voided ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$trip->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return $trip;
	}

	/** As the owner's rename does: the record takes the new name, the trips keep their text. */
	private function rename(int $partnerId, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('fleet_partners')
			->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($partnerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @return array<string, mixed> */
	private function row(Trip $trip): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('partner', 'partner_id', 'updated_at')
			->from('fleet_trips')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$trip->getId(), IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertIsArray($row);

		return $row;
	}

	private function link(Trip $trip): ?int {
		$id = $this->row($trip)['partner_id'];

		return $id === null ? null : (int)$id;
	}

	private function text(Trip $trip): ?string {
		$text = $this->row($trip)['partner'];

		return $text === null ? null : (string)$text;
	}

	/**
	 * Each trip's text and token as stored.
	 *
	 * @param list<Trip> $trips
	 * @return list<array{?string, int}>
	 */
	private function stored(array $trips): array {
		return array_map(fn (Trip $trip): array => [$this->text($trip), (int)$this->row($trip)['updated_at']], $trips);
	}

	/**
	 * The owner's records in full, as a second run must leave them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uuid', 'name', 'created_by', 'created_at', 'updated_at', 'deleted_at')
			->from('fleet_partners')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/** @return list<string> the owner's record names, sorted */
	private function names(string $owner): array {
		$names = array_map(static fn (array $row): string => (string)$row['name'], $this->records($owner));
		sort($names);

		return $names;
	}
}
