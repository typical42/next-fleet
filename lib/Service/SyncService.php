<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\ResponseDefinitions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Everything that changed for one client since its last call (docs/api.md#sync), without a
 * change-log table: each row's `updated_at` is the log. What that cannot see - a cache on the
 * vehicle, a vehicle reached again - is sent whole instead, which a fleet of a handful of vehicles
 * can afford.
 *
 * @psalm-import-type NextFleetSync from ResponseDefinitions
 * @psalm-type Row = OdoReading|Trip|Energy|Maintenance|Expense|Reminder|Document|Booking|Access
 */
class SyncService {
	/**
	 * The app value every erasure and transfer writes anew, at random (SyncEpoch): pseudonymised
	 * rows keep their `updated_at` (BaseMapper::pseudonymise()), so a cursor from before one cannot
	 * see them change. Random rather than counted, because two erasures that both read 4 would both
	 * write 5.
	 */
	public const EPOCH = 'sync_epoch';

	/**
	 * How far behind the clock a run's mark is. A row is stamped before its transaction commits,
	 * so one stamped in the second of a call can land after it; every row this young is sent
	 * again next time - at least once, never missed.
	 */
	public const SETTLE = 120;

	public const LIMIT = 500;
	private const MAX_LIMIT = 2000;

	/** @var array<string, BaseMapper> by SyncCursor::TABLES */
	private array $tables;

	public function __construct(
		private VehicleService $fleet,
		private OdoReadingMapper $readings,
		TripMapper $trips,
		EnergyMapper $energy,
		MaintenanceMapper $maintenance,
		ExpenseMapper $expenses,
		private ReminderMapper $reminders,
		DocumentMapper $documents,
		BookingMapper $bookings,
		AccessMapper $grants,
		private ReminderService $due,
		private DocumentService $papers,
		private BookingService $pool,
		private GrantService $access,
		private IAppConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
		$this->tables = [
			'readings' => $readings,
			'trips' => $trips,
			'energy' => $energy,
			'maintenance' => $maintenance,
			'expenses' => $expenses,
			'reminders' => $reminders,
			'documents' => $documents,
			'bookings' => $bookings,
			'grants' => $grants,
		];
	}

	/**
	 * One page of what changed since `$cursor` ('' for a client that holds nothing): every
	 * reachable vehicle in full, the rows changed after the cursor's mark - all live rows of a
	 * vehicle the client does not hold yet - and the vehicles it listed that are out of reach now.
	 *
	 * A run is the pages from one mark to the next. The mark is taken at its first page, and what
	 * a run covers is held only once its last page is answered.
	 *
	 * @return NextFleetSync
	 * @throws \InvalidArgumentException if the cursor is not one this hands out, or the limit is out of range
	 * @throws \OCP\DB\Exception
	 */
	public function sync(string $userId, string $cursor, int $limit): array {
		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new \InvalidArgumentException('limit is a whole number from 1 to ' . self::MAX_LIMIT);
		}
		$epoch = $this->config->getValueString(Application::APP_ID, self::EPOCH);
		$was = $cursor === '' ? SyncCursor::first($epoch) : SyncCursor::decode($cursor);
		$reset = $was->epoch !== $epoch;
		if ($reset) {
			$was = $was->anew($epoch);
		}

		$vehicles = $this->fleet->list($userId);
		$byId = [];
		foreach ($vehicles as $vehicle) {
			$byId[(int)$vehicle->getId()] = $vehicle;
		}
		$listed = array_map(static fn (Vehicle $vehicle): string => $vehicle->getUuid(), $vehicles);
		// A vehicle lost mid-run leaves it, so it is not held at its end: reached again, it comes whole.
		$run = $was->inRun() ? array_values(array_intersect($was->run, array_keys($byId))) : array_keys($byId);
		$mark = $was->mark ?? $this->time->getTime() - self::SETTLE;

		$page = $this->page($userId, $was, $byId, $run, $limit);
		$more = count($page) > $limit;
		$page = array_slice($page, 0, $limit);
		$last = end($page);
		$changes = $this->wired($userId, $byId, $page);

		// docs/security.md#what-is-logged: a first sync is the CSV export of every vehicle at once.
		$this->logger->info('Sync', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => 'all reachable',
			'rows' => count($page),
		]);

		return [
			'vehicles' => array_map(static fn (Vehicle $vehicle): array => $vehicle->jsonSerialize(), $vehicles),
			'changes' => $changes,
			'unreachable' => array_values(array_diff($was->listed, $listed)),
			'cursor' => ($more && $last !== false
				? $was->paged($listed, $run, $mark, [$last[0], SyncCursor::TABLES[$last[1]], $last[2]])
				: $was->finished($listed, $run, $mark))->encode(),
			'more' => $more,
			'reset' => $reset,
		];
	}

	/**
	 * Up to `$limit + 1` rows after the cursor in (`updated_at`, table, `id`) order, so one more
	 * says whether there is a next page. A merge: each table is read its share of `$limit` at a
	 * time and read again where its rows run out, so a page builds about twice its rows at most,
	 * however the changes fall across the tables (one row per table at least, for a tiny limit).
	 *
	 * @param array<int, Vehicle> $byId every reachable vehicle
	 * @param list<int> $run the vehicles this run covers
	 * @return list<array{int, int, int, Row}> `updated_at`, table rank, `id`, row
	 * @throws \OCP\DB\Exception
	 */
	private function page(string $userId, SyncCursor $was, array $byId, array $run, int $limit): array {
		$held = array_values(array_intersect($was->held, $run));
		$whole = array_values(array_diff($run, $held));
		// Grants are the owner's to read (VehicleAccess::OWN).
		$owned = array_keys(array_filter($byId, static fn (Vehicle $vehicle): bool => $vehicle->getUserId() === $userId));
		$chunk = max(1, intdiv($limit, count(SyncCursor::TABLES)));

		$read = [];
		$buffers = [];
		$full = [];
		foreach (SyncCursor::TABLES as $rank => $table) {
			[$wholeHere, $heldHere] = match ($table) {
				'grants' => [array_values(array_intersect($whole, $owned)), array_values(array_intersect($held, $owned))],
				default => [$whole, $held],
			};
			$read[$rank] = function (int $afterAt, ?int $afterId) use ($table, $wholeHere, $heldHere, $was, $chunk): array {
				/** @var list<Row> */
				return $this->tables[$table]->findChanged($wholeHere, $heldHere, $was->since, $afterAt, $afterId, $chunk);
			};
			$buffers[$rank] = $read[$rank](...$was->after($table));
			$full[$rank] = count($buffers[$rank]) === $chunk;
		}

		$found = [];
		while (count($found) <= $limit) {
			$next = null;
			$head = null;
			foreach ($buffers as $rank => $rows) {
				if ($rows === []) {
					continue;
				}
				$key = [$rows[0]->getUpdatedAt(), $rank, (int)$rows[0]->getId()];
				if ($head === null || $key < $head) {
					[$next, $head] = [$rank, $key];
				}
			}
			if ($next === null || $head === null) {
				break;
			}
			$found[] = [...$head, array_shift($buffers[$next])];
			// A table whose last read came back full may hold the next row of the whole.
			if ($buffers[$next] === [] && $full[$next]) {
				$buffers[$next] = $read[$next]($head[0], $head[2]);
				$full[$next] = count($buffers[$next]) === $chunk;
			}
		}

		return $found;
	}

	/**
	 * The page by table, each item the row's uuid, vehicle and dating, and the row in the wire form
	 * its own route answers - null for a deleted one, which is a tombstone.
	 *
	 * @param array<int, Vehicle> $byId
	 * @param list<array{int, int, int, Row}> $page
	 * @return array<string, list<array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?array<string, mixed>}>>
	 * @throws \OCP\DB\Exception
	 */
	private function wired(string $userId, array $byId, array $page): array {
		// Wired a vehicle's rows at a time: a wire form looks up what it names in one query for all.
		$live = [];
		$readings = [];
		foreach ($page as [, $rank, , $row]) {
			if ($row->getDeletedAt() === null) {
				$live[$rank][$row->getVehicleId()][] = $row;
				if ($row instanceof OdoReading) {
					$readings[] = $row;
				}
			}
		}
		// A Reading's Entry is found by id alone, so the whole page asks at once.
		$this->readings->nameSources($readings);
		$wires = [];
		foreach ($live as $rank => $byVehicle) {
			foreach ($byVehicle as $vehicleId => $rows) {
				$wired = $this->wire(SyncCursor::TABLES[$rank], $userId, $byId[$vehicleId], $rows);
				foreach ($rows as $index => $row) {
					$wires[$rank][(int)$row->getId()] = $wired[$index];
				}
			}
		}

		$changes = array_fill_keys(SyncCursor::TABLES, []);
		foreach ($page as [$updatedAt, $rank, $id, $row]) {
			$changes[SyncCursor::TABLES[$rank]][] = [
				'vehicle_uuid' => $byId[$row->getVehicleId()]->getUuid(),
				'uuid' => $row->getUuid(),
				'updated_at' => $updatedAt,
				'deleted_at' => $row->getDeletedAt(),
				'row' => $wires[$rank][$id] ?? null,
			];
		}

		return $changes;
	}

	/**
	 * Live rows of one table on one vehicle, in the order given.
	 *
	 * @param non-empty-list<Row> $rows
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function wire(string $table, string $userId, Vehicle $vehicle, array $rows): array {
		return match ($table) {
			'energy' => array_map(static fn (Energy $energy): array => EnergyService::wire($vehicle, $energy), self::only(Energy::class, $rows)),
			'maintenance' => $this->maintenance($vehicle, self::only(Maintenance::class, $rows)),
			'reminders' => $this->due->wired($vehicle, self::only(Reminder::class, $rows)),
			'documents' => $this->papers->wired($userId, $vehicle, self::only(Document::class, $rows)),
			'bookings' => $this->pool->wired($userId, $vehicle, self::only(Booking::class, $rows)),
			'grants' => $this->access->wired(self::only(Access::class, $rows)),
			// The rest answer their entity as it serialises itself.
			default => array_map(static fn (\JsonSerializable $row): array => (array)$row->jsonSerialize(), self::only(\JsonSerializable::class, $rows)),
		};
	}

	/**
	 * The rows as the entity their table holds, which they all are: a wire form takes no other.
	 *
	 * @template E of object
	 * @param class-string<E> $class
	 * @param list<Row> $rows
	 * @return list<E>
	 */
	private static function only(string $class, array $rows): array {
		$only = [];
		foreach ($rows as $row) {
			if ($row instanceof $class) {
				$only[] = $row;
			}
		}

		return $only;
	}

	/**
	 * Maintenance Records with the reminders they close, looked up at once, deleted ones included.
	 *
	 * @param list<Maintenance> $records
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function maintenance(Vehicle $vehicle, array $records): array {
		$ids = [];
		foreach ($records as $record) {
			$id = $record->getReminderId();
			if ($id !== null) {
				$ids[] = $id;
			}
		}
		$closes = $this->reminders->findAnyByIds((int)$vehicle->getId(), $ids);

		return array_map(static fn (Maintenance $record): array => MaintenanceService::wire($record, $closes[$record->getReminderId() ?? 0] ?? null), $records);
	}
}
