<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\BackgroundJob;

use OCA\NextFleet\BackgroundJob\ForgetMemberJob;
use OCA\NextFleet\Service\GrantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud takes a queued job off the list before it runs it, so a failure would lose the work:
 * a former member would stay on the reminder lists of cars they no longer see.
 */
class ForgetMemberJobTest extends TestCase {
	private const ARGUMENT = ['group' => 'crew', 'user' => 'ben', 'at' => 1750000000];

	public function testAFailedRunIsQueuedAgain(): void {
		$grants = $this->createMock(GrantService::class);
		$grants->method('forgetMember')->willThrowException(new \RuntimeException('deadlock'));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(ForgetMemberJob::class, self::ARGUMENT);

		$this->runJob($grants, $jobs);
	}

	public function testARunThatWentThroughIsNotQueuedAgain(): void {
		$grants = $this->createMock(GrantService::class);
		$grants->expects($this->once())->method('forgetMember')->with('crew', 'ben', 1750000000);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$this->runJob($grants, $jobs);
	}

	/** run() alone: start() asks the server for its logger, which a unit test has not got. */
	private function runJob(GrantService $grants, IJobList $jobs): void {
		$job = new ForgetMemberJob($this->createMock(ITimeFactory::class), $grants, $jobs, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, self::ARGUMENT);
	}
}
