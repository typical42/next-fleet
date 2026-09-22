<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCP\AppFramework\Db\DoesNotExistException;

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
	private const TYPES = [self::ODOMETER, self::TRIP, self::ENERGY, self::MAINTENANCE, self::EXPENSE];

	public const TRIP = 'trip';
	public const ODOMETER = 'odometer';
	public const ENERGY = 'energy';
	public const MAINTENANCE = 'maintenance';
	/** The Costs chip (docs/ui.md). */
	public const EXPENSE = 'expense';

	/** The kinds whose Entry can write Readings of its own, each under its `source_type`. */
	private const SOURCES = [
		self::TRIP => OdoReading::TRIP,
		self::ENERGY => OdoReading::ENERGY,
		self::MAINTENANCE => OdoReading::MAINTENANCE,
	];

	/** docs/ui.md: fifty rows, then more on scroll. Five years of a company car is thousands. */
	public const PAGE = 50;

	public function __construct(
		private TripMapper $trips,
		private OdoReadingMapper $readings,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
		private ReminderMapper $reminders,
		private VehicleService $fleet,
		private Completeness $completeness,
		private Gaps $gaps,
		private ConsumptionService $consumption,
	) {
	}

	/**
	 * Every Gap in one vehicle's logbook, for the month headers to state
	 * (docs/architecture.md#the-timeline). Not paged: a month's figure is the whole month's, and a
	 * header that grew as its rows scrolled in would state a number that is not yet true.
	 *
	 * @return list<array{trip: string, distance: int, from_at: int, from_at_off: int, to_at: int, to_at_off: int}>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function gaps(string $userId, string $vehicleUuid): array {
		return $this->gaps->of($this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid));
	}

	/**
	 * One page of one vehicle's timeline, newest first: at most PAGE rows and the cursor the next
	 * page starts at, which is null when there is no next page.
	 *
	 * A row names its kind and the moment it happened, and carries the Entry itself under that
	 * kind's key. A trip, fill-up or maintenance record carries the Readings it left on the counter
	 * as well, so the screen shows one row for them; a trip also carries the fields its
	 * jurisdiction requires that it leaves unstated, and a fill-up what it is flagged for.
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
		foreach ($wanted as $kind) {
			foreach ($this->before($kind, $vehicleId, $at, $from[$kind]) as $entry) {
				$keyed[] = $this->row($kind, $entry);
			}
		}

		usort($keyed, static fn (array $a, array $b): int => $b['key'] <=> $a['key']);
		$page = array_slice($keyed, 0, self::PAGE);
		$last = end($page);

		return [
			'rows' => $this->dressed($vehicle, array_column($page, 'row')),
			// The cursor names the kind rather than its rank: it is read back by the next request,
			// and a number would pin the ranking into every client's scroll position.
			'next' => count($keyed) > self::PAGE
				? $last['key'][0] . ':' . $last['row']['type'] . ':' . $last['key'][2]
				: null,
		];
	}

	/**
	 * One Entry as page() would show it, for the sheet that edits it: an edit that lost a race
	 * writes again under the token this carries (docs/ui.md). A Reading another Entry wrote is that
	 * Entry's and no row of its own, so it is not found as an Odometer Entry.
	 *
	 * @return array<string, mixed>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if there is no such live Entry on it
	 * @throws \InvalidArgumentException if the type is not a kind of Entry
	 * @throws \OCP\DB\Exception
	 */
	public function one(string $userId, string $vehicleUuid, string $type, string $entryUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$vehicleId = (int)$vehicle->getId();
		$entry = match ($type) {
			self::TRIP => $this->trips->findOnVehicle($vehicleId, $entryUuid),
			self::ODOMETER => $this->readings->findOnVehicle($vehicleId, $entryUuid),
			self::ENERGY => $this->energy->findOnVehicle($vehicleId, $entryUuid),
			self::MAINTENANCE => $this->maintenance->findOnVehicle($vehicleId, $entryUuid),
			self::EXPENSE => $this->expenses->findOnVehicle($vehicleId, $entryUuid),
			default => throw new \InvalidArgumentException('type is one of ' . implode(', ', self::TYPES)),
		};
		if ($entry instanceof OdoReading && $entry->getSourceType() !== OdoReading::MANUAL) {
			throw new DoesNotExistException('reading ' . $entryUuid . ' is not an Odometer Entry');
		}

		return $this->dressed($vehicle, [$this->row($type, $entry)['row']])[0];
	}

	/**
	 * One kind's share of a page: its rows strictly before the cursor, one more than a page.
	 *
	 * @return list<Trip|OdoReading|Energy|Maintenance|Expense>
	 * @throws \OCP\DB\Exception
	 */
	private function before(string $kind, int $vehicleId, int $at, int $from): array {
		$mapper = match ($kind) {
			self::TRIP => $this->trips->findBefore(...),
			self::ODOMETER => $this->readings->findEntriesBefore(...),
			self::ENERGY => $this->energy->findBefore(...),
			self::MAINTENANCE => $this->maintenance->findBefore(...),
			self::EXPENSE => $this->expenses->findBefore(...),
		};

		return $mapper($vehicleId, $at, $from, self::PAGE + 1);
	}

	/**
	 * One row, with the key it is ordered and paged by: the moment it happened, then where its kind
	 * ranks, then the row's own id. Each table names that moment its own way.
	 *
	 * @return array{key: array{int, int, int}, row: array<string, mixed>}
	 */
	private function row(string $type, Trip|OdoReading|Energy|Maintenance|Expense $entry): array {
		[$occurredAt, $offset] = match (true) {
			$entry instanceof Trip => [$entry->getStartedAt(), $entry->getStartedAtOff()],
			$entry instanceof OdoReading => [$entry->getReadAt(), $entry->getReadAtOff()],
			$entry instanceof Energy => [$entry->getFilledAt(), $entry->getFilledAtOff()],
			$entry instanceof Maintenance => [$entry->getDoneAt(), $entry->getDoneAtOff()],
			$entry instanceof Expense => [$entry->getSpentAt(), $entry->getSpentAtOff()],
		};

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
	 * Rows as the screen shows them: each with what its kind carries beside the Entry.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function dressed(Vehicle $vehicle, array $rows): array {
		return $this->withClosing((int)$vehicle->getId(), $this->withConsumption($vehicle, $this->withFlags($vehicle, $this->withMissing($vehicle, $this->withReadings((int)$vehicle->getId(), $rows)))));
	}

	/**
	 * The reminder each maintenance record on the page closed, by uuid, or null - one query for
	 * the page. The sheet that edits the record sends it back.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function withClosing(int $vehicleId, array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			if ($row['type'] === self::MAINTENANCE && $row[self::MAINTENANCE]->getReminderId() !== null) {
				$ids[] = (int)$row[self::MAINTENANCE]->getReminderId();
			}
		}
		$reminders = $this->reminders->findAnyByIds($vehicleId, $ids);

		foreach ($rows as $index => $row) {
			if ($row['type'] === self::MAINTENANCE) {
				$id = $row[self::MAINTENANCE]->getReminderId();
				$rows[$index]['closes'] = $id === null ? null : ($reminders[$id] ?? null)?->getUuid();
			}
		}

		return $rows;
	}

	/**
	 * The segment each fill-up on the page closes, or null. Measured over the vehicle's whole
	 * chain, because the fill-up that opened it may be pages away - and only when the page holds a
	 * fill-up at all.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function withConsumption(Vehicle $vehicle, array $rows): array {
		if (!in_array(self::ENERGY, array_column($rows, 'type'), true)) {
			return $rows;
		}

		$closing = array_column($this->consumption->of($vehicle), null, 'closes');
		foreach ($rows as $index => $row) {
			if ($row['type'] === self::ENERGY) {
				$rows[$index]['consumption'] = $closing[$row[self::ENERGY]->getUuid()] ?? null;
			}
		}

		return $rows;
	}

	/**
	 * The Readings each Entry on the page left on the counter, hung on its row - one query per kind
	 * rather than one per row. It is what makes an Entry and its Readings one row on screen
	 * (docs/architecture.md#odometer-rules, rule 5), flag and all.
	 *
	 * A trip leaves exactly one, as `reading`. A fill-up or maintenance record leaves one per
	 * counter it was given, none to two, as `readings`.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function withReadings(int $vehicleId, array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			if (isset(self::SOURCES[$row['type']])) {
				$ids[$row['type']][] = (int)$row[$row['type']]->getId();
			}
		}

		$bySource = [];
		foreach ($ids as $kind => $sourceIds) {
			foreach ($this->readings->findForSources($vehicleId, self::SOURCES[$kind], $sourceIds) as $reading) {
				$bySource[$kind][(int)$reading->getSourceId()][] = $reading;
			}
		}

		foreach ($rows as $index => $row) {
			$kind = $row['type'];
			if (!isset(self::SOURCES[$kind])) {
				continue;
			}
			$found = $bySource[$kind][(int)$row[$kind]->getId()] ?? [];
			if ($kind === self::TRIP) {
				$rows[$index]['reading'] = $found[0] ?? null;
			} else {
				$rows[$index]['readings'] = $found;
			}
		}

		return $rows;
	}

	/**
	 * What each fill-up on the page is flagged for, computed on read against the vehicle as it is
	 * now (EnergyService::flags()).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function withFlags(Vehicle $vehicle, array $rows): array {
		foreach ($rows as $index => $row) {
			if ($row['type'] === self::ENERGY) {
				$rows[$index]['flags'] = EnergyService::flags($vehicle, $row[self::ENERGY]);
			}
		}

		return $rows;
	}

	/**
	 * What each trip on the page leaves unstated of what its jurisdiction requires. Measured on
	 * every vehicle; the screen decides whether to say so (docs/features.md#logbook-mode).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function withMissing(Vehicle $vehicle, array $rows): array {
		foreach ($rows as $index => $row) {
			if ($row['type'] === self::TRIP) {
				$rows[$index]['missing'] = $this->completeness->missing($vehicle, $row[self::TRIP]);
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
