<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\BackgroundJob;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Service\GrantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Takes a member just out of a group off the recipients the group gave them
 * (GrantService::forgetMember()). Queued by GroupMemberRemovedListener: one transaction per
 * vehicle the group reaches is too much for the group admin's request. Access is asked when it
 * runs, so a member put back meanwhile stays.
 */
class ForgetMemberJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private GrantService $grants,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `group`, `user` and `at`: a gid, a uid and when they left
	 */
	protected function run($argument): void {
		/** @var array{group: string, user: string, at: int} $argument */
		try {
			$this->grants->forgetMember($argument['group'], $argument['user'], $argument['at']);
		} catch (\Throwable $e) {
			// Nextcloud took the job off the list before it ran. Every vehicle is asked again,
			// which changes nothing where it went through.
			$this->logger->error('A former group member is still on reminder lists; tried again next run', ['app' => Application::APP_ID, 'exception' => $e]);
			$this->jobs->add(self::class, $argument);
		}
	}
}
