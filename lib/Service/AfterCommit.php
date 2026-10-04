<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * What must wait for the commit. A notification is not part of the transaction: one taken back
 * inside a write that then rolls back stays taken back, and its receipt says it was sent.
 *
 * One per request, so a service deep inside another's run defers to that run.
 */
class AfterCommit {
	use TTransactional;

	/** @var list<\Closure(): void> */
	private array $deferred = [];
	private int $depth = 0;

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Runs the work in a transaction, retried as atomicRetry() does, then what it deferred. Inside
	 * another run it is part of that one: no transaction of its own, and its deferrals wait.
	 *
	 * @template T
	 * @param \Closure(): T $work
	 * @return T
	 * @throws \Throwable whatever the work threw, after the rollback
	 */
	public function run(\Closure $work): mixed {
		if ($this->depth > 0) {
			return $work();
		}
		$this->depth++;
		try {
			$result = $this->atomicRetry(function () use ($work): mixed {
				// A retried attempt starts again: what the rolled-back one deferred never happened.
				$this->deferred = [];

				return $work();
			}, $this->db);
		} catch (\Throwable $e) {
			$this->deferred = [];
			throw $e;
		} finally {
			$this->depth--;
		}

		$deferred = $this->deferred;
		$this->deferred = [];
		foreach ($deferred as $one) {
			try {
				$one();
			} catch (\Throwable $e) {
				// The write has committed; failing the request now would only have it sent again.
				$this->logger->error('Work after a commit failed', ['exception' => $e]);
			}
		}

		return $result;
	}

	/**
	 * Runs this once the current run has committed, or at once outside any transaction.
	 *
	 * @param \Closure(): void $fn
	 * @throws \LogicException inside a transaction that is not a run's, which nothing would follow
	 */
	public function defer(\Closure $fn): void {
		if ($this->depth === 0) {
			if ($this->db->inTransaction()) {
				// At once would be the bug this class exists for; a 500 names the caller.
				throw new \LogicException('Deferred inside a transaction that is not an AfterCommit run');
			}
			$fn();

			return;
		}
		$this->deferred[] = $fn;
	}
}
