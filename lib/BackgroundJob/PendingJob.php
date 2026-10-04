<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\BackgroundJob;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\GrantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly: finishes what a failure left pending (Service\Pending). Its own job, so a sweep that
 * fails cannot hold an erasure up, nor the other way round.
 */
class PendingJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ErasureService $erasure,
		private GrantService $grants,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		// cron.php runs many jobs in one process: what it read earlier may since have been finished.
		$this->config->clearCache();
		foreach ([$this->erasure->finish(...), $this->grants->finish(...)] as $finish) {
			try {
				$finish();
			} catch (\Throwable $e) {
				$this->logger->error('Pending work was not finished', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}
}
