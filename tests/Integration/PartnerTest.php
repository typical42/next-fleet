<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\CheckService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\PartnerService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The owner keeps their partner list: reads it, renames, merges and deletes records. A trip keeps
 * its text through all of it (ADR 0013).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class PartnerTest extends TestCase {
	/** Not an account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-partner-owner';
	/** Accounts, since a grantee has to exist on the instance. */
	private const DRIVER = 'nextfleet-test-partner-driver';
	private const MANAGER = 'nextfleet-test-partner-manager';
	private const VIEWER = 'nextfleet-test-partner-viewer';
	private const STRANGER = 'nextfleet-test-partner-stranger';

	private PartnerService $partners;
	private TripService $trips;

	public static function setUpBeforeClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::DRIVER, self::MANAGER, self::VIEWER, self::STRANGER] as $uid) {
			$users->get($uid)?->delete();
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ([self::DRIVER, self::MANAGER, self::VIEWER, self::STRANGER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->partners = Server::get(PartnerService::class);
		$this->trips = Server::get(TripService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		$tables = [
			'fleet_trips' => 'created_by',
			'fleet_audit' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_access' => 'created_by',
			'fleet_partners' => 'user_id',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter(
				[self::OWNER, self::DRIVER, self::MANAGER, self::VIEWER, self::STRANGER],
				$qb::PARAM_STR_ARRAY,
			)));
			$qb->executeStatement();
		}
	}

	public function testTheOwnerListsTheirPartnersByNameWithAddressAndNote(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->trip($vehicle, 1750000000, 'Weber');
		$mueller = $this->trip($vehicle, 1750100000, 'Müller');
		$this->partners->update(self::OWNER, $this->uuidOf($mueller), $this->tokenOf($mueller), [
			'name' => 'Müller',
			'address' => "Hafenstraße 1\n20457 Hamburg",
			'note' => 'Rechnung an die Zentrale',
		]);

		$list = $this->partners->list(self::OWNER);

		$this->assertSame(['Müller', 'Weber'], array_column($list, 'name'));
		$this->assertSame("Hafenstraße 1\n20457 Hamburg", $list[0]['address']);
		$this->assertSame('Rechnung an die Zentrale', $list[0]['note']);
		$this->assertSame(self::OWNER, $list[0]['user_id']);
	}

	/** Whoever may log on one of the owner's vehicles reads the name; the rest is the owner's. */
	public function testADriverReadsTheNameButNeitherTheAddressNorTheNote(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->grant($vehicle, self::DRIVER, 'driver');
		$trip = $this->trip($vehicle, 1750000000, 'Müller');
		$this->partners->update(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip), ['name' => 'Müller', 'address' => 'Hamburg', 'note' => 'Privat']);

		$read = $this->partners->show(self::DRIVER, $this->uuidOf($trip));

		$this->assertSame(['Müller', null, null], [$read['name'], $read['address'], $read['note']]);
		$this->assertSame('Hamburg', $this->partners->show(self::OWNER, $this->uuidOf($trip))['address']);
	}

	/** A viewer names the cost centre an entry shows, but logs no trip: no partner's name is theirs. */
	public function testAViewerReadsNoPartner(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->grant($vehicle, self::VIEWER, 'viewer');
		$trip = $this->trip($vehicle, 1750000000, 'Müller');

		$this->expectException(AccessDeniedException::class);
		$this->partners->show(self::VIEWER, $this->uuidOf($trip));
	}

	public function testAStrangerReadsNoPartner(): void {
		$trip = $this->trip($this->vehicle('NF-PT 1'), 1750000000, 'Müller');

		$this->expectException(AccessDeniedException::class);
		$this->partners->show(self::STRANGER, $this->uuidOf($trip));
	}

	/** The list is the owner's own; a driver's is their own too, empty here. */
	public function testTheListIsTheCallersOwn(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->grant($vehicle, self::DRIVER, 'driver');
		$this->trip($vehicle, 1750000000, 'Müller');

		$this->assertSame([], $this->partners->list(self::DRIVER));
	}

	public function testARenameChangesTheRecordAndNoTripText(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$trip = $this->trip($vehicle, 1750000000, 'Mueller GmbH');

		$renamed = $this->partners->update(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip), ['name' => ' Müller GmbH ']);

		$this->assertSame('Müller GmbH', $renamed['name']);
		$stored = $this->stored($trip);
		$this->assertSame('Mueller GmbH', $stored->getPartner());
		$this->assertSame($renamed['uuid'], $stored->getPartnerUuid());
		$this->assertSame($trip->getUpdatedAt(), $stored->getUpdatedAt());
	}

	/** Two records of one name is what a merge is for. */
	public function testARenameOntoANameTheListHoldsIsRefused(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->trip($vehicle, 1750000000, 'Müller');
		$variant = $this->trip($vehicle, 1750100000, 'Mueller');

		try {
			$this->partners->update(self::OWNER, $this->uuidOf($variant), $this->tokenOf($variant), ['name' => 'Müller']);
			$this->fail('the rename went through');
		} catch (RefusedException $e) {
			$this->assertSame(PartnerService::NAME_TAKEN, $e->reason);
		}
	}

	public function testARenameAgainstAStaleTokenIsRefused(): void {
		$trip = $this->trip($this->vehicle('NF-PT 1'), 1750000000, 'Müller');
		$token = $this->tokenOf($trip);
		$this->partners->update(self::OWNER, $this->uuidOf($trip), $token, ['name' => 'Müller KG']);

		$this->expectException(StaleUpdateException::class);
		$this->partners->update(self::OWNER, $this->uuidOf($trip), $token, ['name' => 'Müller AG']);
	}

	public function testANameIsRequired(): void {
		$trip = $this->trip($this->vehicle('NF-PT 1'), 1750000000, 'Müller');

		$this->expectException(\InvalidArgumentException::class);
		$this->partners->update(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip), ['name' => '  ', 'address' => 'Hamburg']);
	}

	/** A manager's rights end at their vehicle; the list is the owner's. */
	public function testAManagerChangesNoRecord(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->grant($vehicle, self::MANAGER, 'manager');
		$trip = $this->trip($vehicle, 1750000000, 'Müller');
		$other = $this->trip($vehicle, 1750100000, 'Mueller');
		$writes = [
			fn () => $this->partners->update(self::MANAGER, $this->uuidOf($trip), $this->tokenOf($trip), ['name' => 'Müller KG']),
			fn () => $this->partners->merge(self::MANAGER, $this->uuidOf($other), $this->tokenOf($other), $this->uuidOf($trip)),
			fn () => $this->partners->delete(self::MANAGER, $this->uuidOf($trip), $this->tokenOf($trip)),
		];

		foreach ($writes as $write) {
			try {
				$write();
				$this->fail('a manager changed the owner\'s list');
			} catch (AccessDeniedException) {
			}
		}
		$this->assertSame(['Mueller', 'Müller'], array_column($this->partners->list(self::OWNER), 'name'));
	}

	/** B into A, across the owner's vehicles and a voided trip: the links move, the texts stay. */
	public function testAMergeMovesEveryLinkAndKeepsEveryText(): void {
		$first = $this->vehicle('NF-PT 1');
		$second = $this->vehicle('NF-PT 2');
		$kept = $this->trip($first, 1750000000, 'Müller');
		$variant = $this->trip($first, 1750100000, 'Mueller');
		$elsewhere = $this->trip($second, 1750200000, 'Mueller');
		$voided = $this->trip($second, 1750300000, 'Mueller');
		$this->trips->delete(self::OWNER, $second->getUuid(), $voided->getUuid(), $voided->getUpdatedAt());
		$into = $this->uuidOf($kept);
		$gone = $this->uuidOf($variant);

		$merged = $this->partners->merge(self::OWNER, $gone, $this->tokenOf($variant), $into);

		$this->assertSame($into, $merged['uuid']);
		foreach ([$variant, $elsewhere, $voided] as $trip) {
			$stored = $this->stored($trip);
			$this->assertSame($into, $stored->getPartnerUuid());
			$this->assertSame('Mueller', $stored->getPartner());
		}
		$this->assertSame(['Müller'], array_column($this->partners->list(self::OWNER), 'name'));
		$this->expectException(DoesNotExistException::class);
		$this->partners->show(self::OWNER, $gone);
	}

	/** A spelling merged away finds its record again when a driver types it on a new trip. */
	public function testANewTripWithAMergedSpellingLinksTheRecordItWasMergedInto(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$kept = $this->trip($vehicle, 1750000000, 'Müller');
		$variant = $this->trip($vehicle, 1750100000, 'Mueller');
		$this->partners->merge(self::OWNER, $this->uuidOf($variant), $this->tokenOf($variant), $this->uuidOf($kept));

		$later = $this->trip($vehicle, 1750200000, 'Mueller');

		$this->assertSame($this->uuidOf($kept), $later->getPartnerUuid());
	}

	public function testAMergeIntoItselfOrAnotherListIsRefused(): void {
		$trip = $this->trip($this->vehicle('NF-PT 1'), 1750000000, 'Müller');
		$foreign = Server::get(VehicleService::class)->create(self::STRANGER, ['plate' => 'NF-PT 9']);
		$theirs = $this->trips->record(self::STRANGER, $foreign->getUuid(), ['end_odo' => 50] + self::fields(1750000000, 'Müller'));

		try {
			$this->partners->merge(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip), $this->uuidOf($trip));
			$this->fail('merged into itself');
		} catch (\InvalidArgumentException) {
		}
		// One reason for another list's record and for none, so it tells nobody which uuids exist;
		// the client words it as a target merged or deleted meanwhile.
		foreach ([(string)$theirs->getPartnerUuid(), 'no-such-partner'] as $into) {
			try {
				$this->partners->merge(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip), $into);
				$this->fail('merged into ' . $into);
			} catch (RefusedException $e) {
				$this->assertSame(PartnerService::TARGET_GONE, $e->reason);
			}
		}
		$this->assertSame('Müller', $this->stored($theirs)->getPartner());
		$this->assertSame($this->uuidOf($trip), $this->stored($trip)->getPartnerUuid());
	}

	/** Each moved link leaves an audit row with the trip's new token, as any change to a trip does. */
	public function testAMovedLinkUnderLogbookModeLeavesItsAuditRow(): void {
		$vehicle = $this->vehicle('NF-PT 1', ['jurisdiction' => 'de']);
		$vehicle = Server::get(VehicleService::class)->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['logbook_mode' => true]);
		$kept = $this->trip($vehicle, 1750000000, 'Müller');
		$variant = $this->trip($vehicle, 1750100000, 'Mueller');
		$from = $this->uuidOf($variant);

		$this->partners->merge(self::OWNER, $from, $this->tokenOf($variant), $this->uuidOf($kept));

		$stored = $this->stored($variant);
		$this->assertGreaterThan($variant->getUpdatedAt(), $stored->getUpdatedAt());
		$last = $this->lastAudit($variant);
		$this->assertSame(self::OWNER, $last->getCreatedBy());
		$this->assertSame(['partner_uuid' => [$from, $this->uuidOf($kept)]], $last->getDiffJson()['fields']);
		// Long past the lock delay, and still no late change: no logbook prints a link.
		$this->assertFalse($last->getDiffJson()['late']);
		$this->assertSame($stored->getUpdatedAt(), $last->getDiffJson()['updated_at']);
	}

	public function testALinkADeleteTookUnderLogbookModeLeavesItsAuditRow(): void {
		$vehicle = $this->vehicle('NF-PT 1', ['jurisdiction' => 'de']);
		$vehicle = Server::get(VehicleService::class)->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['logbook_mode' => true]);
		$trip = $this->trip($vehicle, 1750000000, 'Erika Mustermann');
		$uuid = $this->uuidOf($trip);

		$this->partners->delete(self::OWNER, $uuid, $this->tokenOf($trip));

		$last = $this->lastAudit($trip);
		$this->assertSame(['partner_uuid' => [$uuid, null]], $last->getDiffJson()['fields']);
		$this->assertSame($this->stored($trip)->getUpdatedAt(), $last->getDiffJson()['updated_at']);
	}

	/**
	 * A delete is an erasure: the name, the address and the note go, and no trip names the record
	 * any more. The texts stay, as the logbook is kept for ten years.
	 */
	public function testADeleteErasesTheRecordAndKeepsEveryText(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$trip = $this->trip($vehicle, 1750000000, 'Erika Mustermann');
		$voided = $this->trip($vehicle, 1750100000, 'Erika Mustermann');
		$this->trips->delete(self::OWNER, $vehicle->getUuid(), $voided->getUuid(), $voided->getUpdatedAt());
		$uuid = $this->uuidOf($trip);
		$token = $this->partners->update(self::OWNER, $uuid, $this->tokenOf($trip), ['name' => 'Erika Mustermann', 'address' => 'Musterweg 1', 'note' => 'Tante'])['updated_at'];
		$tokens = [];
		foreach ([$trip, $voided] as $one) {
			$tokens[$one->getUuid()] = $this->stored($one)->getUpdatedAt();
		}

		$this->partners->delete(self::OWNER, $uuid, $token);

		foreach ([$trip, $voided] as $one) {
			$stored = $this->stored($one);
			$this->assertNull($stored->getPartnerUuid());
			$this->assertSame('Erika Mustermann', $stored->getPartner());
			// A changed answer, so a sync hands the trip over again.
			$this->assertGreaterThan($tokens[$one->getUuid()], $stored->getUpdatedAt());
		}
		$row = $this->record($uuid);
		$this->assertNotNull($row->getDeletedAt());
		$this->assertStringNotContainsString('Erika', $row->getName());
		$this->assertNull($row->getAddress());
		$this->assertNull($row->getNote());
		$this->assertSame([], $this->partners->list(self::OWNER));
	}

	/** The trips keep a mark of what was deleted, so the check and the backfill leave them be. */
	public function testADeletedRecordLeavesNoFindingAndAnEditDoesNotBringItBack(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$trip = $this->trip($vehicle, 1750000000, 'Erika Mustermann');
		$this->partners->delete(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip));

		$edited = $this->trips->update(self::OWNER, $vehicle->getUuid(), $trip->getUuid(), $this->stored($trip)->getUpdatedAt(), ['purpose' => 'Besuch'] + $this->stored($trip)->jsonSerialize());

		$this->assertNull($edited->getPartnerUuid());
		$this->assertSame([], $this->partners->list(self::OWNER));
		$check = Server::get(CheckService::class);
		$this->assertSame([], array_values(array_filter(
			$check->run($check->vehicle($vehicle->getUuid()))['findings'],
			static fn (array $finding): bool => $finding['check'] === 'partner',
		)));
	}

	/** Somebody who types the name again on a new trip makes a new record, as for any new name. */
	public function testANewTripWithTheNameOfADeletedRecordMakesANewOne(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$trip = $this->trip($vehicle, 1750000000, 'Erika Mustermann');
		$deleted = $this->uuidOf($trip);
		$this->partners->delete(self::OWNER, $deleted, $this->tokenOf($trip));

		$later = $this->trip($vehicle, 1750100000, 'Erika Mustermann');

		$this->assertNotNull($later->getPartnerUuid());
		$this->assertNotSame($deleted, $later->getPartnerUuid());
		$this->assertSame(['Erika Mustermann'], array_column($this->partners->list(self::OWNER), 'name'));
	}

	public function testADeleteAgainstAStaleTokenIsRefused(): void {
		$trip = $this->trip($this->vehicle('NF-PT 1'), 1750000000, 'Müller');

		$this->expectException(StaleUpdateException::class);
		$this->partners->delete(self::OWNER, $this->uuidOf($trip), $this->tokenOf($trip) - 1);
	}

	/**
	 * Live trips on any of the owner's vehicles, set off in the period by their own clock, with the
	 * distance in each unit. A voided trip was not driven.
	 */
	public function testAPartnersTripsOverAPeriodAcrossTheOwnersVehicles(): void {
		$car = $this->vehicle('NF-PT 1');
		$van = $this->vehicle('NF-PT 2');
		$march = (int)gmmktime(9, 0, 0, 3, 2, 2026);
		$first = $this->trip($car, $march, 'Müller', ['start_odo' => 1000, 'end_odo' => 1120]);
		$second = $this->trip($van, $march + 86400, 'Mueller', ['start_odo' => 10, 'end_odo' => 50]);
		$this->partners->merge(self::OWNER, $this->uuidOf($second), $this->tokenOf($second), $this->uuidOf($first));
		$voided = $this->trip($car, $march + 2 * 86400, 'Müller', ['start_odo' => 1120, 'end_odo' => 1200]);
		$this->trips->delete(self::OWNER, $car->getUuid(), $voided->getUuid(), $voided->getUpdatedAt());
		// The 31st at 23:30 UTC is 1 April at 01:30 where it was driven.
		$this->trip($car, (int)gmmktime(23, 30, 0, 3, 31, 2026), 'Müller', ['start_odo' => 1200, 'end_odo' => 1250], 120);
		$this->trip($car, (int)gmmktime(9, 0, 0, 4, 2, 2026), 'Weber', ['start_odo' => 1250, 'end_odo' => 1300]);

		$answer = $this->partners->trips(self::OWNER, $this->uuidOf($first), ['from' => '2026-03-01', 'to' => '2026-04-01']);

		$this->assertSame('Müller', $answer['partner']['name']);
		$this->assertSame([$first->getUuid(), $second->getUuid()], array_column(array_column($answer['trips'], 'trip'), 'uuid'));
		$this->assertSame([$car->getUuid(), $van->getUuid()], array_column($answer['trips'], 'vehicle_uuid'));
		// Each row's own, as the sum and the CSV count it.
		$this->assertSame([120, 40], array_column($answer['trips'], 'distance'));
		$this->assertSame([['per' => 'km', 'trips' => 2, 'distance' => 160]], $answer['distance']);
	}

	public function testAPartnersTripsAreTheOwnersToRead(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$this->grant($vehicle, self::MANAGER, 'manager');
		$trip = $this->trip($vehicle, 1750000000, 'Müller');

		$this->expectException(AccessDeniedException::class);
		$this->partners->trips(self::MANAGER, $this->uuidOf($trip), []);
	}

	public function testTheCsvOfAPartnersTripsHoldsOneLinePerTrip(): void {
		$vehicle = $this->vehicle('NF-PT 1');
		$march = (int)gmmktime(9, 0, 0, 3, 2, 2026);
		$trip = $this->trip($vehicle, $march, 'Müller', ['start_odo' => 1000, 'end_odo' => 1120, 'purpose' => 'Abnahme', 'from_label' => 'Kiel', 'to_label' => 'Hamburg']);

		$file = $this->partners->csv(self::OWNER, $this->uuidOf($trip), ['from' => '2026-03-01', 'to' => '2026-04-01']);

		$lines = explode("\r\n", trim($file['body']));
		$this->assertSame("\u{FEFF}vehicle_uuid,plate,trip_uuid,started,started_offset_min,ended,ended_offset_min,from,to,purpose,partner,category,distance,odo_unit", $lines[0]);
		$this->assertSame(
			$vehicle->getUuid() . ',NF-PT 1,' . $trip->getUuid() . ',2026-03-02 10:00,60,2026-03-02 11:00,60,Kiel,Hamburg,Abnahme,Müller,business,120,km',
			$lines[1],
		);
		$this->assertCount(2, $lines);
		$this->assertStringEndsWith('.csv', $file['name']);
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(string $plate, array $fields = []): Vehicle {
		return Server::get(VehicleService::class)->create(self::OWNER, ['plate' => $plate] + $fields);
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => 'user', 'role' => $role]);
	}

	/** @param array<string, mixed> $more */
	private function trip(Vehicle $vehicle, int $startedAt, ?string $partner, array $more = [], int $off = 60): Trip {
		// Each later trip reads a higher counter, as the chain wants.
		$more += ['end_odo' => intdiv($startedAt - 1700000000, 1000)];

		return $this->trips->record(self::OWNER, $vehicle->getUuid(), $more + self::fields($startedAt, $partner, $off));
	}

	/** @return array<string, mixed> */
	private static function fields(int $startedAt, ?string $partner, int $off = 60): array {
		return [
			'started_at' => $startedAt,
			'started_at_off' => $off,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => $off,
			'category' => Trip::BUSINESS,
			'partner' => $partner,
		];
	}

	private function stored(Trip $trip): Trip {
		return Server::get(TripMapper::class)->findAnyByUuid($trip->getUuid());
	}

	/** The uuid of the record the trip links now. */
	private function uuidOf(Trip $trip): string {
		$uuid = $this->stored($trip)->getPartnerUuid();
		$this->assertNotNull($uuid);

		return $uuid;
	}

	/** The token of that record now. */
	private function tokenOf(Trip $trip): int {
		return $this->record($this->uuidOf($trip))->getUpdatedAt();
	}

	private function record(string $uuid): Partner {
		return Server::get(PartnerMapper::class)->findAnyByUuid($uuid);
	}

	private function lastAudit(Trip $trip): Audit {
		$rows = Server::get(AuditMapper::class)->findForEntity(Audit::TRIP, (int)$trip->getId());
		$last = end($rows);
		$this->assertInstanceOf(Audit::class, $last);

		return $last;
	}
}
