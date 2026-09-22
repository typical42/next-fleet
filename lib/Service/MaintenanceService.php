<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Everything a Maintenance Record is written under. Its counters follow the fill-up's rules
 * (docs/architecture.md#odometer-rules), and the Readings they leave are OdometerService's.
 */
class MaintenanceService {
	use TTransactional;

	/** The kinds of work a record can be (CONTEXT.md). "service" is one of them, never the class. */
	public const TYPES = ['service', 'repair', 'inspection', 'tyres', 'upgrade'];

	/**
	 * The columns a request may set, in the shape EnergyService::WRITABLE has. The counters are
	 * not among them: each is a Reading as well as a column.
	 *
	 * @var array<string, array{string, string, int|list<string>|null}>
	 */
	private const WRITABLE = [
		'done_at' => ['setDoneAt', 'count', null],
		'done_at_off' => ['setDoneAtOff', 'offset', null],
		'type' => ['setType', 'word', self::TYPES],
		'title' => ['setTitle', 'text', 255],
		'vendor' => ['setVendor', 'text', 255],
		'cost' => ['setCost', 'count', null],
		'vat_rate' => ['setVatRate', 'count', null],
		'notes' => ['setNotes', 'text', null],
	];

	/**
	 * The columns the database will not default. The sheet requires the title and prefills the
	 * moment, so a request without one is a client that did not.
	 */
	private const REQUIRED = ['done_at', 'done_at_off', 'title'];

	/** How far back prefill() looks for vendors: decades of a vehicle's workshop visits. */
	private const VENDOR_HISTORY = 200;

	public function __construct(
		private MaintenanceMapper $records,
		private OdometerService $odometer,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private Jurisdictions $jurisdictions,
		private ReminderService $reminders,
		private ReminderMapper $reminderRows,
		private IDBConnection $db,
	) {
	}

	/**
	 * Writes one Maintenance Record and a Reading per counter it was given.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed> the row as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$record = new Maintenance();
		$record->setVehicleId((int)$vehicle->getId());
		$record->setCreatedBy($userId);
		$this->apply($vehicle, $record, $fields);

		// Retried for the reason TripService::record() gives.
		return $this->atomicRetry(function () use ($userId, $vehicle, $record, $fields): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$closes = $this->reminders->closable((int)$vehicle->getId(), $fields['closes'] ?? null, null);
			$record->setReminderId($closes?->getId());
			$written = $this->records->insert($record);
			$this->follow($vehicle, $userId, $written);
			if ($closes !== null) {
				$this->reminders->closeBy($closes, $written);
			}

			return self::wire($written, $closes);
		}, $this->db);
	}

	/**
	 * Rewrites one record in place, and its Readings with it, as EnergyService::update() does a
	 * fill-up.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return array<string, mixed> the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the record has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $recordUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $recordUuid, $expectedUpdatedAt, $fields): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$record = $this->records->findOnVehicle((int)$vehicle->getId(), $recordUuid);
			$before = clone $record;
			$current = $this->closed($record);
			$this->apply($vehicle, $record, $fields);
			$closes = $this->reminders->closable((int)$vehicle->getId(), $fields['closes'] ?? null, $current);
			$record->setReminderId($closes?->getId());
			$edited = $this->records->updateChecked($record, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $edited);

			$moved = [$before->getDoneAt(), $before->getDoneAtOff(), $before->getOdo()] !== [$edited->getDoneAt(), $edited->getDoneAtOff(), $edited->getOdo()];
			if ($current !== $closes || $moved) {
				// Taken back and closed again from where the record now says the work was done.
				// A reminder that has moved on since is no longer this record's to move.
				$reopened = $current !== null && $this->reminders->reopenBy($current, $before);
				if ($closes !== null && ($closes !== $current || $reopened)) {
					$this->reminders->closeBy($closes, $edited);
				}
			}

			return self::wire($edited, $closes);
		}, $this->db);
	}

	/**
	 * Soft-deletes one record and its Readings, as EnergyService::delete() does a fill-up.
	 *
	 * @return array<string, mixed> the row as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the record has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $recordUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $recordUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$record = $this->records->findOnVehicle((int)$vehicle->getId(), $recordUuid);
			$deleted = $this->records->softDelete($record, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $deleted);
			$closes = $this->closed($deleted);
			if ($closes !== null) {
				$this->reminders->reopenBy($closes, $deleted);
			}

			return self::wire($deleted, $closes);
		}, $this->db);
	}

	/**
	 * Undo, on the token the delete answered with (TripService::restore()).
	 *
	 * @return array<string, mixed> the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the record has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $recordUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $recordUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$record = $this->records->findAnyOnVehicle((int)$vehicle->getId(), $recordUuid);
			$back = $this->records->restoreChecked($record, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $back);
			$closes = $this->closed($back);
			if ($closes !== null && !$this->reminders->recloseBy($closes, $back)) {
				// Something else moved the reminder on meanwhile. A link kept here would take that
				// back the next time this record is withdrawn.
				$closes = null;
				$back->setReminderId(null);
				$back = $this->records->updateChecked($back, $back->getUpdatedAt());
			}

			return self::wire($back, $closes);
		}, $this->db);
	}

	/**
	 * The reminder the record closes, as it now stands, deleted or not.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function closed(Maintenance $record): ?Reminder {
		$id = $record->getReminderId();

		return $id === null ? null : $this->reminderRows->findAnyByIds($record->getVehicleId(), [$id])[$id] ?? null;
	}

	/**
	 * The wire form, with the reminder the record closes named by its uuid: `reminder_id` is an
	 * id and stays off the wire.
	 *
	 * @return array<string, mixed>
	 */
	private static function wire(Maintenance $record, ?Reminder $closes): array {
		return $record->jsonSerialize() + ['closes' => $closes?->getUuid()];
	}

	/**
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function follow(Vehicle $vehicle, string $userId, Maintenance $record): void {
		$this->odometer->followEntry(
			$vehicle,
			$userId,
			OdoReading::MAINTENANCE,
			(int)$record->getId(),
			$record->getDoneAt(),
			$record->getDoneAtOff(),
			$record->getOdo(),
			$record->getSecondOdo(),
			$record->getDeletedAt() !== null,
		);
	}

	/**
	 * What the sheet prefills a record with (docs/ui.md): the VAT rate of the vehicle's
	 * jurisdiction on the day of the work, and the vendors this vehicle has used, each once and
	 * the latest first.
	 *
	 * @param array<string, mixed> $fields `at` and `off`: the moment the sheet is on
	 * @return array{vat_rate: ?int, vendors: list<string>}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the moment is not one
	 * @throws \OCP\DB\Exception
	 */
	public function prefill(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$at = Field::read('at', 'count', null, $fields['at'] ?? null);
		$off = Field::read('off', 'offset', null, $fields['off'] ?? null);
		if (!is_int($at) || !is_int($off)) {
			throw new \InvalidArgumentException('at and off are the moment a prefill is for');
		}

		$vendors = array_map(
			static fn (Maintenance $record): string => (string)$record->getVendor(),
			$this->records->findLatestWithVendor((int)$vehicle->getId(), self::VENDOR_HISTORY),
		);

		return [
			'vat_rate' => $this->jurisdictions->vatRateAt($vehicle->getJurisdiction(), $at, $off),
			'vendors' => array_values(array_unique($vendors)),
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 */
	private function apply(Vehicle $vehicle, Maintenance $record, array $fields): void {
		foreach (self::WRITABLE as $column => [$setter, $kind, $limit]) {
			$value = Field::read($column, $kind, $limit, $fields[$column] ?? null);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				throw new \InvalidArgumentException($column . ' is a field every maintenance record carries');
			}
			$record->$setter($value);
		}

		[$odo, $secondOdo] = OdometerService::entryCounters($vehicle, $fields);
		$record->setOdo($odo);
		$record->setSecondOdo($secondOdo);
	}
}
