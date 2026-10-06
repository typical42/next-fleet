<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * An owner and a driver, each a client of their own, kept in step by sync alone. Each step leans
 * on the one before, as the two clients would.
 *
 * Every step runs inside sync's settle window, so a sync carries every row of the run whatever
 * its token says; what proves a write reached the client is the state and token the item carries.
 */
class SliceTest extends TestCase {
	use Syncing;

	private const PREFIX = 'nextfleet-m8-';

	private static Server $server;
	private static Account $owner;
	private static Account $driver;
	/** @var array<string, mixed> */
	private static array $vehicle;
	/** @var array<string, mixed> */
	private static array $trip;
	/** @var array<string, mixed> */
	private static array $booking;
	/** The cursor each client's last sync handed out, by uid. @var array<string, string> */
	private static array $cursors = [];

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$driver = self::$server->account(self::PREFIX . 'driver-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	/** @return array<string, mixed> the sync from where the client's last one ended */
	private function next(Account $as): array {
		$sync = $this->sync($as, self::$cursors[$as->uid] ?? null);
		self::$cursors[$as->uid] = $sync['cursor'];

		return $sync;
	}

	public function testTheOwnerGrantsADriver(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', ['plate' => 'M8 1']);
		$this->assertSame(201, $created->status, $created->body);
		self::$vehicle = $created->data();
		$this->assertContains(self::$vehicle['uuid'], array_column($this->next(self::$owner)['vehicles'], 'uuid'));

		$granted = self::$server->ocs(self::$owner, 'POST', '/vehicles/' . self::$vehicle['uuid'] . '/grants', [
			'grantee' => self::$driver->uid, 'grantee_type' => 'user', 'role' => 'driver',
		]);

		$this->assertSame(200, $granted->status, $granted->body);
		$this->assertSame([['grantee' => self::$driver->uid, 'role' => 'driver']], array_map(
			static fn (array $grant): array => ['grantee' => $grant['grantee'], 'role' => $grant['role']],
			$granted->data(),
		));
	}

	#[Depends('testTheOwnerGrantsADriver')]
	public function testTheDriversFirstSyncListsTheVehicle(): void {
		$sync = $this->next(self::$driver);

		$this->assertSame([self::$vehicle['uuid']], array_column($sync['vehicles'], 'uuid'));
		$this->assertContains('book', $sync['vehicles'][0]['may']);
		$this->assertNotContains('own', $sync['vehicles'][0]['may']);
		$this->assertSame([], $sync['changes']['grants'], 'a grant is its owner\'s to see');
	}

	#[Depends('testTheDriversFirstSyncListsTheVehicle')]
	public function testTheDriverLogsATripAndBooksTheCar(): void {
		$vehicle = self::$vehicle['uuid'];
		$trip = self::$server->ocs(self::$driver, 'POST', "/vehicles/$vehicle/trips", [
			'started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120,
			'end_odo' => 1000, 'category' => 'business',
		]);
		$this->assertSame(201, $trip->status, $trip->body);
		self::$trip = $trip->data();

		$booking = self::$server->ocs(self::$driver, 'POST', "/vehicles/$vehicle/bookings", [
			'starts_at' => time() + 3600, 'starts_at_off' => 120, 'ends_at' => time() + 7200, 'ends_at_off' => 120,
			'purpose' => 'Kundentermin',
		]);

		$this->assertSame(201, $booking->status, $booking->body);
		self::$booking = $booking->data();
	}

	#[Depends('testTheDriverLogsATripAndBooksTheCar')]
	public function testTheOwnersSyncCarriesBoth(): void {
		$sync = $this->next(self::$owner);

		$trip = $this->synced($sync, 'trips', self::$trip['uuid']);
		$this->assertSame(self::$trip['updated_at'], $trip['updated_at']);
		$this->assertSame(1000, $trip['row']['end_odo']);
		// The trip's Reading is tied to it by uuid, so a client can hang the two on one row.
		$this->assertSame([self::$trip['uuid']], array_column(array_column($sync['changes']['readings'], 'row'), 'source_uuid'));
		$booking = $this->synced($sync, 'bookings', self::$booking['uuid']);
		$this->assertSame(self::$booking['updated_at'], $booking['updated_at']);
		$this->assertSame(self::$driver->uid, $booking['row']['user_id']);
		$this->assertSame(1000, $sync['vehicles'][0]['odo_value']);
	}

	#[Depends('testTheOwnersSyncCarriesBoth')]
	public function testTheDriversSyncCarriesTheOwnersRestore(): void {
		$path = '/vehicles/' . self::$vehicle['uuid'] . '/trips/' . self::$trip['uuid'];
		$deleted = self::$server->ocs(self::$owner, 'DELETE', $path, ['updated_at' => self::$trip['updated_at']]);
		$this->assertSame(200, $deleted->status, $deleted->body);
		$restored = self::$server->ocs(self::$owner, 'POST', "$path/restore", ['updated_at' => $deleted->data()['updated_at']]);
		$this->assertSame(200, $restored->status, $restored->body);

		$trip = $this->synced($this->next(self::$driver), 'trips', self::$trip['uuid']);

		$this->assertNull($trip['deleted_at']);
		$this->assertSame($restored->data()['updated_at'], $trip['updated_at']);
		$this->assertGreaterThan(self::$trip['updated_at'], $trip['updated_at']);
	}

	#[Depends('testTheDriversSyncCarriesTheOwnersRestore')]
	public function testARevokeListsTheVehicleUnreachable(): void {
		$grant = self::$server->ocs(self::$owner, 'GET', '/vehicles/' . self::$vehicle['uuid'] . '/grants')->data()[0]['uuid'];
		$revoked = self::$server->ocs(self::$owner, 'DELETE', '/vehicles/' . self::$vehicle['uuid'] . "/grants/$grant");
		$this->assertSame(200, $revoked->status, $revoked->body);

		$sync = $this->next(self::$driver);

		$this->assertSame([self::$vehicle['uuid']], $sync['unreachable']);
		$this->assertSame([], $sync['vehicles']);
	}
}
