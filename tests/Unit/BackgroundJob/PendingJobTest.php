<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\BackgroundJob;

use OCA\NextFleet\BackgroundJob\PendingJob;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\GrantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingJobTest extends TestCase {
	/** An erasure that fails again holds no revoke up, and the admin's log says so. */
	public function testAFinishThatFailsLeavesTheOtherToRunAndIsLogged(): void {
		$erasure = $this->createMock(ErasureService::class);
		$failure = new \RuntimeException('deadlock');
		$erasure->method('finish')->willThrowException($failure);
		$grants = $this->createMock(GrantService::class);
		$grants->expects($this->once())->method('finish');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Pending work was not finished', ['app' => 'nextfleet', 'exception' => $failure]);

		$job = new PendingJob($this->createMock(ITimeFactory::class), $erasure, $grants, $this->createMock(IAppConfig::class), $logger);
		// run() alone: start() asks the server for its logger.
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
