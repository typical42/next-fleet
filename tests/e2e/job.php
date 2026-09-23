<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Runs ReminderJob once, as cron would, at the instant given as the one argument (ISO 8601):
 *
 *   php custom_apps/nextfleet/tests/e2e/job.php 2031-05-03T12:00:00Z
 *
 * `occ background-job:execute` runs it at the real time, and a reminder engine you cannot move
 * through time is one you cannot test (PRD M4). m4-slice.spec.js runs this inside the container
 * (tests/e2e/server.js). The job sweeps every vehicle on the instance, the demo fleet included.
 */

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\BackgroundJob\ReminderJob;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;

if ($argc !== 2) {
	fwrite(STDERR, "usage: php job.php <ISO 8601 instant>\n");
	exit(2);
}

// tests/e2e/ → nextfleet → custom_apps → the server root, which exists only in the container.
/** @psalm-suppress MissingFile */
require_once __DIR__ . '/../../../../lib/base.php';

// cron.php boots every app before a job; without the notifications app a notification has
// nowhere to go (tests/Integration/ReminderJobTest.php).
\OCP\Server::get(IAppManager::class)->loadApps();

// Anonymous, because a named class is bound before base.php has loaded the interface.
$clock = new class(new \DateTimeImmutable($argv[1])) implements ITimeFactory {
	public function __construct(
		private \DateTimeImmutable $at,
	) {
	}

	public function getTime(): int {
		return $this->at->getTimestamp();
	}

	public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
		$moment = \DateTime::createFromImmutable($this->at);
		if ($timezone !== null) {
			$moment->setTimezone($timezone);
		}

		return $time === 'now' ? $moment : $moment->modify($time);
	}

	public function now(): \DateTimeImmutable {
		return $this->at;
	}

	public function withTimeZone(\DateTimeZone $timezone): static {
		return new static($this->at->setTimezone($timezone));
	}

	public function getTimeZone(?string $timezone = null): \DateTimeZone {
		return new \DateTimeZone($timezone ?? 'UTC');
	}
};

$container = (new Application())->getContainer();
// Registered on the app's container, which asks the server's only for what it lacks, so every
// service the job builds reads this clock.
$container->registerService(ITimeFactory::class, static fn (): ITimeFactory => $clock);
// Never scheduled, so it has no id: start() moves no last_run, and the real hourly run keeps
// its own schedule.
$container->get(ReminderJob::class)->start(\OCP\Server::get(IJobList::class));
echo "ReminderJob ran at {$argv[1]}\n";
