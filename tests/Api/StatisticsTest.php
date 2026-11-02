<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Who drove how far, as each one's client reads it over OCS (docs/api.md#statistics): the owner
 * every driver, a driver only themselves.
 */
class StatisticsTest extends TestCase {
	private const PREFIX = 'nextfleet-stats-';

	private static Server $server;
	private static Account $owner;
	private static Account $driver;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$driver = self::$server->account(self::PREFIX . 'driver-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	public function testTheOwnerReadsEveryDriverAndTheDriverOnlyThemselves(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', ['plate' => 'K 2', 'jurisdiction' => 'de', 'odo_unit' => 'km', 'energy_types' => ['diesel']]);
		$this->assertSame(201, $created->status, $created->body);
		$uuid = $created->data()['uuid'];
		$granted = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/grants", ['grantee' => self::$driver->uid, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(200, $granted->status, $granted->body);
		$this->trip(self::$owner, $uuid, 1750000000, 1000, 1080, 'private');
		$this->trip(self::$driver, $uuid, 1750010000, 1080, 1120, 'commute');
		$range = ['vehicle' => $uuid, 'from' => '2025-06-01', 'to' => '2025-07-01', 'tz' => 'Europe/Berlin'];

		$owners = self::$server->ocs(self::$owner, 'GET', '/statistics', $range);
		$this->assertSame(200, $owners->status, $owners->body);
		$this->assertSame([['per' => 'km', 'buckets' => [['trips' => 2, 'business' => 0, 'private' => 80, 'commute' => 40]], 'range' => ['trips' => 2, 'business' => 0, 'private' => 80, 'commute' => 40]]], $owners->data()['distance']);
		$this->assertEqualsCanonicalizing([self::$owner->uid, self::$driver->uid], array_column($owners->data()['by_driver'][0]['drivers'], 'user_id'));
		$this->assertTrue($owners->data()['vehicles'][0]['drivers']);

		$drivers = self::$server->ocs(self::$driver, 'GET', '/statistics', $range)->data();
		$this->assertSame(2, $drivers['distance'][0]['range']['trips'], 'the logbook is every role\'s');
		$this->assertSame([self::$driver->uid], array_column($drivers['by_driver'][0]['drivers'], 'user_id'));
		$this->assertSame(['trips' => 1, 'distance' => 40, 'business' => 0], array_intersect_key($drivers['by_driver'][0]['drivers'][0], ['trips' => 0, 'distance' => 0, 'business' => 0]));
		$this->assertFalse($drivers['vehicles'][0]['drivers']);
	}

	private function trip(Account $account, string $vehicle, int $at, int $start, int $end, string $category): void {
		$trip = self::$server->ocs($account, 'POST', "/vehicles/$vehicle/trips", [
			'started_at' => $at, 'started_at_off' => 120, 'ended_at' => $at + 3600, 'ended_at_off' => 120,
			'start_odo' => $start, 'end_odo' => $end, 'category' => $category,
		]);
		$this->assertSame(201, $trip->status, $trip->body);
	}
}
