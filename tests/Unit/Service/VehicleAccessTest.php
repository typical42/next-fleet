<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The one place that decides who reaches a vehicle (docs/adr/0001-own-access-table.md): its
 * owner, or a grant whose role covers the operation.
 */
class VehicleAccessTest extends TestCase {
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const STRANGER = 'bob';

	private AccessMapper&MockObject $grants;
	/** @var list<string> */
	private array $groupIds = [];
	private bool $hasAccount = true;

	protected function setUp(): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn([]);
	}

	private function access(): VehicleAccess {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->hasAccount ? $this->createMock(IUser::class) : null);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(fn () => $this->groupIds);

		return new VehicleAccess($this->grants, $users, $groups);
	}

	private function vehicle(bool $costsShared = true): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'uuid' => '0195e2f1-0000-4000-8000-000000000001',
			'user_id' => self::OWNER,
			'costs_shared' => $costsShared,
		]);
	}

	private static function grant(string $role, string $grantee = self::STRANGER, string $type = Access::USER, ?bool $takeNow = true): Access {
		return Access::fromRow([
			'id' => 1,
			'uuid' => '0195e2f1-0000-4000-8000-000000000002',
			'vehicle_id' => self::VEHICLE_ID,
			'grantee' => $grantee,
			'grantee_type' => $type,
			'role' => $role,
			'take_now' => $takeNow,
		]);
	}

	/**
	 * The owner needs no grant, and asking the table for one would be a query on every read of
	 * every vehicle the common case owns.
	 *
	 * @dataProvider operations
	 */
	public function testTheOwnerMayEverything(string $operation): void {
		$this->grants->expects($this->never())->method('findGrants');

		$this->assertTrue($this->access()->may(self::OWNER, $operation, $this->vehicle()));
	}

	/**
	 * The realistic bug in an id-addressed API (docs/security.md): another user on the same
	 * instance holding a uuid they were never granted.
	 *
	 * @dataProvider operations
	 */
	public function testSomeoneWithNoGrantMayNothing(string $operation): void {
		$this->assertFalse($this->access()->may(self::STRANGER, $operation, $this->vehicle()));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function operations(): iterable {
		yield 'view' => [VehicleAccess::VIEW];
		yield 'log' => [VehicleAccess::LOG];
		yield 'edit' => [VehicleAccess::EDIT];
		yield 'delete' => [VehicleAccess::DELETE];
		yield 'own' => [VehicleAccess::OWN];
		yield 'costs' => [VehicleAccess::COSTS];
		yield 'take_now' => [VehicleAccess::TAKE_NOW];
	}

	/** The switch is for drivers and viewers: the owner sees what the car costs whatever it says. */
	public function testTheOwnerSeesCostsWithTheSwitchOff(): void {
		$this->assertTrue($this->access()->may(self::OWNER, VehicleAccess::COSTS, $this->vehicle(costsShared: false)));
	}

	/**
	 * Costs follow the role and the vehicle's switch: the manager handles them and always sees
	 * them, a driver and a viewer only while the owner shares them.
	 *
	 * @dataProvider costRoles
	 */
	public function testCostsAreTheManagersAndTheSwitchDecidesForTheRest(string $role, bool $costsShared, bool $expected): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn([$this->grant($role)]);

		$this->assertSame($expected, $this->access()->may(self::STRANGER, VehicleAccess::COSTS, $this->vehicle($costsShared)));
	}

	/**
	 * @return iterable<string, array{string, bool, bool}>
	 */
	public static function costRoles(): iterable {
		yield 'a manager, shared' => ['manager', true, true];
		yield 'a manager, not shared' => ['manager', false, true];
		yield 'a driver, shared' => ['driver', true, true];
		yield 'a driver, not shared' => ['driver', false, false];
		yield 'a viewer, shared' => ['viewer', true, true];
		yield 'a viewer, not shared' => ['viewer', false, false];
		// Sharing is with those who have access, never with everybody.
		yield 'a role the domain does not have, shared' => ['admin', true, false];
	}

	/** A stranger reaches nothing, so a shared switch gives them no costs either. */
	public function testASharedSwitchGivesAStrangerNothing(): void {
		$this->assertSame([], $this->access()->operations(self::STRANGER, $this->vehicle(costsShared: true)));
	}

	/**
	 * What one Entry's amount shows to the reader: everybody's with `costs`, their own whatever
	 * the switch says, because they entered it. Read off what the gate left, as mayChange() is.
	 *
	 * @dataProvider entryCosts
	 * @param list<string> $held
	 */
	public function testAnAmountShowsWithCostsOrToWhoEnteredIt(array $held, ?string $createdBy, bool $expected): void {
		$this->grants->expects($this->never())->method('findGrants');
		$vehicle = $this->vehicle(costsShared: false);
		$vehicle->setMay($held);

		$this->assertSame($expected, $this->access()->mayCost(self::STRANGER, $vehicle, $createdBy));
	}

	/**
	 * @return iterable<string, array{list<string>, ?string, bool}>
	 */
	public static function entryCosts(): iterable {
		yield 'with costs, somebody else\'s' => [['view', 'log', 'costs'], self::OWNER, true];
		yield 'a driver, their own' => [['view', 'log'], self::STRANGER, true];
		yield 'a driver, somebody else\'s' => [['view', 'log'], self::OWNER, false];
		yield 'a viewer, their own from when they drove' => [['view'], self::STRANGER, true];
		yield 'a driver, an erased author' => [['view', 'log'], null, false];
		yield 'a vehicle that never passed the gate' => [[], self::STRANGER, false];
	}

	/**
	 * Who sees every driver's figures on the Statistics screen: the owner and the managers, who
	 * hold `edit`. Read off what the gate left, as mayChange() is.
	 *
	 * @dataProvider driverFigures
	 * @param list<string> $held
	 */
	public function testEveryDriversFiguresAreTheOwnersAndTheManagers(array $held, bool $expected): void {
		$this->grants->expects($this->never())->method('findGrants');
		$vehicle = $this->vehicle();
		$vehicle->setMay($held);

		$this->assertSame($expected, $this->access()->mayCompareDrivers(self::STRANGER, $vehicle));
	}

	/**
	 * Who answers a booking that waits: the same owner and managers, by the same rule.
	 *
	 * @dataProvider driverFigures
	 * @param list<string> $held
	 */
	public function testABookingIsAnsweredByTheOwnerAndTheManagers(array $held, bool $expected): void {
		$this->grants->expects($this->never())->method('findGrants');
		$vehicle = $this->vehicle();
		$vehicle->setMay($held);

		$this->assertSame($expected, $this->access()->mayApprove(self::STRANGER, $vehicle));
	}

	/**
	 * @return iterable<string, array{list<string>, bool}>
	 */
	public static function driverFigures(): iterable {
		yield 'the owner' => [['view', 'log', 'edit', 'delete', 'own', 'costs'], true];
		yield 'a manager' => [['view', 'log', 'edit', 'delete', 'costs'], true];
		yield 'a driver, costs shared' => [['view', 'log', 'costs'], false];
		yield 'a viewer' => [['view'], false];
		yield 'a vehicle that never passed the gate' => [[], false];
	}

	/**
	 * A role is what a grant means, and a check that stopped at "there is a row" would hand a
	 * viewer the delete button (docs/security.md).
	 *
	 * @dataProvider roles
	 */
	public function testARoleCoversItsOwnOperationsAndNoOthers(string $role, string $operation, bool $expected): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn([$this->grant($role)]);

		$this->assertSame($expected, $this->access()->may(self::STRANGER, $operation, $this->vehicle()));
	}

	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function roles(): iterable {
		yield 'a manager sees' => ['manager', VehicleAccess::VIEW, true];
		yield 'a manager logs' => ['manager', VehicleAccess::LOG, true];
		yield 'a manager edits' => ['manager', VehicleAccess::EDIT, true];
		yield 'a manager deletes' => ['manager', VehicleAccess::DELETE, true];
		// The car is the owner's: a manager keeps everything but access and whether it exists.
		yield 'a manager does not own' => ['manager', VehicleAccess::OWN, false];
		yield 'a driver sees' => ['driver', VehicleAccess::VIEW, true];
		yield 'a driver logs' => ['driver', VehicleAccess::LOG, true];
		yield 'a driver does not edit' => ['driver', VehicleAccess::EDIT, false];
		yield 'a driver does not delete' => ['driver', VehicleAccess::DELETE, false];
		yield 'a driver does not own' => ['driver', VehicleAccess::OWN, false];
		yield 'a viewer sees' => ['viewer', VehicleAccess::VIEW, true];
		yield 'a viewer does not log' => ['viewer', VehicleAccess::LOG, false];
		yield 'a viewer does not edit' => ['viewer', VehicleAccess::EDIT, false];
		yield 'a viewer does not delete' => ['viewer', VehicleAccess::DELETE, false];
		yield 'a viewer does not own' => ['viewer', VehicleAccess::OWN, false];
		// Granting takes only the roles we have, so a word from a later migration that drops one
		// is the realistic way another arrives, and it must not read as more than those.
		yield 'a role the domain does not have' => ['admin', VehicleAccess::VIEW, false];
	}

	/**
	 * Take it now skips approval for a driver while their grant allows it, the column's default.
	 * The owner and the managers answer their own bookings, so a value kept from when a manager
	 * drove narrows nothing; a viewer books nothing, whatever their row says.
	 *
	 * @dataProvider takeNow
	 * @param list<Access> $grants
	 */
	public function testTakeItNowIsADriversWhileAGrantAllowsIt(array $grants, bool $expected): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn($grants);

		$this->assertSame($expected, $this->access()->may(self::STRANGER, VehicleAccess::TAKE_NOW, $this->vehicle()));
	}

	/**
	 * @return iterable<string, array{list<Access>, bool}>
	 */
	public static function takeNow(): iterable {
		yield 'a driver, allowed' => [[self::grant('driver')], true];
		yield 'a driver, a row from before the column' => [[self::grant('driver', takeNow: null)], true];
		yield 'a driver, blocked' => [[self::grant('driver', takeNow: false)], false];
		// Widest wins, as for roles: one row that allows is enough.
		yield 'blocked in person, allowed through a group' => [[self::grant('driver', takeNow: false), self::grant('driver', 'drivers', Access::GROUP)], true];
		yield 'allowed in person, blocked through a group' => [[self::grant('driver'), self::grant('driver', 'drivers', Access::GROUP, false)], true];
		yield 'blocked in person and through a group' => [[self::grant('driver', takeNow: false), self::grant('driver', 'drivers', Access::GROUP, false)], false];
		yield 'a manager, blocked from when they drove' => [[self::grant('manager', takeNow: false)], true];
		yield 'a viewer, allowed' => [[self::grant('viewer')], false];
		yield 'a viewer allowed, a driver blocked' => [[self::grant('viewer'), self::grant('driver', 'drivers', Access::GROUP, false)], false];
	}

	/** Granted in person and again through a group: the widest of the two decides. */
	public function testTheWidestGrantDecides(): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn([
			$this->grant('viewer'),
			$this->grant('manager', 'drivers', Access::GROUP),
		]);

		$this->assertTrue($this->access()->may(self::STRANGER, VehicleAccess::DELETE, $this->vehicle()));
	}

	/**
	 * What the screen hides by: every operation the caller holds, from the same rows may()
	 * reads, so a button cannot disagree with the server.
	 *
	 * @param list<string> $roles
	 * @param list<string> $expected
	 * @dataProvider operationLists
	 */
	public function testOperationsAreEverythingTheCallerMay(string $userId, array $roles, array $expected): void {
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn(array_map(fn (string $role): Access => $this->grant($role), $roles));

		$this->assertSame($expected, $this->access()->operations($userId, $this->vehicle()));
	}

	/**
	 * @return iterable<string, array{string, list<string>, list<string>}>
	 */
	public static function operationLists(): iterable {
		yield 'the owner' => [self::OWNER, [], ['view', 'log', 'edit', 'delete', 'own', 'costs', 'take_now']];
		yield 'a manager' => [self::STRANGER, ['manager'], ['view', 'log', 'edit', 'delete', 'costs', 'take_now']];
		yield 'a driver' => [self::STRANGER, ['driver'], ['view', 'log', 'costs', 'take_now']];
		yield 'a viewer' => [self::STRANGER, ['viewer'], ['view', 'costs']];
		yield 'a viewer and a driver at once' => [self::STRANGER, ['viewer', 'driver'], ['view', 'log', 'costs', 'take_now']];
		yield 'a stranger' => [self::STRANGER, [], []];
	}

	/**
	 * The overview's half of the same answer: every vehicle a grant reaches and what the caller
	 * may do on it, from one query, the widest row per vehicle deciding.
	 */
	public function testReachableSaysWhatTheCallerMayOnEachGrantedVehicle(): void {
		$this->groupIds = ['drivers'];
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->expects($this->once())
			->method('findReachable')
			->with(self::STRANGER, ['drivers'], ['manager', 'driver', 'viewer'])
			->willReturn([7 => [['viewer', true], ['driver', true]], 8 => [['driver', false]], 9 => [['manager', false]]]);

		// The switch is on each vehicle, so what it adds is listed()'s to read; the role alone is here.
		$this->assertSame(
			[7 => ['view', 'log', 'take_now'], 8 => ['view', 'log'], 9 => ['view', 'log', 'edit', 'delete', 'costs', 'take_now']],
			$this->access()->reachable(self::STRANGER),
		);
	}

	/**
	 * A vehicle in a list is answered from what reachable() found, not a query per row; the
	 * owner's own from the column on the vehicle, and the cost switch too.
	 */
	public function testAListedVehicleIsAnsweredWithoutAQuery(): void {
		$this->grants->expects($this->never())->method('findGrants');
		$reachable = [self::VEHICLE_ID => ['view']];

		$this->assertSame(['view', 'log', 'edit', 'delete', 'own', 'costs', 'take_now'], $this->access()->listed(self::OWNER, $this->vehicle(), $reachable));
		$this->assertSame(['view', 'costs'], $this->access()->listed(self::STRANGER, $this->vehicle(), $reachable));
		$this->assertSame(['view'], $this->access()->listed(self::STRANGER, $this->vehicle(costsShared: false), $reachable));
		$this->assertSame([], $this->access()->listed(self::STRANGER, $this->vehicle(), []));
	}

	/**
	 * Changing an Entry: anybody's with the operation, your own with `log`. Read off what the
	 * gate left on the vehicle, so it asks the table nothing.
	 *
	 * @dataProvider entryChanges
	 * @param list<string> $held
	 */
	public function testAnEntryIsChangedWithTheOperationOrByWhoEnteredIt(array $held, string $operation, ?string $createdBy, bool $expected): void {
		$this->grants->expects($this->never())->method('findGrants');
		$vehicle = $this->vehicle();
		$vehicle->setMay($held);

		$this->assertSame($expected, $this->access()->mayChange(self::STRANGER, $operation, $vehicle, $createdBy));
	}

	/**
	 * @return iterable<string, array{list<string>, string, ?string, bool}>
	 */
	public static function entryChanges(): iterable {
		$driver = ['view', 'log'];
		$manager = ['view', 'log', 'edit', 'delete'];
		yield 'a driver edits their own' => [$driver, 'edit', self::STRANGER, true];
		yield 'a driver deletes their own' => [$driver, 'delete', self::STRANGER, true];
		yield 'a driver edits somebody else\'s' => [$driver, 'edit', self::OWNER, false];
		yield 'a driver deletes somebody else\'s' => [$driver, 'delete', self::OWNER, false];
		yield 'a driver on an erased author' => [$driver, 'edit', null, false];
		yield 'a manager edits anybody\'s' => [$manager, 'edit', self::OWNER, true];
		yield 'a manager deletes anybody\'s' => [$manager, 'delete', self::OWNER, true];
		yield 'a viewer edits their own' => [['view'], 'edit', self::STRANGER, false];
		yield 'a vehicle that never passed the gate' => [[], 'edit', self::STRANGER, false];
	}

	/**
	 * A pseudonym is no account (docs/adr/0008-erasing-a-driver-pseudonymises.md): whatever still
	 * names it - an erased owner's vehicle, a renamed grant, an Entry it wrote - opens nothing to a
	 * caller that hands it in as a uid.
	 */
	public function testAPseudonymMayNothing(): void {
		$erased = 'erased:k3x9qk3x9qk3x9qk3x9q';
		$this->grants = $this->createMock(AccessMapper::class);
		$this->grants->method('findGrants')->willReturn([$this->grant('manager', $erased)]);
		$this->grants->method('findReachable')->willReturn([self::VEHICLE_ID => [['manager', true]]]);
		$owned = Vehicle::fromRow(['id' => self::VEHICLE_ID, 'uuid' => '0195e2f1-0000-4000-8000-000000000001', 'user_id' => $erased]);
		$granted = $this->vehicle();
		$granted->setMay(['view', 'log']);
		$access = $this->access();

		$this->assertSame([], $access->operations($erased, $owned));
		$this->assertSame([], $access->operations($erased, $this->vehicle()));
		$this->assertFalse($access->may($erased, VehicleAccess::VIEW, $owned));
		$this->assertFalse($access->mayChange($erased, VehicleAccess::EDIT, $granted, $erased));
		$this->assertFalse($access->mayBooking($erased, $granted, $erased));
		$this->assertFalse($access->mayCost($erased, $granted, $erased));
		$this->assertFalse($access->mayCompareDrivers($erased, $owned));
		$this->assertFalse($access->mayApprove($erased, $owned));
		$this->assertSame([], $access->listed($erased, $owned, [self::VEHICLE_ID => ['view']]));
		$this->assertSame([], $access->reachable($erased));
	}
}
