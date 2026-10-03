<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

/**
 * A client's sync (docs/api.md#sync), for a test case that holds its server in `self::$server`.
 */
trait Syncing {
	/** @return array<string, mixed> one whole sync, every page of it */
	private function sync(Account $as, ?string $cursor = null): array {
		$answer = self::$server->ocs($as, 'GET', '/sync', $cursor === null ? [] : ['cursor' => $cursor]);
		$this->assertSame(200, $answer->status, $answer->body);
		$sync = $answer->data();
		$this->assertFalse($sync['more'], 'a fresh account fits on one page');

		return $sync;
	}

	/**
	 * @param array<string, mixed> $sync
	 * @return array<string, mixed> the row's item in it
	 */
	private function synced(array $sync, string $table, string $uuid): array {
		$items = array_values(array_filter($sync['changes'][$table], static fn (array $item): bool => $item['uuid'] === $uuid));
		$this->assertCount(1, $items, "$table $uuid is not in this sync");

		return $items[0];
	}
}
