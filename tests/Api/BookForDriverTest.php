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
 * The owner books a vehicle for its driver, and the driver confirms it from the notice, over OCS
 * (docs/api.md#booking-for-a-driver). Each step leans on the one before.
 */
class BookForDriverTest extends TestCase {
	private const PREFIX = 'nextfleet-for-';

	private static Server $server;
	private static Account $owner;
	private static Account $driver;
	private static string $vehicle;
	/** @var array<string, mixed> */
	private static array $booking;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$driver = self::$server->account(self::PREFIX . 'driver-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	/** Booked by the owner, the booking is the driver's and stands at once. */
	public function testTheOwnerBooksForTheDriver(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', ['plate' => 'K 4', 'jurisdiction' => 'de', 'odo_unit' => 'km', 'booking_approval_rule' => 'always']);
		$this->assertSame(201, $created->status, $created->body);
		self::$vehicle = $created->data()['uuid'];
		$granted = self::$server->ocs(self::$owner, 'POST', '/vehicles/' . self::$vehicle . '/grants', ['grantee' => self::$driver->uid, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(200, $granted->status, $granted->body);

		$booked = self::$server->ocs(self::$owner, 'POST', '/vehicles/' . self::$vehicle . '/bookings', $this->span() + ['user_id' => self::$driver->uid]);

		$this->assertSame(201, $booked->status, $booked->body);
		$data = $booked->data();
		$this->assertSame([self::$driver->uid, self::$owner->uid, 'approved', null], [$data['user_id'], $data['created_by'], $data['approval'], $data['seen_at']]);
		$this->assertIsString($data['created_by_name']);
		self::$booking = $data;
	}

	/** The owner reads whom they may book for: themselves and the driver. */
	#[Depends('testTheOwnerBooksForTheDriver')]
	public function testTheOwnerReadsWhomToBookFor(): void {
		$read = self::$server->ocs(self::$owner, 'GET', '/vehicles/' . self::$vehicle . '/bookers');

		$this->assertSame(200, $read->status, $read->body);
		$uids = array_column($read->data(), 'user_id');
		sort($uids);
		$this->assertSame([self::$driver->uid, self::$owner->uid], $uids);
	}

	/** The driver books for nobody else, and reads nobody to book for. */
	#[Depends('testTheOwnerReadsWhomToBookFor')]
	public function testTheDriverBooksForNobodyElse(): void {
		$span = $this->span(2) + ['user_id' => self::$owner->uid];

		$this->assertSame(403, self::$server->ocs(self::$driver, 'POST', '/vehicles/' . self::$vehicle . '/bookings', $span)->status);
		$this->assertSame(403, self::$server->ocs(self::$driver, 'GET', '/vehicles/' . self::$vehicle . '/bookers')->status);
	}

	/** The notice's button confirms the booking; the owner cannot confirm it for the driver. */
	#[Depends('testTheDriverBooksForNobodyElse')]
	public function testTheDriverConfirmsFromTheNotice(): void {
		$path = '/vehicles/' . self::$vehicle . '/bookings/' . self::$booking['uuid'] . '/seen';
		$this->assertSame(403, self::$server->ocs(self::$owner, 'POST', $path)->status);
		$notices = self::$server->request('GET', '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', self::$driver, ['OCS-APIRequest: true', 'Accept: application/json']);
		$this->assertSame(200, $notices->status, $notices->body);
		$madeFor = array_values(array_filter($notices->data(), static fn (array $one): bool => $one['object_type'] === 'booking_for'));
		$this->assertCount(1, $madeFor, $notices->body);
		[$seen] = $madeFor[0]['actions'];

		$link = (string)parse_url($seen['link'], PHP_URL_PATH);
		$confirmed = self::$server->request($seen['type'], $link, self::$driver, ['OCS-APIRequest: true', 'Accept: application/json']);

		$this->assertSame(200, $confirmed->status, $confirmed->body);
		$this->assertIsInt($confirmed->data()['seen_at']);
		// The v1 route, which a phone calls as the web does.
		$this->assertStringStartsWith('/ocs/v2.php/apps/nextfleet/api/v1/vehicles/', $link);
	}

	/** Named in another case, the driver is stored as the instance spells them, and confirms. */
	#[Depends('testTheDriverConfirmsFromTheNotice')]
	public function testTheDriverNamedInAnotherCaseIsStoredAsTheInstanceSpellsThem(): void {
		$booked = self::$server->ocs(self::$owner, 'POST', '/vehicles/' . self::$vehicle . '/bookings', $this->span(3) + ['user_id' => strtoupper(self::$driver->uid)]);

		$this->assertSame(201, $booked->status, $booked->body);
		$this->assertSame(self::$driver->uid, $booked->data()['user_id']);
		$seen = self::$server->ocs(self::$driver, 'POST', '/vehicles/' . self::$vehicle . '/bookings/' . $booked->data()['uuid'] . '/seen');
		$this->assertSame(200, $seen->status, $seen->body);
	}

	/** @return array<string, int> */
	private function span(int $days = 1): array {
		$start = time() + $days * 86400;

		return ['starts_at' => $start, 'starts_at_off' => 60, 'ends_at' => $start + 7200, 'ends_at_off' => 60];
	}
}
