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
 * The one place that decides who reaches a vehicle
 * (docs/adr/0001-own-access-table.md): its owner, or a grant whose role covers the operation.
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

	private function vehicle(): Vehicle {
		return Vehicle::fromRow([
			'id' => self::VEHICLE_ID,
			'uuid' => '0195e2f1-0000-4000-8000-000000000001',
			'user_id' => self::OWNER,
		]);
	}

	private function grant(string $role, string $grantee = self::STRANGER, string $type = Access::USER): Access {
		return Access::fromRow([
			'id' => 1,
			'uuid' => '0195e2f1-0000-4000-8000-000000000002',
			'vehicle_id' => self::VEHICLE_ID,
			'grantee' => $grantee,
			'grantee_type' => $type,
			'role' => $role,
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
		yield 'edit' => [VehicleAccess::EDIT];
		yield 'delete' => [VehicleAccess::DELETE];
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
		yield 'a manager edits' => ['manager', VehicleAccess::EDIT, true];
		yield 'a manager deletes' => ['manager', VehicleAccess::DELETE, true];
		yield 'a driver sees' => ['driver', VehicleAccess::VIEW, true];
		yield 'a driver does not edit' => ['driver', VehicleAccess::EDIT, false];
		yield 'a driver does not delete' => ['driver', VehicleAccess::DELETE, false];
		yield 'a viewer sees' => ['viewer', VehicleAccess::VIEW, true];
		yield 'a viewer does not edit' => ['viewer', VehicleAccess::EDIT, false];
		yield 'a viewer does not delete' => ['viewer', VehicleAccess::DELETE, false];
		// Nothing writes this table yet, so a word from an import or a later migration is the
		// realistic way one arrives, and it must not read as more than the roles we have.
		yield 'a role the domain does not have' => ['admin', VehicleAccess::VIEW, false];
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
}
