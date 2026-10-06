<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Everything a trip is written under, so the controller carries none of it. What the journey did
 * to the counter is not written here: the Reading is OdometerService's, in the one place that
 * writes one (docs/architecture.md#odometer-rules).
 */
class TripService {
	use TTransactional;

	/** CONTEXT.md's vocabulary, and the split the Fahrtenbuch is built on. */
	private const CATEGORIES = [Trip::BUSINESS, Trip::PRIVATE, Trip::COMMUTE];

	/**
	 * The columns a request may set, each with the setter it reaches and what it has to look
	 * like - the shape VehicleService::WRITABLE has, for the same reason.
	 *
	 * `reconciled` is missing on purpose: it marks a Reconciliation Trip, one the app created to
	 * close a Gap (CONTEXT.md), and a client that could set it could dress a hand-typed trip as
	 * one.
	 *
	 * @var array<string, array{string, string, int|list<string>|null}>
	 */
	private const WRITABLE = [
		'started_at' => ['setStartedAt', 'count', Field::MOMENT],
		'started_at_off' => ['setStartedAtOff', 'offset', null],
		'ended_at' => ['setEndedAt', 'count', Field::MOMENT],
		'ended_at_off' => ['setEndedAtOff', 'offset', null],
		'start_odo' => ['setStartOdo', 'count', Field::COUNTER],
		'end_odo' => ['setEndOdo', 'count', Field::COUNTER],
		'distance' => ['setDistance', 'count', Field::COUNTER],
		'from_label' => ['setFromLabel', 'text', 255],
		'to_label' => ['setToLabel', 'text', 255],
		'purpose' => ['setPurpose', 'text', 255],
		'partner' => ['setPartner', 'text', 255],
		'category' => ['setCategory', 'word', self::CATEGORIES],
	];

	/**
	 * The columns the database will not default. The sheet prefills every one of them (docs/ui.md),
	 * so a request without one is a client that did not, not a driver who left a field empty - the
	 * fields a driver leaves empty are flagged, never refused (docs/features.md#logbook-mode).
	 */
	private const REQUIRED = ['started_at', 'started_at_off', 'ended_at', 'ended_at_off', 'category'];

	/**
	 * What kind of change the audit row records. It is a key in `diff_json` and not a column,
	 * because the trail has to describe changes to tables that do not exist yet
	 * (docs/architecture.md#data-model).
	 */
	private const CREATED = 'created';
	private const EDITED = 'edited';
	private const VOIDED = 'voided';
	private const RESTORED = 'restored';

	/** What an audit row does not restate: it carries its own author, instant and identity. */
	private const BOOKKEEPING = ['uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by'];

	/**
	 * How far back prefill() looks: a year of a vehicle driven a few times a day. Larger than the
	 * station's, because a logbook has more trips than fill-ups by that factor.
	 */
	private const TRIP_HISTORY = 1000;

	/** The refusals the sheet words itself (RefusedException). */
	public const END_BELOW_START = 'end_below_start';
	public const ENDS_IN_FUTURE = 'ends_in_future';

	/** How far ahead of the server's clock an arrival may lie, in seconds. */
	private const FUTURE_SLACK = 86400;

	public function __construct(
		private TripMapper $trips,
		private AuditMapper $audit,
		private OdometerService $odometer,
		private VehicleService $fleet,
		private Jurisdictions $jurisdictions,
		private ITimeFactory $time,
		private Gaps $gaps,
		private VehicleMapper $vehicles,
		private BookingService $bookings,
		private IDBConnection $db,
		private LogbookPeriods $periods,
	) {
	}

	/**
	 * Writes one trip and the Reading it left on the counter, and ties it to the booking it was
	 * logged from when `booking_uuid` names one (BookingService::tie()).
	 *
	 * @param array<string, mixed> $fields
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle,
	 *                                                        or the booking is not theirs to log
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\BookingConflictException if the booking is not back or has its trip
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->trips,
			static fn (Trip $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			static fn (Trip $row): Trip => $row,
		);

		return $once->run(fn (): Trip => $this->insert($userId, $vehicle, $fields, $once));
	}

	/**
	 * What record() writes once it knows the request is no retry.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Trip> $once
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\BookingConflictException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, Vehicle $vehicle, array $fields, Once $once): Trip {
		$trip = new Trip();
		$once->stamp($trip);
		$trip->setVehicleId((int)$vehicle->getId());
		$trip->setCreatedBy($userId);
		$this->apply($trip, $fields);
		$booking = Field::read('booking_uuid', 'text', 64, $fields['booking_uuid'] ?? null);

		// The two rows are one fact: a trip without its Reading is a logbook that disagrees with
		// its counter, and nothing later can tell which is wrong.
		//
		// Retried: a deadlock the hold does not order, with a write from outside this service,
		// would otherwise be a 500 that loses the trip. The replay inserts the id the rolled-back
		// attempt was given, which auto-increment never hands out again.
		return $this->atomicRetry(function () use ($userId, $vehicle, $trip, $booking, $once): Trip {
			// First: the Reading settles the vehicle's whole chain, so a second writer on it has
			// to wait and settle on this one's (VehicleMapper::hold()).
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();
			$written = $this->trips->insert($trip);
			$this->trail($vehicle, $written, $userId, self::CREATED, $this->stated($written));
			$this->odometer->fromTrip($vehicle, $written);
			if (is_string($booking)) {
				$this->bookings->tie($userId, $vehicle, $booking, $written);
			}

			return $written;
		}, $this->db);
	}

	/**
	 * What the sheet's trip fields complete from (docs/ui.md): the places, purposes and partners of
	 * this vehicle's own trips, each once and the latest first. Starts and destinations are one
	 * list, because where a trip ended is where the next sets off. Asked with LOG, as the trip it
	 * fills in is.
	 *
	 * Beside the words: the category this person last entered here, and how the vehicle's last trip
	 * ended - its stated end counter (none for one logged by distance) and its arrival.
	 *
	 * @return array{places: list<string>, purposes: list<string>, partners: list<string>, category: ?string, last: ?array{end_odo: ?int, ended_at: int, ended_at_off: int}}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function prefill(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		$places = $purposes = $partners = [];
		foreach ($this->trips->findLatestDescribed((int)$vehicle->getId(), self::TRIP_HISTORY) as $trip) {
			// The destination first: it is the later of the two places on the same trip.
			array_push($places, $trip->getToLabel(), $trip->getFromLabel());
			$purposes[] = $trip->getPurpose();
			$partners[] = $trip->getPartner();
		}

		$last = $this->trips->findLatest((int)$vehicle->getId());

		return [
			'places' => self::distinct($places),
			'purposes' => self::distinct($purposes),
			'partners' => self::distinct($partners),
			'category' => $this->trips->findLatestEnteredBy((int)$vehicle->getId(), $userId)?->getCategory(),
			'last' => $last === null ? null : [
				'end_odo' => $last->getEndOdo(),
				'ended_at' => $last->getEndedAt(),
				'ended_at_off' => $last->getEndedAtOff(),
			],
		];
	}

	/**
	 * @param list<?string> $words
	 * @return list<string> each word once, in the order first given, without the empty ones
	 */
	private static function distinct(array $words): array {
		return array_values(array_unique(array_filter($words, static fn (?string $word): bool => $word !== null)));
	}

	/**
	 * Closes one Gap as one Reconciliation Trip (CONTEXT.md): private, over the Gap's kilometres,
	 * from the Reading it was measured against to the start of the trip that claimed it. A
	 * distance, not a counter, so its Reading is counted from that Reading and lands on the claim
	 * (rule 6).
	 *
	 * The Gap is named by the trip that opened it and found again here, not taken from the
	 * request: a Gap that moved since the driver saw it is not the one they confirmed.
	 *
	 * Adding a trip, so LOG: whoever closes the Gap is who entered it.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws StaleUpdateException if the vehicle has no such Gap any more
	 * @throws \OCP\DB\Exception
	 */
	public function reconcile(string $userId, string $vehicleUuid, string $tripUuid, int $distance, int $fromAt, int $toAt): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $distance, $fromAt, $toAt): Trip {
			// A second confirmation of the same Gap - another tab, another driver - waits here for
			// this one to commit, and then finds nothing left to close.
			$this->vehicles->hold((int)$vehicle->getId());
			$gap = $this->gap($vehicle, $tripUuid, $distance, $fromAt, $toAt);

			$trip = new Trip();
			$trip->setVehicleId((int)$vehicle->getId());
			$trip->setCreatedBy($userId);
			$trip->setCategory(Trip::PRIVATE);
			$trip->setStartedAt($gap['from_at']);
			$trip->setStartedAtOff($gap['from_at_off']);
			$trip->setEndedAt($gap['to_at']);
			$trip->setEndedAtOff($gap['to_at_off']);
			$trip->setDistance($gap['distance']);
			$trip->setReconciled(true);

			$written = $this->trips->insert($trip);
			// Nobody drove this and wrote it down: the kilometres are what the counter says went
			// unrecorded, and an auditor reading the trail has to be able to tell the two apart.
			$this->trail($vehicle, $written, $userId, self::CREATED, $this->stated($written), ['derived' => true]);
			$this->odometer->fromTrip($vehicle, $written);

			return $written;
		}, $this->db);
	}

	/**
	 * The Gap the driver confirmed, as the vehicle has it now.
	 *
	 * @return array{trip: string, distance: int, from_at: int, from_at_off: int, to_at: int, to_at_off: int}
	 * @throws StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function gap(Vehicle $vehicle, string $tripUuid, int $distance, int $fromAt, int $toAt): array {
		foreach ($this->gaps->of($vehicle) as $gap) {
			if ($gap['trip'] === $tripUuid && $gap['distance'] === $distance && $gap['from_at'] === $fromAt && $gap['to_at'] === $toAt) {
				return $gap;
			}
		}

		throw new StaleUpdateException('no gap of ' . $distance . ' before trip ' . $tripUuid);
	}

	/**
	 * Rewrites one trip in place, and the Reading with it when the journey moved. Append-only is the
	 * audit row, not a second trip row (docs/features.md#logbook-mode): the diff is the revision.
	 *
	 * The request is the whole trip, as `record()` takes it: a field it leaves out is one the
	 * driver emptied. Keeping it, as a vehicle's update does, would leave no way to turn a distance
	 * trip into a counter trip.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this trip
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the trip has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $tripUuid, int $expectedUpdatedAt, array $fields): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		// Looked up inside the transaction for the reason delete() gives.
		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $expectedUpdatedAt, $fields): Trip {
			$this->vehicles->hold((int)$vehicle->getId());
			$trip = $this->on($vehicle, $this->trips->findByUuid($tripUuid));
			$this->fleet->change($userId, VehicleAccess::EDIT, $vehicle, $trip->getCreatedBy());
			$was = clone $trip;
			$this->apply($trip, $fields);

			// A save that changed nothing is no revision and writes nothing: a token moved without
			// an audit row is what the export prints as a change nobody recorded. Still checked.
			$changed = $this->changed($was, $trip);
			if ($changed === []) {
				if ($was->getUpdatedAt() !== $expectedUpdatedAt) {
					throw new StaleUpdateException('trip ' . $tripUuid . ' is not the row that was read');
				}

				return $was;
			}

			$edited = $this->trips->updateChecked($trip, $expectedUpdatedAt);
			$this->trail($vehicle, $edited, $userId, self::EDITED, $changed, [
				'late' => $this->late($vehicle, $was, $edited),
			], $was);
			$this->odometer->followEdit($vehicle, $was, $edited);

			return $edited;
		}, $this->db);
	}

	/**
	 * Whether the change arrives after the ruleset's lock delay (`ILogbookRules::lockDelayDays()`).
	 * It is allowed either way; the trail says which. A void or a restore moves no journey, so it
	 * passes the trip as both.
	 *
	 * The delay runs from the earlier of the two ends, as recorded or as restated: an edit that
	 * re-dates an old trip to yesterday would otherwise restart the clock. A day is 86 400 seconds
	 * of the server's clock, not the driver's calendar day.
	 */
	private function late(Vehicle $vehicle, Trip $was, Trip $trip): bool {
		$rules = $this->jurisdictions->get($vehicle->getJurisdiction())->logbookRules();
		if ($rules === null) {
			return false;
		}

		$ended = min($was->getEndedAt(), $trip->getEndedAt());

		return $this->time->getTime() > $ended + $rules->lockDelayDays() * 86400;
	}

	/**
	 * Voids one trip: the row survives with `deleted_at` stamped on it
	 * (docs/features.md#logbook-mode), which is what the trash, the undo and the export read it
	 * back through.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not void this trip
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the trip has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $tripUuid, int $expectedUpdatedAt): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		// Looked up inside the transaction: a replay after a deadlock has to start from the row as
		// the database has it, not from an entity a rolled-back statement already stamped.
		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $expectedUpdatedAt): Trip {
			$this->vehicles->hold((int)$vehicle->getId());
			$trip = $this->on($vehicle, $this->trips->findByUuid($tripUuid));
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $trip->getCreatedBy());
			$voided = $this->trips->softDelete($trip, $expectedUpdatedAt);
			$this->trail($vehicle, $voided, $userId, self::VOIDED, [
				'deleted_at' => [null, $voided->getDeletedAt()],
			], ['late' => $this->late($vehicle, $voided, $voided)]);
			$this->odometer->followTrip($vehicle, $voided);

			return $voided;
		}, $this->db);
	}

	/**
	 * Undo, and it takes the right the void took. The lookup ignores `deleted_at` - the row it is
	 * after is precisely the one a live read passes over - and the token is the one the void
	 * answered with, which is the one the undo toast holds (docs/architecture.md#concurrency).
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the void answered with
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not void this trip
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the trip has changed since, or was never voided
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $tripUuid, int $expectedUpdatedAt): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $expectedUpdatedAt): Trip {
			$this->vehicles->hold((int)$vehicle->getId());
			$voided = $this->on($vehicle, $this->trips->findAnyByUuid($tripUuid));
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $voided->getCreatedBy());
			$stamp = $voided->getDeletedAt();
			$back = $this->trips->restoreChecked($voided, $expectedUpdatedAt);
			$this->trail($vehicle, $back, $userId, self::RESTORED, ['deleted_at' => [$stamp, null]], [
				'late' => $this->late($vehicle, $back, $back),
			]);
			$this->odometer->followTrip($vehicle, $back);

			return $back;
		}, $this->db);
	}

	/**
	 * The trip, where it hangs off the vehicle the route named. The gate upstream only asked about
	 * the vehicle, so a uuid alone would reach a journey in anybody else's logbook.
	 *
	 * Not found rather than refused: an answer that told them apart would tell which uuids exist.
	 *
	 * @throws DoesNotExistException
	 */
	private function on(Vehicle $vehicle, Trip $trip): Trip {
		if ($trip->getVehicleId() !== (int)$vehicle->getId()) {
			throw new DoesNotExistException('trip ' . $trip->getUuid() . ' is not on this vehicle');
		}

		return $trip;
	}

	/**
	 * The audit row the write leaves behind, under Logbook Mode or about a trip that set off -
	 * before or after the change - inside a period the mode was on: the trail follows the trip, not
	 * the switch (docs/features.md#logbook-mode). It carries the token the change left the trip
	 * with (docs/architecture.md#data-model).
	 *
	 * Inside the caller's transaction on purpose: a change without its row, or a row about a
	 * change that rolled back, is a trail that disagrees with the logbook it describes.
	 *
	 * The vehicle is the snapshot the gate handed back, not a second read. A mode flip racing this
	 * write is recorded on the vehicle with the instant it took effect, which places the trip.
	 *
	 * The author is whoever made the change, never the trip's `created_by`.
	 *
	 * @param array<string, array{mixed, mixed}> $fields each changed column as `[before, after]`
	 * @param array<string, mixed> $carried what the change itself carried, such as that it was late
	 * @param ?Trip $was the trip before an edit, which may have set off somewhere else
	 * @throws \OCP\DB\Exception
	 */
	private function trail(Vehicle $vehicle, Trip $trip, string $userId, string $change, array $fields, array $carried = [], ?Trip $was = null): void {
		if (!$this->kept($vehicle, $trip, $was)) {
			return;
		}

		$row = new Audit();
		$row->setCreatedBy($userId);
		$row->setEntity(Audit::TRIP);
		$row->setEntityId((int)$trip->getId());
		$row->setDiffJson(['change' => $change, 'fields' => $fields] + $carried + ['updated_at' => $trip->getUpdatedAt()]);
		$this->audit->insert($row);
	}

	/** Whether the trip, as it is or as it was before an edit, is in a logbook somebody keeps or kept. */
	private function kept(Vehicle $vehicle, Trip $trip, ?Trip $was): bool {
		if ($vehicle->getLogbookMode() === true) {
			return true;
		}

		$periods = $this->periods->of($vehicle);

		return LogbookPeriods::covering($periods, $trip->getStartedAt()) !== null
			|| ($was !== null && LogbookPeriods::covering($periods, $was->getStartedAt()) !== null);
	}

	/**
	 * The columns an edit changed, each as `[before, after]`, in the vocabulary `stated()` reads.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	private function changed(Trip $was, Trip $trip): array {
		$before = array_diff_key($was->jsonSerialize(), array_flip(self::BOOKKEEPING));
		$after = array_diff_key($trip->jsonSerialize(), array_flip(self::BOOKKEEPING));

		$changed = [];
		foreach ($after as $column => $value) {
			if ($before[$column] !== $value) {
				$changed[$column] = [$before[$column], $value];
			}
		}

		return $changed;
	}

	/**
	 * What the trip says, each field as the pair `[before, after]` an auditor reads a diff as. A
	 * creation has nothing before it, so every pair starts at null.
	 *
	 * A field the driver left empty is not in the diff: listing it would bury the fields that were
	 * set. The row's identity, author and dating are left out; the audit row carries them
	 * (docs/architecture.md#data-model).
	 *
	 * The columns are read off the wire form, where this app spells them out once;
	 * `testTheAuditRowNamesEveryColumnATripCarries` keeps the trail's vocabulary the client's.
	 *
	 * @return array<string, array{null, mixed}>
	 */
	private function stated(Trip $trip): array {
		$fields = array_diff_key($trip->jsonSerialize(), array_flip(self::BOOKKEEPING));
		// The only column that is false rather than absent when nobody touched it. A trip nobody
		// reconciled states nothing; every other false would be a fact and stays in.
		if ($trip->getReconciled() === false) {
			unset($fields['reconciled']);
		}
		$stated = array_filter($fields, static fn (mixed $value): bool => $value !== null);

		return array_map(static fn (mixed $value): array => [null, $value], $stated);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 */
	private function apply(Trip $trip, array $fields): void {
		foreach (self::WRITABLE as $column => [$setter, $kind, $limit]) {
			$value = Field::read($column, $kind, $limit, $fields[$column] ?? null);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				throw new \InvalidArgumentException($column . ' is a field every trip carries');
			}

			// A null is written too: on an edit it is a field the driver emptied (update()).
			$trip->$setter($value);
		}

		// Rule 5 gives the Reading the moment the journey ended, so a trip that ended before it
		// began would date the counter earlier than the drive that moved it.
		if ($trip->getEndedAt() < $trip->getStartedAt()) {
			throw new \InvalidArgumentException('ended_at is not before started_at');
		}
		// The sheet toggles between the end counter and the kilometres (docs/ui.md). With neither
		// the counter learns nothing; with both, preferring one would throw away a number the
		// other contradicts, against rule 6.
		if ($trip->getEndOdo() === null && $trip->getDistance() === null) {
			throw new \InvalidArgumentException('a trip carries end_odo or distance');
		}
		if ($trip->getEndOdo() !== null && $trip->getDistance() !== null) {
			throw new \InvalidArgumentException('a trip is a counter or a distance, not both');
		}
		// A negative trip would lower every sum it lands in, the claim's first among them.
		if ($trip->getStartOdo() !== null && $trip->getEndOdo() !== null && $trip->getEndOdo() < $trip->getStartOdo()) {
			throw new RefusedException('end_odo is below start_odo', self::END_BELOW_START);
		}
		// Past a day of clock and offset slack, an arrival ahead is a typo in the date, and its
		// Reading would stand ahead of every one entered after it.
		if ($trip->getEndedAt() > $this->time->getTime() + self::FUTURE_SLACK) {
			throw new RefusedException('ended_at is more than a day ahead', self::ENDS_IN_FUTURE);
		}
	}
}
