<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\RemindersCommand;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\ReminderService;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A `--send` step that throws, which the container's services cannot be made to do. The happy
 * paths are Integration\RemindersCommandTest's.
 */
class RemindersCommandTest extends TestCase {
	/** As in ReminderJob, no digest follows a failed sweep: it would mail states never persisted. */
	public function testAFailedSweepSendsNoDigest(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('sweep')->willThrowException(new \RuntimeException('the database broke'));
		$mail = $this->createMock(MailService::class);
		$mail->expects($this->never())->method('digest');

		$command = $this->command($notifications, $mail);
		$this->assertSame(1, $command->execute(['--send' => true], ['capture_stderr_separately' => true]));

		$this->assertSame('', $command->getDisplay());
		$this->assertSame("notifications: the database broke\n", $command->getErrorOutput());
	}

	public function testAFailedDigestPrintsItsMessageAndExitsOne(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('sweep');
		$mail = $this->createMock(MailService::class);
		$mail->expects($this->once())->method('digest')->willThrowException(new \RuntimeException('the mail server went away'));

		$command = $this->command($notifications, $mail);
		$this->assertSame(1, $command->execute(['--send' => true], ['capture_stderr_separately' => true]));

		$this->assertSame("digest: the mail server went away\n", $command->getErrorOutput());
	}

	private function command(NotificationService $notifications, MailService $mail): CommandTester {
		return new CommandTester(new RemindersCommand(
			$this->createMock(ReminderService::class),
			$notifications,
			$mail,
			$this->createMock(IFactory::class),
			$this->createMock(IUserManager::class),
		));
	}
}
