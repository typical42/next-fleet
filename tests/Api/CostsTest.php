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
 * An owner who keeps a vehicle's costs from its driver, then shares them, as the driver's client
 * sees it over OCS (docs/api.md#costs). Each step leans on the one before.
 */
class CostsTest extends TestCase {
	use Syncing;

	private const PREFIX = 'nextfleet-costs-';

	private static Server $server;
	private static Account $owner;
	private static Account $driver;
	/** @var array<string, mixed> */
	private static array $vehicle;
	private static string $expense;
	/** The owner's receipt on their own fill-up. */
	private static string $receipt;
	private static string $cursor;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$driver = self::$server->account(self::PREFIX . 'driver-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	public function testTheOwnerKeepsTheCostsFromTheDriver(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', [
			'plate' => 'K 1', 'currency' => 'EUR', 'purchase_price' => 3200000, 'costs_shared' => false,
			'jurisdiction' => 'de', 'odo_unit' => 'km', 'energy_types' => ['diesel'],
		]);
		$this->assertSame(201, $created->status, $created->body);
		self::$vehicle = $created->data();
		$this->assertFalse(self::$vehicle['costs_shared']);
		$this->assertContains('costs', self::$vehicle['may'], 'the owner always sees the costs');

		$uuid = self::$vehicle['uuid'];
		$expense = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/expenses", ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 1600]);
		$this->assertSame(201, $expense->status, $expense->body);
		self::$expense = $expense->data()['uuid'];
		$fill = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/energy", [
			'filled_at' => 1750003600, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'unit_price' => 1750, 'total' => 7350, 'full_tank' => true,
		]);
		$this->assertSame(201, $fill->status, $fill->body);
		$fileId = self::$server->upload(self::$owner, 'Tankbeleg.txt', 'Diesel 73,50 EUR');
		$receipt = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/documents", [
			'file_id' => $fileId, 'kind' => 'receipt', 'linked_type' => 'energy', 'linked_uuid' => $fill->data()['uuid'],
		]);
		$this->assertSame(200, $receipt->status, $receipt->body);
		self::$receipt = $receipt->data()[0]['uuid'];
		$granted = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/grants", ['grantee' => self::$driver->uid, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(200, $granted->status, $granted->body);
	}

	#[Depends('testTheOwnerKeepsTheCostsFromTheDriver')]
	public function testTheDriverReadsNoCost(): void {
		$uuid = self::$vehicle['uuid'];

		$vehicle = self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid")->data();
		$this->assertNull($vehicle['purchase_price']);
		$this->assertNotContains('costs', $vehicle['may']);

		// The fill-up is there, without what it cost; the expense is not.
		$timeline = self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid/timeline")->data();
		$this->assertSame(['energy'], array_column($timeline['rows'], 'type'));
		$this->assertSame([42000, null, null], [$timeline['rows'][0]['energy']['amount'], $timeline['rows'][0]['energy']['unit_price'], $timeline['rows'][0]['energy']['total']]);
		$this->assertSame(404, self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid/timeline/expense/" . self::$expense)->status);

		$kpis = self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid/kpis", ['from' => 1749900000, 'to' => 1750100000]);
		$this->assertSame(200, $kpis->status, $kpis->body);
		$this->assertNull($kpis->data()['cost']['total']);
		$year = self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid/costs/2025", ['tz' => 'Europe/Berlin']);
		$this->assertSame(200, $year->status, $year->body);
		$this->assertNull($year->data()['year']['cost']['total']);
		$range = ['vehicle' => $uuid, 'from' => '2025-06-01', 'to' => '2025-07-01', 'tz' => 'Europe/Berlin'];
		$statistics = self::$server->ocs(self::$driver, 'GET', '/statistics', $range);
		$this->assertSame(200, $statistics->status, $statistics->body);
		$this->assertSame([], $statistics->data()['costs']);
		$this->assertSame([], $statistics->data()['per_distance']);
		$this->assertSame([['uuid' => $uuid, 'costs' => false, 'drivers' => false]], $statistics->data()['vehicles']);
		$owners = self::$server->ocs(self::$owner, 'GET', '/statistics', $range)->data();
		$this->assertSame(8950, $owners['costs'][0]['range']['total']);
		$this->assertSame(['total' => 8950, 'distance' => null, 'value' => null], $owners['per_distance'][0]['range']);
		$this->assertSame(403, self::$server->request('GET', "/index.php/apps/nextfleet/vehicles/$uuid/mileage/2025", self::$driver)->status);

		// The receipt shows the price, so it goes with it.
		$this->assertSame([], self::$server->ocs(self::$driver, 'GET', "/vehicles/$uuid/documents")->data());
		$this->assertSame(404, self::$server->request('GET', "/index.php/apps/nextfleet/vehicles/$uuid/documents/" . self::$receipt, self::$driver)->status);

		$sync = $this->sync(self::$driver);
		self::$cursor = $sync['cursor'];
		$this->assertSame([], $sync['changes']['expenses']);
		$this->assertNull($sync['changes']['energy'][0]['row']['total']);
		$this->assertSame([], $sync['changes']['documents']);
	}

	/** Shared again, every client starts over and gets what it may now read. */
	#[Depends('testTheDriverReadsNoCost')]
	public function testSharingTheCostsAgainStartsTheDriversSyncOver(): void {
		$uuid = self::$vehicle['uuid'];
		$token = self::$server->ocs(self::$owner, 'GET', "/vehicles/$uuid")->data()['updated_at'];
		$shared = self::$server->ocs(self::$owner, 'PUT', "/vehicles/$uuid", ['updated_at' => $token, 'costs_shared' => true]);
		$this->assertSame(200, $shared->status, $shared->body);

		$sync = $this->sync(self::$driver, self::$cursor);

		$this->assertTrue($sync['reset']);
		$this->assertSame(1600, $this->synced($sync, 'expenses', self::$expense)['row']['amount']);
		$this->assertSame('receipt', $this->synced($sync, 'documents', self::$receipt)['row']['kind']);
		$this->assertSame(3200000, $sync['vehicles'][0]['purchase_price']);
	}
}
