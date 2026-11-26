<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\PartnerLinks;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A trip's partner text links its record in the vehicle owner's list, which a text no record has
 * yet adds to (ADR 0013). The text stays as typed.
 */
class PartnerLinkTest extends TestCase {
	private const OWNER = 'nextfleet-test-plink-owner';

	private TripService $trips;

	protected function setUp(): void {
		$this->trips = Server::get(TripService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		foreach (['fleet_trips' => 'created_by', 'fleet_audit' => 'created_by', 'fleet_odo_readings' => 'created_by', 'fleet_partners' => 'user_id', 'fleet_vehicles' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	public function testANewTripLinksARecordOfItsNameInTheOwnersList(): void {
		$trip = $this->record($this->vehicle('NF-PL 1'), 1750000000, " Mu\u{0308}ller ");

		$partner = $this->partnerOf($trip);
		$this->assertSame([self::OWNER, "M\u{00FC}ller"], [$partner->getUserId(), $partner->getName()]);
		$this->assertSame("Mu\u{0308}ller", $this->stored($trip)->getPartner());
	}

	/** The answer names the record by its uuid, on the write and on every read after it. */
	public function testTheTripAnswersWithTheUuidOfItsRecord(): void {
		$trip = $this->record($this->vehicle('NF-PL 1'), 1750000000, 'Müller');

		$uuid = $this->partnerOf($trip)->getUuid();
		$this->assertSame($uuid, $trip->jsonSerialize()['partner_uuid']);
		$this->assertSame($uuid, $this->stored($trip)->jsonSerialize()['partner_uuid']);
		$this->assertSame([$uuid], array_map(
			static fn (Trip $row): ?string => $row->jsonSerialize()['partner_uuid'],
			Server::get(TripMapper::class)->findAllForVehicle($trip->getVehicleId()),
		));
	}

	/** One list for all the owner's vehicles. */
	public function testTheNameOnAnotherVehicleOfTheOwnerLinksTheSameRecord(): void {
		$first = $this->record($this->vehicle('NF-PL 1'), 1750000000, 'Müller');
		$second = $this->record($this->vehicle('NF-PL 2'), 1750000000, 'Müller');

		$this->assertSame($this->stored($first)->getPartnerId(), $this->stored($second)->getPartnerId());
	}

	/** A spelling the record no longer has still finds it, through a live trip that links it. */
	public function testAnOldSpellingFindsItsRenamedRecordThroughAnotherTrip(): void {
		$first = $this->record($this->vehicle('NF-PL 1'), 1750000000, 'Mueller GmbH');
		$record = $this->partnerOf($first);
		$this->rename($record, 'Müller GmbH');

		$second = $this->record($this->vehicle('NF-PL 2'), 1750000000, 'Mueller GmbH');

		$this->assertSame($record->getId(), $this->stored($second)->getPartnerId());
		$this->assertSame('Mueller GmbH', $this->stored($second)->getPartner());
	}

	/** A voided trip is no evidence of what its text stands for. */
	public function testAVoidedTripLeadsToNoRecord(): void {
		$vehicle = $this->vehicle('NF-PL 1');
		$first = $this->record($vehicle, 1750000000, 'Mueller GmbH');
		$record = $this->partnerOf($first);
		$this->rename($record, 'Müller GmbH');
		$this->trips->delete(self::OWNER, $vehicle->getUuid(), $first->getUuid(), $first->getUpdatedAt());

		$second = $this->record($vehicle, 1750100000, 'Mueller GmbH');

		$this->assertNotSame($record->getId(), $this->stored($second)->getPartnerId());
		$this->assertSame('Mueller GmbH', $this->partnerOf($second)->getName());
	}

	public function testATripThatNamesNobodyLinksNothing(): void {
		$trip = $this->record($this->vehicle('NF-PL 1'), 1750000000, null);

		$this->assertNull($this->stored($trip)->getPartnerId());
	}

	public function testAnEditOfTheTextLinksTheNewNameAndAnEmptiedOneNothing(): void {
		$vehicle = $this->vehicle('NF-PL 1');
		$trip = $this->record($vehicle, 1750000000, 'Müller');

		$renamed = $this->edit($vehicle, $trip, ['partner' => 'Weber']);
		$this->assertSame('Weber', $this->partnerOf($renamed)->getName());

		$emptied = $this->edit($vehicle, $renamed, ['partner' => '']);
		$this->assertNull($this->stored($emptied)->getPartnerId());
	}

	/** The link of a text nobody changed stays where it is, wherever that is. */
	public function testAnEditThatKeepsTheTextKeepsTheLink(): void {
		$vehicle = $this->vehicle('NF-PL 1');
		$trip = $this->record($vehicle, 1750000000, 'Müller');
		$elsewhere = (int)$this->record($vehicle, 1750100000, 'Weber')->getPartnerId();
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_trips')
			->set('partner_id', $qb->createNamedParameter($elsewhere, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$trip->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$edited = $this->edit($vehicle, $this->stored($trip), ['purpose' => 'Kundentermin']);

		$this->assertSame($elsewhere, $this->stored($edited)->getPartnerId());
	}

	/**
	 * Two drivers who type one new name at once, on two of the owner's vehicles, make one record.
	 * The other driver's create commits while this one waits for the owner's hold.
	 */
	public function testTwoCreatesOfOneNewNameAtOnceMakeOneRecord(): void {
		// The owner's hold is their first vehicle's; the trip is on the other.
		$this->vehicle('NF-PL 1');
		$second = $this->vehicle('NF-PL 2');
		$other = new Partner();
		$other->setUserId(self::OWNER);
		$other->setName('Acme GmbH');
		$other->setCreatedBy(self::OWNER);
		$trips = $this->tripsHolding(static function () use ($other): void {
			Server::get(PartnerMapper::class)->insert($other);
		});

		$trip = $trips->record(self::OWNER, $second->getUuid(), $this->fields(1750000000, 'Acme GmbH'));

		$this->assertSame($other->getId(), $this->stored($trip)->getPartnerId());
		$this->assertCount(1, array_filter(
			Server::get(PartnerMapper::class)->findOf(self::OWNER),
			static fn (Partner $partner): bool => $partner->getName() === 'Acme GmbH',
		));
	}

	/** TripService as the container builds it, with `$held` run once as it takes the owner's hold. */
	private function tripsHolding(\Closure $held): TripService {
		$vehicles = new class(Server::get(IDBConnection::class), Server::get(ITimeFactory::class), Server::get(ISecureRandom::class), Server::get(IConfig::class), $held) extends VehicleMapper {
			public function __construct(
				IDBConnection $db,
				ITimeFactory $time,
				ISecureRandom $random,
				IConfig $config,
				private ?\Closure $held,
			) {
				parent::__construct($db, $time, $random, $config);
			}

			public function holdOwner(string $userId): void {
				parent::holdOwner($userId);
				$held = $this->held;
				$this->held = null;
				$held !== null && $held();
			}
		};
		$instead = [VehicleMapper::class => $vehicles, PartnerLinks::class => new PartnerLinks(Server::get(PartnerMapper::class), $vehicles)];
		$args = [];
		foreach ((new \ReflectionMethod(TripService::class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$args[] = $instead[$type] ?? Server::get($type);
		}

		return (new \ReflectionClass(TripService::class))->newInstanceArgs($args);
	}

	/**
	 * A merge or a delete moves a trip's partner link and leaves its cost centre as an edit
	 * committed since the trip was read left it.
	 */
	public function testAPartnerRelinkLeavesTheCostCentreAsAnEditMeanwhileLeftIt(): void {
		$vehicle = $this->vehicle('NF-PL 1');
		$trip = $this->record($vehicle, 1750000000, 'Müller');
		$weber = $this->partnerOf($this->record($vehicle, 1750100000, 'Weber'));
		$read = $this->stored($trip);
		$this->setLink($trip, 'cost_centre_id', 4711);

		$this->trips->relink(self::OWNER, $vehicle, $read, $weber);

		$this->assertSame([$weber->getId(), 4711], [$this->stored($trip)->getPartnerId(), $this->stored($trip)->getCostCentreId()]);
	}

	/** A transfer moves a trip's cost centre link, and its partner link stays as it stands. */
	public function testACostCentreRelinkLeavesThePartnerAsAnEditMeanwhileLeftIt(): void {
		$vehicle = $this->vehicle('NF-PL 1');
		$trip = $this->record($vehicle, 1750000000, 'Müller');
		$weber = (int)$this->record($vehicle, 1750100000, 'Weber')->getPartnerId();
		$read = $this->stored($trip);
		$this->setLink($trip, 'partner_id', $weber);
		$centre = new CostCentre();
		$centre->setId(4712);

		$this->trips->relinkCostCentre(self::OWNER, $vehicle, $read, $centre);

		$this->assertSame([$weber, 4712], [$this->stored($trip)->getPartnerId(), $this->stored($trip)->getCostCentreId()]);
	}

	/** Another write's link, committed after the trip was read. */
	private function setLink(Trip $trip, string $column, int $id): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_trips')
			->set($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$trip->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function vehicle(string $plate): Vehicle {
		return Server::get(VehicleService::class)->create(self::OWNER, ['plate' => $plate]);
	}

	private function record(Vehicle $vehicle, int $startedAt, ?string $partner): Trip {
		return $this->trips->record(self::OWNER, $vehicle->getUuid(), $this->fields($startedAt, $partner));
	}

	/** @return array<string, mixed> */
	private function fields(int $startedAt, ?string $partner): array {
		return [
			'started_at' => $startedAt,
			'started_at_off' => 0,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 0,
			// Later trips read a higher counter, as the chain wants.
			'end_odo' => intdiv($startedAt - 1700000000, 1000),
			'category' => Trip::BUSINESS,
			'partner' => $partner,
		];
	}

	/**
	 * An edit replaces the whole trip, so it sends the row with the fields changed.
	 *
	 * @param array<string, mixed> $changed
	 */
	private function edit(Vehicle $vehicle, Trip $trip, array $changed): Trip {
		return $this->trips->update(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $trip->getUpdatedAt(), $changed + $trip->jsonSerialize());
	}

	/** What the owner's rename does to the record, and only to it. */
	private function rename(Partner $record, string $name): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_partners')
			->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$record->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function stored(Trip $trip): Trip {
		return Server::get(TripMapper::class)->findAnyByUuid($trip->getUuid());
	}

	private function partnerOf(Trip $trip): Partner {
		$id = $this->stored($trip)->getPartnerId();
		$this->assertNotNull($id);

		return Server::get(PartnerMapper::class)->findAnyById($id);
	}
}
