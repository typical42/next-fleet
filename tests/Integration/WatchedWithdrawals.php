<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\IDBConnection;
use OCP\Notification\IApp;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

/**
 * A notification app beside the real one, telling whether each withdrawal ran inside a
 * transaction. The notifications app writes through the same connection, so its rows roll back
 * with a refused write; the push that deleted the notice from the phone does not.
 */
final class WatchedWithdrawals implements IApp {
	/** @var ?list<bool> null while nobody watches */
	private static ?array $seen = null;
	private static bool $registered = false;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public static function watch(): void {
		if (!self::$registered) {
			\OCP\Server::get(IManager::class)->registerApp(self::class);
			self::$registered = true;
		}
		self::$seen = [];
	}

	/** @return list<bool> for each withdrawal since watch(), whether a transaction was open */
	public static function stop(): array {
		$seen = self::$seen ?? [];
		self::$seen = null;

		return $seen;
	}

	public function notify(INotification $notification): void {
	}

	public function markProcessed(INotification $notification): void {
		if (self::$seen !== null) {
			self::$seen[] = $this->db->inTransaction();
		}
	}

	public function getCount(INotification $notification): int {
		return 0;
	}
}
