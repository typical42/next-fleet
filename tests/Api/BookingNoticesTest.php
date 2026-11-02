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
 * An owner sets who hears of a vehicle's bookings, and of which events, over OCS
 * (docs/api.md#booking-notices). Each step leans on the one before.
 */
class BookingNoticesTest extends TestCase {
	private const PREFIX = 'nextfleet-notices-';

	private static Server $server;
	private static Account $owner;
	private static Account $manager;
	/** @var array<string, mixed> */
	private static array $vehicle;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$manager = self::$server->account(self::PREFIX . 'manager-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	public function testANewVehicleHearsOfBookingsAndTakingsWithAnEmptyList(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', ['plate' => 'K 2', 'jurisdiction' => 'de', 'odo_unit' => 'km']);
		$this->assertSame(201, $created->status, $created->body);
		self::$vehicle = $created->data();
		$this->assertSame(['booked', 'taken'], self::$vehicle['booking_notices']);
		$uuid = self::$vehicle['uuid'];

		$list = self::$server->ocs(self::$owner, 'GET', "/vehicles/$uuid/booking-recipients");
		$this->assertSame(200, $list->status, $list->body);
		$this->assertSame([], $list->data());
	}

	#[Depends('testANewVehicleHearsOfBookingsAndTakingsWithAnEmptyList')]
	public function testTheOwnerTicksTheEventsAndPutsAManagerOnTheList(): void {
		$uuid = self::$vehicle['uuid'];
		$granted = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/grants", ['grantee' => self::$manager->uid, 'grantee_type' => 'user', 'role' => 'manager']);
		$this->assertSame(200, $granted->status, $granted->body);

		$ticked = self::$server->ocs(self::$owner, 'PUT', "/vehicles/$uuid", ['updated_at' => self::$vehicle['updated_at'], 'booking_notices' => ['returned']]);
		$this->assertSame(200, $ticked->status, $ticked->body);
		$this->assertSame(['returned'], $ticked->data()['booking_notices']);
		self::$vehicle = $ticked->data();

		$added = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/booking-recipients", ['recipient' => self::$manager->uid, 'recipient_type' => 'user']);
		$this->assertSame(200, $added->status, $added->body);
		$this->assertSame([self::$manager->uid], array_column($added->data(), 'recipient'));
		$this->assertSame(['user'], array_column($added->data(), 'recipient_type'));
	}

	/** A manager reads the list; the list and the events are the owner's to change. */
	#[Depends('testTheOwnerTicksTheEventsAndPutsAManagerOnTheList')]
	public function testAManagerReadsTheListAndChangesNothing(): void {
		$uuid = self::$vehicle['uuid'];

		$this->assertSame(200, self::$server->ocs(self::$manager, 'GET', "/vehicles/$uuid/booking-recipients")->status);
		$this->assertSame(403, self::$server->ocs(self::$manager, 'DELETE', "/vehicles/$uuid/booking-recipients/user/" . self::$manager->uid)->status);
		$this->assertSame(403, self::$server->ocs(self::$manager, 'PUT', "/vehicles/$uuid", ['updated_at' => self::$vehicle['updated_at'], 'booking_notices' => ['booked']])->status);
	}

	#[Depends('testAManagerReadsTheListAndChangesNothing')]
	public function testTheOwnerTakesOneOffTheList(): void {
		$uuid = self::$vehicle['uuid'];

		$this->assertSame(400, self::$server->ocs(self::$owner, 'DELETE', "/vehicles/$uuid/booking-recipients/team/" . self::$manager->uid)->status);
		$removed = self::$server->ocs(self::$owner, 'DELETE', "/vehicles/$uuid/booking-recipients/user/" . self::$manager->uid);
		$this->assertSame(200, $removed->status, $removed->body);
		$this->assertSame([], $removed->data());
	}
}
