<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\IDBConnection;
use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Suites hard-delete their vehicles, which no code path does, and leave the owner's row on the
 * reminder list behind: orphans `occ nextfleet:check` would report though the app never made them.
 * After each test class this removes the recipients whose vehicle is gone.
 */
final class ForgetOrphanedRecipients implements Extension {
	public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void {
		$facade->registerSubscriber(new class implements FinishedSubscriber {
			public function notify(Finished $event): void {
				if ($event->testSuite()->isForTestClass()) {
					ForgetOrphanedRecipients::forget();
				}
			}
		});
	}

	public static function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('r.id')
			->from('fleet_reminder_recipients', 'r')
			->leftJoin('r', 'fleet_vehicles', 'v', $qb->expr()->eq('v.id', 'r.vehicle_id'))
			->where($qb->expr()->isNull('v.id'));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		// Oracle takes at most 1000 values in one IN list.
		foreach (array_chunk($ids, 1000) as $chunk) {
			$delete = $db->getQueryBuilder();
			$delete->delete('fleet_reminder_recipients')
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, $delete::PARAM_INT_ARRAY)));
			$delete->executeStatement();
		}
	}
}
