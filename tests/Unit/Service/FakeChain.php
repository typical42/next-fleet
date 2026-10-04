<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The chain lookups of OdoReadingMapper over Readings held in memory, as the database answers
 * them: live rows of one counter, in `(read_at, id)` order. Integration tests hold the SQL to
 * the same answers (tests/Integration/DistanceTest.php).
 */
final class FakeChain {
	/**
	 * @param \Closure(): list<OdoReading> $rows the vehicle's Readings, read at each call
	 */
	public static function onto(OdoReadingMapper&MockObject $mapper, \Closure $rows): void {
		$chain = static function (string $counter) use ($rows): array {
			$own = array_values(array_filter(
				$rows(),
				static fn (OdoReading $reading): bool => $reading->getCounter() === $counter && $reading->getDeletedAt() === null,
			));
			usort($own, static fn (OdoReading $a, OdoReading $b): int => $a->place() <=> $b->place());

			return $own;
		};
		$standing = static fn (string $counter): array => array_values(array_filter(
			$chain($counter),
			static fn (OdoReading $reading): bool => !$reading->getFlagged(),
		));

		$mapper->method('findChain')->willReturnCallback(static fn (int $vehicleId, string $counter): array => $chain($counter));
		$mapper->method('findOfSourceType')->willReturnCallback(
			static fn (int $vehicleId, string $sourceType, string $counter): array => array_values(array_filter(
				$chain($counter),
				static fn (OdoReading $reading): bool => $reading->getSourceType() === $sourceType,
			)),
		);
		$mapper->method('findNewestStanding')->willReturnCallback(
			static function (int $vehicleId, string $counter, int $readAt, ?int $beforeId = null) use ($standing): ?OdoReading {
				$limit = [$readAt, $beforeId ?? PHP_INT_MAX];
				$found = array_filter($standing($counter), static fn (OdoReading $reading): bool => $beforeId === null
					? $reading->getReadAt() <= $readAt
					: $reading->place() < $limit);

				return $found === [] ? null : end($found);
			},
		);
		$mapper->method('findOldestStanding')->willReturnCallback(
			static function (int $vehicleId, string $counter, int $readAt) use ($standing): ?OdoReading {
				$found = array_values(array_filter($standing($counter), static fn (OdoReading $reading): bool => $reading->getReadAt() >= $readAt));

				return $found[0] ?? null;
			},
		);
		$mapper->method('findResets')->willReturnCallback(
			static fn (int $vehicleId, string $counter, int $from, int $to): array => array_values(array_filter(
				$chain($counter),
				static fn (OdoReading $reading): bool => $reading->getKind() === OdoReading::RESET
					&& $reading->getReadAt() >= $from && $reading->getReadAt() <= $to,
			)),
		);
	}
}
