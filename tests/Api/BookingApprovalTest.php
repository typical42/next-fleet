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
 * An owner makes a vehicle's bookings wait for an answer, a driver's request holds its span, and
 * the owner answers it, over OCS (docs/api.md#booking-approval). Each step leans on the one before.
 */
class BookingApprovalTest extends TestCase {
	private const PREFIX = 'nextfleet-approval-';

	private static Server $server;
	private static Account $owner;
	private static Account $driver;
	/** @var array<string, mixed> */
	private static array $vehicle;
	/** @var array<string, mixed> */
	private static array $request;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$owner = self::$server->account(self::PREFIX . 'owner-');
		self::$driver = self::$server->account(self::PREFIX . 'driver-');
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	public function testTheOwnerTurnsApprovalOn(): void {
		$created = self::$server->ocs(self::$owner, 'POST', '/vehicles', ['plate' => 'K 3', 'jurisdiction' => 'de', 'odo_unit' => 'km']);
		$this->assertSame(201, $created->status, $created->body);
		$this->assertFalse($created->data()['booking_approval']);
		$uuid = $created->data()['uuid'];
		$granted = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/grants", ['grantee' => self::$driver->uid, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(200, $granted->status, $granted->body);

		$on = self::$server->ocs(self::$owner, 'PUT', "/vehicles/$uuid", ['updated_at' => $created->data()['updated_at'], 'booking_approval' => true]);
		$this->assertSame(200, $on->status, $on->body);
		$this->assertTrue($on->data()['booking_approval']);
		self::$vehicle = $on->data();
	}

	#[Depends('testTheOwnerTurnsApprovalOn')]
	public function testADriversBookingIsARequestThatHoldsItsSpan(): void {
		$uuid = self::$vehicle['uuid'];
		$start = time() + 86400;
		$span = ['starts_at' => $start, 'starts_at_off' => 60, 'ends_at' => $start + 7200, 'ends_at_off' => 60];

		$booked = self::$server->ocs(self::$driver, 'POST', "/vehicles/$uuid/bookings", $span);
		$this->assertSame(201, $booked->status, $booked->body);
		$this->assertSame(['booked', 'requested'], [$booked->data()['state'], $booked->data()['approval']]);
		self::$request = $booked->data();

		$over = self::$server->ocs(self::$owner, 'POST', "/vehicles/$uuid/bookings", $span);
		$this->assertSame(409, $over->status, $over->body);
		$this->assertSame('requested', $over->data()['booking']['approval']);
	}

	#[Depends('testADriversBookingIsARequestThatHoldsItsSpan')]
	public function testTheOwnerAsksBackAndTheDriverMayNotAnswer(): void {
		$path = '/vehicles/' . self::$vehicle['uuid'] . '/bookings/' . self::$request['uuid'];

		$this->assertSame(403, self::$server->ocs(self::$driver, 'POST', "$path/approve", ['updated_at' => self::$request['updated_at']])->status);
		$asked = self::$server->ocs(self::$owner, 'POST', "$path/ask", ['updated_at' => self::$request['updated_at'], 'comment' => 'The van instead?']);
		$this->assertSame(200, $asked->status, $asked->body);
		$this->assertSame(['asked', 'The van instead?', self::$owner->uid], [$asked->data()['approval'], $asked->data()['approval_comment'], $asked->data()['approval_by']]);
		$this->assertSame(412, self::$server->ocs(self::$owner, 'POST', "$path/decline", ['updated_at' => self::$request['updated_at']])->status);
		self::$request = $asked->data();
	}

	/** Taken early, an approved booking would go: a waiting one does not. */
	#[Depends('testTheOwnerAsksBackAndTheDriverMayNotAnswer')]
	public function testTheDriverTakesNothingAndAMoveAsksAgain(): void {
		$path = '/vehicles/' . self::$vehicle['uuid'] . '/bookings/' . self::$request['uuid'];
		$this->assertNotContains('check_out', self::$request['may']);

		$this->assertSame(400, self::$server->ocs(self::$driver, 'POST', "$path/check-out", ['odo' => 100, 'at_off' => 60])->status);
		$span = ['starts_at' => self::$request['starts_at'], 'starts_at_off' => 60, 'ends_at' => self::$request['ends_at'] + 3600, 'ends_at_off' => 60];
		$moved = self::$server->ocs(self::$driver, 'PUT', $path, ['updated_at' => self::$request['updated_at']] + $span);
		$this->assertSame(200, $moved->status, $moved->body);
		$this->assertSame(['requested', null], [$moved->data()['approval'], $moved->data()['approval_comment']]);
		self::$request = $moved->data();
	}

	#[Depends('testTheDriverTakesNothingAndAMoveAsksAgain')]
	public function testTheOwnerDeclinesAndTheSpanIsFree(): void {
		$path = '/vehicles/' . self::$vehicle['uuid'] . '/bookings/' . self::$request['uuid'];

		$declined = self::$server->ocs(self::$owner, 'POST', "$path/decline", ['updated_at' => self::$request['updated_at']]);
		$this->assertSame(200, $declined->status, $declined->body);
		$this->assertSame(['cancelled', 'declined'], [$declined->data()['state'], $declined->data()['approval']]);
		$this->assertSame(400, self::$server->ocs(self::$owner, 'POST', "$path/approve", ['updated_at' => $declined->data()['updated_at']])->status);
	}
}
