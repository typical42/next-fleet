<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Work an event starts and nothing would start again: an account's erasure, a deleted group's
 * revokes. Each is marked before it begins and unmarked once done, so a run a crash or a database
 * error cut short is still marked, and PendingJob finishes it.
 */
class Pending {
	public const ERASURE = 'erasure';
	public const GROUP = 'group';

	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Marks the work, or keeps the mark a cut-short run left: its time tells what the work found
	 * from what came after it.
	 *
	 * @param self::ERASURE|self::GROUP $kind
	 */
	public function begin(string $kind, string $id): void {
		$key = $this->key($kind, $id);
		if ($this->config->hasKey(Application::APP_ID, $key, lazy: true)) {
			return;
		}
		// Lazy: no request but PendingJob's reads it, and getAllValues() loads lazy keys too.
		$this->config->setValueString(Application::APP_ID, $key, json_encode(['id' => $id, 'since' => $this->time->getTime()], JSON_THROW_ON_ERROR), lazy: true);
	}

	/** @param self::ERASURE|self::GROUP $kind */
	public function marks(string $kind, string $id): bool {
		return $this->config->hasKey(Application::APP_ID, $this->key($kind, $id), lazy: true);
	}

	/** @param self::ERASURE|self::GROUP $kind */
	public function end(string $kind, string $id): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($kind, $id));
	}

	/**
	 * @param self::ERASURE|self::GROUP $kind
	 * @return list<array{id: string, since: int}> the uids or gids marked, and when
	 */
	public function of(string $kind): array {
		$marked = [];
		foreach ($this->config->getAllValues(Application::APP_ID, 'pending_' . $kind . '_') as $value) {
			$mark = json_decode((string)$value, true);
			if (is_array($mark) && is_string($mark['id'] ?? null) && is_int($mark['since'] ?? null)) {
				$marked[] = ['id' => $mark['id'], 'since' => $mark['since']];
			}
		}

		return $marked;
	}

	/** By a hash: the key column holds 64 characters, and a uid may take as many. */
	private function key(string $kind, string $id): string {
		return 'pending_' . $kind . '_' . md5($id);
	}
}
