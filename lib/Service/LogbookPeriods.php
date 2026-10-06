<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Vehicle;

/**
 * When a vehicle's Logbook Mode was on, read off the flips on its trail
 * (docs/features.md#logbook-mode). One reading for the export that states the periods and the trip
 * trail that is kept for every trip set off inside one: two readings could disagree about which
 * trips the logbook vouches for.
 */
class LogbookPeriods {
	public function __construct(
		private AuditMapper $audit,
	) {
	}

	/**
	 * Every period, oldest first; `to` is null while the mode is still on.
	 *
	 * Where the first period begins is not a row: the first flip's `before` says whether the vehicle
	 * was created under the mode, and a vehicle never switched is under it since its creation exactly
	 * when its column says so. The instants are the server's and carry no offset.
	 *
	 * @return list<array{from: int, to: ?int}>
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle): array {
		$flips = array_map(
			static fn (array $change): array => [$change[0] === true, $change[1] === true, $change[2]],
			$this->changes($vehicle, 'logbook_mode'),
		);

		$on = $flips === [] ? $vehicle->getLogbookMode() === true : $flips[0][0];
		$from = $on ? $vehicle->getCreatedAt() : null;
		$periods = [];
		foreach ($flips as [, $after, $at]) {
			if ($after && $from === null) {
				$from = $at;
			} elseif (!$after && $from !== null) {
				$periods[] = ['from' => $from, 'to' => $at];
				$from = null;
			}
		}
		if ($from !== null) {
			$periods[] = ['from' => $from, 'to' => null];
		}

		return $periods;
	}

	/**
	 * The plates the vehicle carried, oldest first, each with the server instant it took over. Read
	 * off the same trail: the first row that changed it says what it was before, and a vehicle never
	 * renamed has carried today's since it was created.
	 *
	 * @return non-empty-list<array{plate: ?string, from: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function plates(Vehicle $vehicle): array {
		$plates = [];
		foreach ($this->changes($vehicle, 'plate') as [$before, $after, $at]) {
			if ($plates === []) {
				// The plate before every change, so it starts no later than the first one, whatever
				// `created_at` says.
				$plates[] = ['plate' => self::plate($before), 'from' => min($vehicle->getCreatedAt(), $at)];
			}
			$plates[] = ['plate' => self::plate($after), 'from' => $at];
		}

		return $plates === [] ? [['plate' => $vehicle->getPlate(), 'from' => $vehicle->getCreatedAt()]] : $plates;
	}

	private static function plate(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/**
	 * Each change of one column on the vehicle's trail, oldest first, as `[before, after, instant]`.
	 *
	 * @return list<array{mixed, mixed, int}>
	 * @throws \OCP\DB\Exception
	 */
	private function changes(Vehicle $vehicle, string $column): array {
		$changes = [];
		foreach ($this->audit->findForEntity(Audit::VEHICLE, (int)$vehicle->getId()) as $row) {
			$pair = $row->getDiffJson()['fields'][$column] ?? null;
			if (is_array($pair) && count($pair) === 2) {
				$changes[] = [$pair[0], $pair[1], $row->getCreatedAt()];
			}
		}

		return $changes;
	}

	/**
	 * The plates `plates()` gives that were on the vehicle at some point of `$period`, the first
	 * starting no earlier than the period does.
	 *
	 * @param non-empty-list<array{plate: ?string, from: int}> $plates
	 * @param array{from: int, to: ?int} $period
	 * @return list<array{plate: ?string, from: int}>
	 */
	public static function within(array $plates, array $period): array {
		$within = [];
		foreach ($plates as $i => $plate) {
			$until = $plates[$i + 1]['from'] ?? null;
			if (($until !== null && $until <= $period['from']) || ($period['to'] !== null && $plate['from'] >= $period['to'])) {
				continue;
			}
			$within[] = ['plate' => $plate['plate'], 'from' => max($plate['from'], $period['from'])];
		}

		return $within;
	}

	/**
	 * The period a trip that set off at `$startedAt` set off under - from the flip on, up to but not
	 * at the flip off - or null where it set off outside every one.
	 *
	 * @template P of array{from: int, to: ?int, ...<string, mixed>}
	 * @param list<P> $periods
	 * @return ?P
	 */
	public static function covering(array $periods, int $startedAt): ?array {
		foreach ($periods as $period) {
			if ($startedAt >= $period['from'] && ($period['to'] === null || $startedAt < $period['to'])) {
				return $period;
			}
		}

		return null;
	}
}
