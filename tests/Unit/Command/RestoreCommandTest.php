<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\RestoreCommand;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\Pending;
use OCA\NextFleet\Service\VehicleService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A write that lands between the read and the restore, which the container cannot be timed to
 * do. The other paths are Integration\RestoreCommandTest's.
 */
class RestoreCommandTest extends TestCase {
	public function testARaceIsRefusedWithAReasonToRunItAgain(): void {
		$vehicle = new Vehicle();
		$vehicle->setUuid('raced');
		$vehicle->setUserId('owner');
		$vehicle->setUpdatedAt(7);
		$vehicle->setDeletedAt(5);
		$mapper = $this->createMock(VehicleMapper::class);
		$mapper->method('findAnyByUuid')->willReturn($vehicle);
		$vehicles = $this->createMock(VehicleService::class);
		$vehicles->expects($this->once())->method('restore')->with('owner', 'raced', 7)
			->willThrowException(new StaleUpdateException('fleet_vehicles row 1 is not the deleted row that was read'));
		$command = new CommandTester(new RestoreCommand($mapper, $vehicles, $this->users(true), $this->pending(false)));

		$this->assertSame(1, $command->execute(['vehicle' => 'raced'], ['capture_stderr_separately' => true]));

		$this->assertSame('', $command->getDisplay());
		$this->assertStringContainsString('run the command again', $command->getErrorOutput());
	}

	/** The account is gone and its erasure has not run: the vehicle would come back to nobody. */
	public function testAnOwnerWhoseAccountIsGoneIsRefused(): void {
		$command = $this->refusing($this->users(false), $this->pending(false));

		$this->assertSame(1, $command->execute(['vehicle' => 'orphaned'], ['capture_stderr_separately' => true]));
		$this->assertStringContainsString('No user backend knows its owner bob', $command->getErrorOutput());
	}

	/** The account exists again, but the erasure of the old one is still marked. */
	public function testAnOwnerWithAnErasurePendingIsRefused(): void {
		$command = $this->refusing($this->users(true), $this->pending(true));

		$this->assertSame(1, $command->execute(['vehicle' => 'orphaned'], ['capture_stderr_separately' => true]));
		$this->assertStringContainsString('erasure of its owner bob is still pending', $command->getErrorOutput());
	}

	private function refusing(IUserManager $users, Pending $pending): CommandTester {
		$vehicle = new Vehicle();
		$vehicle->setUuid('orphaned');
		$vehicle->setUserId('bob');
		$vehicle->setUpdatedAt(7);
		$vehicle->setDeletedAt(5);
		$mapper = $this->createMock(VehicleMapper::class);
		$mapper->method('findAnyByUuid')->willReturn($vehicle);
		$vehicles = $this->createMock(VehicleService::class);
		$vehicles->expects($this->never())->method('restore');

		return new CommandTester(new RestoreCommand($mapper, $vehicles, $users, $pending));
	}

	private function users(bool $exists): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn($exists);

		return $users;
	}

	private function pending(bool $marked): Pending {
		$pending = $this->createMock(Pending::class);
		$pending->method('marks')->willReturn($marked);

		return $pending;
	}
}
