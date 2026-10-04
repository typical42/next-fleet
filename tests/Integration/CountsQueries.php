<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\IDBConnection;

/**
 * How many statements a read runs. OCP counts none, so this asks the connection behind it, which
 * every supported major keeps. A server without the counter fails the test: a skip reads as green,
 * and the bound would go unmeasured on exactly the major that changed.
 */
trait CountsQueries {
	private static function queriesOf(callable $read): int {
		$db = \OCP\Server::get(IDBConnection::class);
		$inner = method_exists($db, 'getInner') ? $db->getInner() : $db;
		if (!method_exists($inner, 'getStats')) {
			self::fail('this server counts no queries: find its counter, or measure another way');
		}
		$before = (int)$inner->getStats()['executed'];
		$read();

		return (int)$inner->getStats()['executed'] - $before;
	}
}
