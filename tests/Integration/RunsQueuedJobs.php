<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\BackgroundJob\IJobList;

/**
 * What cron does with a request's queued work, on demand: a test cannot wait for the next run.
 */
trait RunsQueuedJobs {
	/**
	 * Runs every queued job of the class, each once, as cron would - other cases' too, which the
	 * jobs' own checks answer for.
	 *
	 * @param class-string<\OCP\BackgroundJob\IJob> $class
	 */
	private static function runQueued(string $class): void {
		$jobs = \OCP\Server::get(IJobList::class);
		foreach ($jobs->getJobsIterator($class, null, 0) as $job) {
			$job->start($jobs);
		}
	}
}
