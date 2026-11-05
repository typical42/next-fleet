<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The owner allows or blocks one driver's Take it now: a blocked driver's take-now waits for an
 * answer like any booking (docs/architecture.md#booking-approval).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class TakeNowTest extends TestCase {
	/** Not an account: a vehicle's `user_id` is a string column. */
	private const OWNER = 'nextfleet-test-takenow-owner';
	/** Accounts and a group, since a grantee has to exist, and a group needs members. */
	private const DRIVER = 'nextfleet-test-takenow-driver';
	private const OTHER = 'nextfleet-test-takenow-other';
	private const CREW = 'nextfleet-test-takenow-crew';

	private VehicleService $vehicles;
	private GrantService $grants;
	private BookingService $bookings;
	private Vehicle $vehicle;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::DRIVER, self::OTHER] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::CREW);
		$crew?->addUser($users->get(self::DRIVER) ?? throw new \RuntimeException('no ' . self::DRIVER));
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::DRIVER, self::OTHER] as $uid) {
			$users->get($uid)?->delete();
		}
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
	}

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->forget();
		$this->vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-TN 1', 'booking_approval_rule' => Vehicle::APPROVAL_ALWAYS]);
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Every row this suite writes is the owner's, or a booking by one of its drivers. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_bookings')->where($qb->expr()->in('created_by', $qb->createNamedParameter([self::DRIVER, self::OTHER], IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_access')->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
	}

	/** A new grant allows it; the owner says otherwise on the grant or later, and the role stays. */
	public function testAGrantAllowsTakeItNowUntilTheOwnerBlocksIt(): void {
		$this->assertTrue($this->grant(self::DRIVER, 'user')['take_now']);

		$blocked = $this->change(self::DRIVER, ['role' => 'driver', 'take_now' => false]);
		$this->assertSame(['driver', false], [$blocked['role'], $blocked['take_now']]);

		// A client that sends the role alone keeps what the owner set, and so does a grant given again.
		$this->assertFalse($this->change(self::DRIVER, ['role' => 'manager'])['take_now']);
		$this->assertFalse($this->grant(self::DRIVER, 'user')['take_now']);
		// A null is a switch not sent: openapi.json offers it as one.
		$this->assertFalse($this->change(self::DRIVER, ['role' => 'driver', 'take_now' => null])['take_now']);
		$this->assertFalse($this->grant(self::DRIVER, 'user', ['take_now' => null])['take_now']);

		// A form posts the switch as a word.
		$this->assertFalse($this->grant(self::OTHER, 'user', ['take_now' => 'false'])['take_now']);
	}

	/** Not sent, a new grant allows it. */
	public function testANewGrantSentNullAllowsTakeItNow(): void {
		$this->assertTrue($this->grant(self::DRIVER, 'user', ['take_now' => null])['take_now']);
	}

	/** A switch that is neither on nor off is refused, and changes nothing. */
	public function testTakeItNowIsTrueOrFalse(): void {
		$this->grant(self::DRIVER, 'user');

		$this->expectException(\InvalidArgumentException::class);
		$this->change(self::DRIVER, ['role' => 'driver', 'take_now' => 'perhaps']);
	}

	/**
	 * A blocked driver's take-now is a request, back by midnight or not, and the vehicle tells their
	 * screen so. The block is theirs alone: the other driver takes the car as before.
	 */
	public function testABlockedDriversTakeItNowWaitsForAnAnswer(): void {
		$this->grant(self::DRIVER, 'user', ['take_now' => false]);
		$this->grant(self::OTHER, 'user');

		$this->assertNotContains('take_now', $this->may(self::DRIVER));
		$asked = $this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->now());
		$this->assertSame(Booking::REQUESTED, $asked['approval']);
		$this->assertNotContains('check_out', $asked['may']);
		$this->bookings->cancel(self::DRIVER, $this->vehicle->getUuid(), $asked['uuid'], $asked['updated_at']);

		$this->assertContains('take_now', $this->may(self::OTHER));
		$this->assertNull($this->bookings->book(self::OTHER, $this->vehicle->getUuid(), $this->now())['approval']);
	}

	/** Blocked in person, allowed through a group: one grant that allows it is enough. */
	public function testTheWidestGrantDecides(): void {
		$this->grant(self::DRIVER, 'user', ['take_now' => false]);
		$this->grant(self::CREW, 'group');

		$this->assertContains('take_now', $this->may(self::DRIVER));
		$this->assertNull($this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->now())['approval']);
	}

	/** The block narrows approval only: on a vehicle that asks nobody, nobody is asked. */
	public function testABlockAsksNothingWhereNoApprovalApplies(): void {
		$this->grant(self::DRIVER, 'user', ['take_now' => false]);
		$read = $this->vehicles->find(self::OWNER, $this->vehicle->getUuid());
		$this->vehicles->update(self::OWNER, $this->vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval_rule' => Vehicle::APPROVAL_NEVER]);

		$this->assertNull($this->bookings->book(self::DRIVER, $this->vehicle->getUuid(), $this->now())['approval']);
	}

	/**
	 * @param array<string, mixed> $more
	 * @return array<string, mixed> the grant as the list answers it
	 */
	private function grant(string $grantee, string $type, array $more = []): array {
		return $this->named($this->grants->grant(self::OWNER, $this->vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => $type, 'role' => 'driver'] + $more), $grantee);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed> the grant as the list answers it
	 */
	private function change(string $grantee, array $fields): array {
		$uuid = $this->named($this->grants->list(self::OWNER, $this->vehicle->getUuid()), $grantee)['uuid'];

		return $this->named($this->grants->change(self::OWNER, $this->vehicle->getUuid(), $uuid, $fields), $grantee);
	}

	/**
	 * @param list<array<string, mixed>> $list
	 * @return array<string, mixed>
	 */
	private function named(array $list, string $grantee): array {
		foreach ($list as $grant) {
			if ($grant['grantee'] === $grantee) {
				return $grant;
			}
		}
		$this->fail('no grant to ' . $grantee);
	}

	/** @return list<string> the vehicle's `may`, as the user reads it, the same in their overview */
	private function may(string $userId): array {
		$may = $this->vehicles->find($userId, $this->vehicle->getUuid())->getMay();
		foreach ($this->vehicles->list($userId) as $listed) {
			if ($listed->getUuid() === $this->vehicle->getUuid()) {
				$this->assertSame($may, $listed->getMay(), 'the overview answers as the vehicle does');
			}
		}

		return $may;
	}

	/**
	 * Take it now for a minute, on the clock that puts the booker at about noon, so the car is
	 * back by midnight whenever the suite runs.
	 *
	 * @return array<string, int>
	 */
	private function now(): array {
		$now = time();
		$off = intdiv((43200 - $now % 86400 + 86400 + 43200) % 86400 - 43200, 60);

		return ['starts_at' => $now, 'starts_at_off' => $off, 'ends_at' => $now + 60, 'ends_at_off' => $off];
	}
}
