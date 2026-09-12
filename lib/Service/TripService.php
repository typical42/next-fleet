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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
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
		'started_at' => ['setStartedAt', 'count', null],
		'started_at_off' => ['setStartedAtOff', 'offset', null],
		'ended_at' => ['setEndedAt', 'count', null],
		'ended_at_off' => ['setEndedAtOff', 'offset', null],
		'start_odo' => ['setStartOdo', 'count', null],
		'end_odo' => ['setEndOdo', 'count', null],
		'distance' => ['setDistance', 'count', null],
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
	private const VOIDED = 'voided';
	private const RESTORED = 'restored';

	/** What an audit row does not restate: it carries its own author, instant and identity. */
	private const BOOKKEEPING = ['uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by'];

	public function __construct(
		private TripMapper $trips,
		private AuditMapper $audit,
		private OdometerService $odometer,
		private VehicleService $fleet,
		private IDBConnection $db,
	) {
	}

	/**
	 * Writes one trip and the Reading it left on the counter.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$trip = new Trip();
		$trip->setVehicleId((int)$vehicle->getId());
		$trip->setCreatedBy($userId);
		$this->apply($trip, $fields);

		// The two rows are one fact. A trip whose Reading did not land is a logbook that disagrees
		// with the counter it is measured against, and nothing later can tell which of the two is
		// wrong.
		//
		// Retried rather than atomic() alone: the Reading restates the whole chain and the
		// vehicle's cache with it, so two drivers logging a shared car at once can meet the
		// database's own deadlock detection - and a 500 there loses the trip the driver typed.
		// The replay is safe because BaseMapper::insert re-marks every column on the entity.
		return $this->atomicRetry(function () use ($userId, $vehicle, $trip): Trip {
			$written = $this->trips->insert($trip);
			$this->trail($vehicle, $written, $userId, self::CREATED, $this->stated($written));
			$this->odometer->fromTrip($vehicle, $written);

			return $written;
		}, $this->db);
	}

	/**
	 * Voids one trip: the row survives with `deleted_at` stamped on it
	 * (docs/features.md#logbook-mode), which is what the trash, the undo and the export read it
	 * back through.
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the trip has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $tripUuid, int $expectedUpdatedAt): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		// The trip is looked up inside the transaction, not before it: a replay after a deadlock
		// has to start from the row as the database has it, and an entity a rolled-back statement
		// already stamped would be written back a second time with nothing left to change.
		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $expectedUpdatedAt): Trip {
			$trip = $this->on($vehicle, $this->trips->findByUuid($tripUuid));
			$voided = $this->trips->softDelete($trip, $expectedUpdatedAt);
			$this->trail($vehicle, $voided, $userId, self::VOIDED, [
				'deleted_at' => [null, $voided->getDeletedAt()],
			]);
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
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the trip has changed since, or was never voided
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $tripUuid, int $expectedUpdatedAt): Trip {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $tripUuid, $expectedUpdatedAt): Trip {
			$voided = $this->on($vehicle, $this->trips->findAnyByUuid($tripUuid));
			$stamp = $voided->getDeletedAt();
			$back = $this->trips->restoreChecked($voided, $expectedUpdatedAt);
			$this->trail($vehicle, $back, $userId, self::RESTORED, ['deleted_at' => [$stamp, null]]);
			$this->odometer->followTrip($vehicle, $back);

			return $back;
		}, $this->db);
	}

	/**
	 * The trip, where it hangs off the vehicle the route named. A uuid alone would be a second way
	 * in: one vehicle of their own is all somebody would need to reach a journey in anybody else's
	 * logbook, and the gate upstream only ever asked about the vehicle.
	 *
	 * Not found rather than refused, for the reason a restore looks a stranger's uuid up the same
	 * way every other route does: an answer that told them apart would tell them which uuids exist.
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
	 * The audit row the write leaves behind, and only under Logbook Mode
	 * (docs/features.md#logbook-mode) - off the mode the trail would be a log nobody reads and a
	 * private user never asked for.
	 *
	 * Inside the caller's transaction on purpose: a change that landed without its row, or a row
	 * about a change that rolled back, are both a trail that disagrees with the logbook it
	 * describes, and nothing later can tell which of the two happened.
	 *
	 * The vehicle is the snapshot the gate handed back, not a second read taken here. A trip, its
	 * Reading and its audit row are one write and are made under one state of the vehicle; a mode
	 * flip racing that write is recorded on the vehicle itself, with the instant it took effect,
	 * which is what says on which side of it a trip falls.
	 *
	 * The author is whoever made the change, never the trip's `created_by`: a trip somebody else
	 * entered is one this person voided, and the trail is about the change.
	 *
	 * @param array<string, array{mixed, mixed}> $fields each changed column as `[before, after]`
	 * @throws \OCP\DB\Exception
	 */
	private function trail(Vehicle $vehicle, Trip $trip, string $userId, string $change, array $fields): void {
		if ($vehicle->getLogbookMode() !== true) {
			return;
		}

		$row = new Audit();
		$row->setCreatedBy($userId);
		$row->setEntity(Audit::TRIP);
		$row->setEntityId((int)$trip->getId());
		$row->setDiffJson(['change' => $change, 'fields' => $fields]);
		$this->audit->insert($row);
	}

	/**
	 * What the trip says, each field as the pair `[before, after]` an auditor reads a diff as. A
	 * creation has nothing before it, so every pair starts at null.
	 *
	 * A field the driver left empty is not in the diff: it did not change, and listing it would
	 * bury the fields that did. The row's own identity, author and dating are left out too; the
	 * audit row carries all three already (docs/architecture.md#data-model).
	 *
	 * The columns are read off the wire form because that is where this app spells them out once.
	 * `testTheAuditRowNamesEveryColumnATripCarries` is what ties the two together, so a change to
	 * the one the client reads cannot quietly give the trail a different vocabulary.
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
			$value = $this->read($column, $kind, $limit, $fields[$column] ?? null);
			if ($value === null) {
				if (in_array($column, self::REQUIRED, true)) {
					throw new \InvalidArgumentException($column . ' is a field every trip carries');
				}
				continue;
			}

			$trip->$setter($value);
		}

		// Rule 5 gives the Reading the moment the journey ended, so a trip that ended before it
		// began would date the counter earlier than the drive that moved it.
		if ($trip->getEndedAt() < $trip->getStartedAt()) {
			throw new \InvalidArgumentException('ended_at is not before started_at');
		}
		// The sheet toggles between the two (docs/ui.md): the counter the trip ended on, or the
		// kilometres it covered. A trip with neither leaves the counter with nothing to say, and
		// one with both states the end of the journey twice - preferring either would throw away
		// a number the other contradicts, which is the opposite of what rule 6 asks.
		if ($trip->getEndOdo() === null && $trip->getDistance() === null) {
			throw new \InvalidArgumentException('a trip carries end_odo or distance');
		}
		if ($trip->getEndOdo() !== null && $trip->getDistance() !== null) {
			throw new \InvalidArgumentException('a trip is a counter or a distance, not both');
		}
	}

	/**
	 * One field, as its column holds it. An absent value and an empty one are the same fact, so
	 * both arrive here as null.
	 *
	 * @param int|list<string>|null $limit
	 * @throws \InvalidArgumentException
	 */
	private function read(string $column, string $kind, int|array|null $limit, mixed $value): string|int|null {
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '') {
			return null;
		}

		return match ($kind) {
			'text' => $this->text($column, $value, is_int($limit) ? $limit : null),
			'word' => $this->word($column, $value, is_array($limit) ? $limit : []),
			'count' => $this->count($column, $value),
			'offset' => $this->offset($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
	}

	/** @throws \InvalidArgumentException */
	private function text(string $column, mixed $value, ?int $length): string {
		if (!is_string($value)) {
			throw new \InvalidArgumentException($column . ' is text');
		}
		// Refused rather than truncated: the database would refuse it too, and a 500 tells the
		// user nothing about which field was too long.
		if ($length !== null && mb_strlen($value) > $length) {
			throw new \InvalidArgumentException($column . ' is longer than ' . $length . ' characters');
		}

		return $value;
	}

	/**
	 * @param list<string> $vocabulary
	 * @throws \InvalidArgumentException
	 */
	private function word(string $column, mixed $value, array $vocabulary): string {
		if (!is_string($value) || !in_array($value, $vocabulary, true)) {
			throw new \InvalidArgumentException($column . ' is one of ' . implode(', ', $vocabulary));
		}

		return $value;
	}

	/** @throws \InvalidArgumentException */
	private function count(string $column, mixed $value): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false || $number < 0) {
			throw new \InvalidArgumentException($column . ' is a whole number, never negative');
		}

		return $number;
	}

	/**
	 * The offset the moment was entered at, in minutes (docs/architecture.md#time). Real ones run
	 * from -12:00 to +14:00, and a number outside that is a field that did not mean minutes.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function offset(string $column, mixed $value): int {
		$minutes = filter_var($value, FILTER_VALIDATE_INT);
		if ($minutes === false || $minutes < -720 || $minutes > 840) {
			throw new \InvalidArgumentException($column . ' is a UTC offset in minutes');
		}

		return $minutes;
	}
}
