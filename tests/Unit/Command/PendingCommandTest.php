<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\PendingCommand;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\Pending;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A finish that fails, which the container's services cannot be made to do. The happy paths are
 * Integration\PendingCommandTest's.
 */
class PendingCommandTest extends TestCase {
	/** Both run, whatever the first did, as in PendingJob; what failed is still marked after. */
	public function testAFailurePrintsItsMessageAndExitsOne(): void {
		$pending = $this->createMock(Pending::class);
		$pending->method('of')->willReturnCallback(static fn (string $kind): array => $kind === Pending::ERASURE ? [['id' => 'ghost', 'since' => 0]] : []);
		$erasure = $this->createMock(ErasureService::class);
		$erasure->expects($this->once())->method('finish')->willReturn([['id' => 'ghost', 'error' => new \RuntimeException('the database broke')]]);
		$grants = $this->createMock(GrantService::class);
		$grants->expects($this->once())->method('finish')->willThrowException(new \RuntimeException('the config went away'));
		$command = new CommandTester(new PendingCommand($pending, $erasure, $grants, $this->users(false)));

		$this->assertSame(1, $command->execute(['--finish' => true, '--output' => 'json'], ['capture_stderr_separately' => true]));

		$this->assertSame([['kind' => 'erasure', 'id' => 'ghost', 'marked_at' => '1970-01-01T00:00:00Z']], json_decode($command->getDisplay(), true));
		$this->assertStringContainsString('erasure ghost: the database broke', $command->getErrorOutput());
		$this->assertStringContainsString('the config went away', $command->getErrorOutput());
	}

	public function testPlainSaysWhenNothingIsMarked(): void {
		$pending = $this->createMock(Pending::class);
		$pending->method('of')->willReturn([]);
		$command = new CommandTester(new PendingCommand($pending, $this->createMock(ErasureService::class), $this->createMock(GrantService::class), $this->users(false)));

		$this->assertSame(0, $command->execute([]));

		$this->assertSame("Nothing is pending.\n", $command->getDisplay());
	}

	/** The job drops it with a log line only; the admin who runs the command is told. */
	public function testAnErasureOfAUidThatExistsAgainIsNamed(): void {
		$pending = $this->createMock(Pending::class);
		$pending->method('of')->willReturnCallback(static fn (string $kind): array => $kind === Pending::ERASURE ? [['id' => 'bob', 'since' => 0]] : []);
		$command = new CommandTester(new PendingCommand($pending, $this->createMock(ErasureService::class), $this->createMock(GrantService::class), $this->users(true)));

		$this->assertSame(0, $command->execute(['--finish' => true], ['capture_stderr_separately' => true]));

		$this->assertStringContainsString('erasure bob: an account of that uid exists again, so the erasure is dropped', $command->getErrorOutput());
	}

	private function users(bool $exists): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn($exists);

		return $users;
	}
}
