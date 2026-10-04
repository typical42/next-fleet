<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

trait SharingRules {
	/**
	 * Runs `$test` under core's sharing settings, then puts back what the instance had: the test
	 * servers are dev servers, and their admin's settings are not ours to reset.
	 *
	 * @param array<string, string> $values
	 */
	private function underSharingRules(array $values, \Closure $test): void {
		$before = [];
		foreach ($values as $key => $value) {
			$before[$key] = $this->coreValue($key);
			$this->writeCoreValue($key, $value);
		}
		try {
			$test();
		} finally {
			foreach ($before as $key => $value) {
				$this->writeCoreValue($key, $value);
			}
		}
	}

	/** @return ?string null when the instance has no such row */
	private function coreValue(string $key): ?string {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$result = $qb->select('configvalue')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('core')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false ? null : (string)$value;
	}

	/**
	 * Written to the row, as RecipientTest::writeInstalledVersion() does and for its reason: the
	 * typed setters retype a key core keeps untyped, and IConfig's untyped ones are deprecated. A
	 * row that exists keeps its type; a new one is untyped, as core writes it.
	 */
	private function writeCoreValue(string $key, ?string $value): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$here = $qb->expr()->andX(
			$qb->expr()->eq('appid', $qb->createNamedParameter('core')),
			$qb->expr()->eq('configkey', $qb->createNamedParameter($key)),
		);
		if ($value === null) {
			$qb->delete('appconfig')->where($here)->executeStatement();
		} elseif ($this->coreValue($key) === null) {
			$qb->insert('appconfig')->values([
				'appid' => $qb->createNamedParameter('core'),
				'configkey' => $qb->createNamedParameter($key),
				'configvalue' => $qb->createNamedParameter($value),
				'type' => $qb->createNamedParameter(IAppConfig::VALUE_MIXED, IQueryBuilder::PARAM_INT),
				'lazy' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} else {
			$qb->update('appconfig')->set('configvalue', $qb->createNamedParameter($value))->where($here)->executeStatement();
		}
		\OCP\Server::get(IAppConfig::class)->clearCache();
	}
}
