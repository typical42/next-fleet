<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\AfterCommit;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Work deferred inside a transaction waits for its commit, and goes with its rollback.
 */
class AfterCommitTest extends TestCase {
	/** @var list<string> */
	private array $events = [];
	private SpyLogger $logger;
	private AfterCommit $after;

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->events[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->events[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->events[] = 'rollback';
		});
		$this->logger = new SpyLogger();
		$this->after = new AfterCommit($db, $this->logger);
	}

	public function testDeferredWorkRunsAfterTheCommit(): void {
		$result = $this->after->run(function (): string {
			$this->after->defer(function (): void {
				$this->events[] = 'withdraw';
			});
			$this->events[] = 'write';

			return 'written';
		});

		$this->assertSame('written', $result);
		$this->assertSame(['begin', 'write', 'commit', 'withdraw'], $this->events);
	}

	public function testDeferredWorkGoesWithTheRollback(): void {
		try {
			$this->after->run(function (): void {
				$this->after->defer(function (): void {
					$this->events[] = 'withdraw';
				});
				throw new \InvalidArgumentException('refused');
			});
			$this->fail('the run did not throw');
		} catch (\InvalidArgumentException) {
		}

		$this->assertSame(['begin', 'rollback'], $this->events);
		// Nor does it run with the next transaction.
		$this->after->run(static fn (): null => null);
		$this->assertSame(['begin', 'rollback', 'begin', 'commit'], $this->events);
	}

	/** A run inside another is part of it: one transaction, and its work waits for the outer commit. */
	public function testAnInnerRunWaitsForTheOuterCommit(): void {
		$this->after->run(function (): void {
			$this->after->run(function (): void {
				$this->after->defer(function (): void {
					$this->events[] = 'withdraw';
				});
			});
			$this->events[] = 'inner done';
		});

		$this->assertSame(['begin', 'inner done', 'commit', 'withdraw'], $this->events);
	}

	/** The write has committed: a failed withdrawal is logged, and the others still run. */
	public function testAFailedDeferralIsLoggedAndTheRestRun(): void {
		$this->after->run(function (): void {
			$this->after->defer(static function (): void {
				throw new \Error('notifications are down');
			});
			$this->after->defer(function (): void {
				$this->events[] = 'withdraw';
			});
		});

		$this->assertSame(['begin', 'commit', 'withdraw'], $this->events);
		$this->assertSame([LogLevel::ERROR], array_column($this->logger->lines, 'level'));
	}

	/** A transaction no run owns has nobody to run the work after it: a caller that forgot run(). */
	public function testATransactionWithoutARunIsRefused(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$after = new AfterCommit($db, $this->logger);

		$this->expectException(\LogicException::class);
		$after->defer(static function (): void {
		});
	}

	/** Outside a run there is no transaction to wait for. */
	public function testOutsideARunItRunsAtOnce(): void {
		$this->after->defer(function (): void {
			$this->events[] = 'withdraw';
		});

		$this->assertSame(['withdraw'], $this->events);
	}
}
