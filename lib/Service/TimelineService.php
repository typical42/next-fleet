<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;

/**
 * The one timeline a vehicle has (docs/ui.md): everything that happened to it, merged into one
 * order and read a page at a time. The merge is the server's because paging is - a client that
 * asked each table for fifty rows would have to hold both to know which fifty come first.
 */
class TimelineService {
	/**
	 * The kinds of Entry a row can be, oldest-ranking first. The order is the tie-break between
	 * two rows at the same instant (docs/architecture.md#the-timeline), so entries added here go
	 * at the end - moving one would re-order pages that are already being scrolled.
	 */
	private const TYPES = [self::ODOMETER, self::TRIP];

	public const TRIP = 'trip';
	public const ODOMETER = 'odometer';

	/** docs/ui.md: fifty rows, then more on scroll. Five years of a company car is thousands. */
	public const PAGE = 50;

	public function __construct(
		private TripMapper $trips,
		private OdoReadingMapper $readings,
		private VehicleService $fleet,
	) {
	}

	/**
	 * One page of one vehicle's timeline, newest first: at most PAGE rows and the cursor the next
	 * page starts at, which is null when there is no next page.
	 *
	 * A row names its kind and the moment it happened, and carries the Entry itself under that
	 * kind's key. A trip carries the Reading it left on the counter as well, so the screen shows
	 * one row for the two.
	 *
	 * @param ?string $type one of TYPES, or null for all of them
	 * @param ?string $cursor what a previous page answered with, or null for the newest rows
	 * @return array{rows: list<array<string, mixed>>, next: ?string}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the type or the cursor is not one this route hands out
	 * @throws \OCP\DB\Exception
	 */
	public function page(string $userId, string $vehicleUuid, ?string $type, ?string $cursor): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$vehicleId = (int)$vehicle->getId();
		$wanted = $this->wanted($type);
		[$at, $from] = $this->cursor($cursor);

		// One row more than the page, from each table: it is what says whether there is a next
		// page, and it costs one row rather than a second count query.
		$keyed = [];
		if (in_array(self::TRIP, $wanted, true)) {
			foreach ($this->trips->findBefore($vehicleId, $at, $from[self::TRIP], self::PAGE + 1) as $trip) {
				$keyed[] = $this->row(self::TRIP, $trip->getStartedAt(), $trip->getStartedAtOff(), $trip);
			}
		}
		if (in_array(self::ODOMETER, $wanted, true)) {
			foreach ($this->readings->findEntriesBefore($vehicleId, $at, $from[self::ODOMETER], self::PAGE + 1) as $entry) {
				$keyed[] = $this->row(self::ODOMETER, $entry->getReadAt(), $entry->getReadAtOff(), $entry);
			}
		}

		usort($keyed, static fn (array $a, array $b): int => $b['key'] <=> $a['key']);
		$page = array_slice($keyed, 0, self::PAGE);
		$last = end($page);

		return [
			'rows' => $this->withReadings($vehicleId, array_column($page, 'row')),
			// The cursor names the kind rather than its rank: it is read back by the next request,
			// and a number would pin the ranking into every client's scroll position.
			'next' => count($keyed) > self::PAGE
				? $last['key'][0] . ':' . $last['row']['type'] . ':' . $last['key'][2]
				: null,
		];
	}

	/**
	 * One row, with the key it is ordered and paged by: the moment it happened, then where its kind
	 * ranks, then the row's own id.
	 *
	 * @return array{key: array{int, int, int}, row: array<string, mixed>}
	 */
	private function row(string $type, int $occurredAt, int $offset, Trip|OdoReading $entry): array {
		return [
			'key' => [$occurredAt, self::rank($type), (int)$entry->getId()],
			'row' => [
				'type' => $type,
				'occurred_at' => $occurredAt,
				'occurred_at_off' => $offset,
				$type => $entry,
			],
		];
	}

	/**
	 * Where a kind sorts against another at the same instant: its place in TYPES. The merge and the
	 * cursor read it from here rather than each deciding for itself - two rankings that agree by
	 * the alphabet would part company the day a kind is added, and part company silently, at a page
	 * boundary inside one instant.
	 */
	private static function rank(string $type): int {
		return (int)array_search($type, self::TYPES, true);
	}

	/**
	 * The Reading each trip on the page left on the counter, hung on its row - one query for the
	 * page rather than one per row. It is what makes a trip and its Reading one row on screen
	 * (docs/architecture.md#odometer-rules, rule 5), flag and all.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function withReadings(int $vehicleId, array $rows): array {
		$tripIds = [];
		foreach ($rows as $row) {
			if ($row['type'] === self::TRIP) {
				$tripIds[] = (int)$row[self::TRIP]->getId();
			}
		}

		$byTrip = [];
		foreach ($this->readings->findForTrips($vehicleId, $tripIds) as $reading) {
			$byTrip[(int)$reading->getSourceId()] = $reading;
		}

		foreach ($rows as $index => $row) {
			if ($row['type'] === self::TRIP) {
				$rows[$index]['reading'] = $byTrip[(int)$row[self::TRIP]->getId()] ?? null;
			}
		}

		return $rows;
	}

	/**
	 * Which kinds the chips asked for: one of them, or all of them when the filter is absent
	 * (docs/ui.md). A kind nobody serves is refused rather than answered with everything, which
	 * would look to a client like a filter that silently does nothing.
	 *
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	private function wanted(?string $type): array {
		if ($type === null || $type === '') {
			return self::TYPES;
		}
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException('type is one of ' . implode(', ', self::TYPES));
		}

		return [$type];
	}

	/**
	 * Where the next page starts, as each table has to ask for it: the instant, and the id each
	 * kind must stay under at that instant.
	 *
	 * A kind that sorts under the cursor's own at the same instant has that whole instant still to
	 * come, so it is handed an id no row reaches; a kind that sorts over it is done with that
	 * instant and is handed one no row is under. Without that, a page boundary between two rows
	 * that share an instant would drop the rows on the wrong side of it.
	 *
	 * @return array{int, array<string, int>}
	 * @throws \InvalidArgumentException if the cursor is not one this route handed out
	 */
	private function cursor(?string $cursor): array {
		if ($cursor === null || $cursor === '') {
			return [PHP_INT_MAX, array_fill_keys(self::TYPES, PHP_INT_MAX)];
		}

		$parts = explode(':', $cursor);
		if (count($parts) !== 3
			|| filter_var($parts[0], FILTER_VALIDATE_INT) === false
			|| !in_array($parts[1], self::TYPES, true)
			|| filter_var($parts[2], FILTER_VALIDATE_INT) === false) {
			throw new \InvalidArgumentException('cursor is not one this timeline handed out');
		}

		$rank = self::rank($parts[1]);
		$from = [];
		foreach (self::TYPES as $index => $type) {
			$from[$type] = match (true) {
				$index === $rank => (int)$parts[2],
				$index < $rank => PHP_INT_MAX,
				default => 0,
			};
		}

		return [(int)$parts[0], $from];
	}
}
