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
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\IServiceTemplates;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Everything a Reminder is written under (docs/architecture.md#reminder-engine). Its state is
 * the engine's to move; this is what the sheet sets.
 *
 * @psalm-import-type NextFleetReminder from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetListedReminder from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetFleetReminder from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetReminderTemplate from \OCA\NextFleet\ResponseDefinitions
 */
class ReminderService {
	use TTransactional;

	/** The numbers a reminder is set with, each under its bound (docs/security.md). */
	private const BOUNDS = ['due_odo' => Field::COUNTER, 'lead_odo' => Field::COUNTER, 'recur_months' => Field::MONTHS, 'recur_odo' => Field::COUNTER];
	/**
	 * The states a reminder is still open in, most urgent first. A snooze silences it, so it sinks
	 * below one merely planned.
	 */
	private const URGENCY = [Reminder::OVERDUE => 0, Reminder::DUE => 1, Reminder::WARNED => 2, Reminder::PLANNED => 3, Reminder::SNOOZED => 4];

	/**
	 * While replay() runs, by record id: what each record above kept before the replay first wrote
	 * it. Null otherwise. The notices wait for the net change, or a value put back would ring twice.
	 *
	 * @var array<int, ClosedDue>|null
	 */
	private ?array $replaying = null;

	public function __construct(
		private ReminderMapper $reminders,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IServiceTemplates $services,
		private Jurisdictions $jurisdictions,
		private OdoReadingMapper $readings,
		private ITimeFactory $time,
		private IDBConnection $db,
		private NotificationService $notifications,
		private AfterCommit $after,
		private MaintenanceMapper $records,
	) {
	}

	/**
	 * The state is evaluated at now rather than read from the row, and nothing is written
	 * (docs/architecture.md#reminder-engine).
	 *
	 * @return list<NextFleetListedReminder> the vehicle's live reminders, in their wire form, each
	 *                                       with its `estimate` (ReminderEngine::estimate())
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		return $this->listed($this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid));
	}

	/**
	 * The overview's read: every vehicle the user may see, as `list()` answers each, and each row
	 * names its `vehicle`. A sold vehicle has left the overview, so its reminders are not read.
	 *
	 * @return list<NextFleetFleetReminder>
	 * @throws \OCP\DB\Exception
	 */
	public function fleet(string $userId): array {
		return array_map(
			static fn (array $one): array => ['vehicle' => $one['vehicle']->getUuid()] + $one['reminder'],
			$this->fleetBeside($userId),
		);
	}

	/**
	 * The dashboard's read: `fleet()`'s open reminders, most urgent first, each beside its vehicle.
	 * The overview sorts the same rows in the browser; this is that order (src/utils/reminders.js
	 * `openByUrgency`) for a reader with no browser code: by state, then by the sooner of the due
	 * date and the estimate, a reminder with neither last in its state.
	 *
	 * @return list<array{vehicle: Vehicle, reminder: NextFleetListedReminder}>
	 * @throws \OCP\DB\Exception
	 */
	public function due(string $userId): array {
		$open = array_values(array_filter(
			$this->fleetBeside($userId),
			static fn (array $one): bool => isset(self::URGENCY[$one['reminder']['state']]),
		));
		// A plain day sorts as a string; no day sorts after every day.
		$soonest = static fn (array $reminder): string => min(array_filter(
			[$reminder['due_date'], $reminder['estimate'], '9999-12-31'],
			is_string(...),
		));
		usort($open, static fn (array $a, array $b): int => self::URGENCY[$a['reminder']['state']] <=> self::URGENCY[$b['reminder']['state']]
			?: $soonest($a['reminder']) <=> $soonest($b['reminder']));

		return $open;
	}

	/**
	 * @return list<array{vehicle: Vehicle, reminder: NextFleetListedReminder}>
	 * @throws \OCP\DB\Exception
	 */
	private function fleetBeside(string $userId): array {
		$byId = [];
		foreach ($this->fleet->list($userId) as $vehicle) {
			if ($vehicle->getLifecycle() !== Vehicle::DISPOSED) {
				$byId[(int)$vehicle->getId()] = $vehicle;
			}
		}
		// Every vehicle's reminders and pace in one query each, however large the fleet.
		$reminders = $this->reminders->findByVehicles(array_keys($byId));
		$now = $this->time->now();
		$paces = $this->paces(array_merge(...array_values($reminders)), $now);

		$rows = [];
		foreach ($byId as $vehicleId => $vehicle) {
			foreach ($this->wiredAt($vehicle, $reminders[$vehicleId] ?? [], $paces[$vehicleId] ?? [], $now) as $row) {
				$rows[] = ['vehicle' => $vehicle, 'reminder' => $row];
			}
		}

		return $rows;
	}

	/**
	 * @return list<NextFleetListedReminder>
	 * @throws \OCP\DB\Exception
	 */
	private function listed(Vehicle $vehicle): array {
		return $this->wired($vehicle, $this->reminders->findByVehicle((int)$vehicle->getId()));
	}

	/**
	 * Reminders of one vehicle as the list answers them, in the order given; sync answers its
	 * reminders through this too.
	 *
	 * @param list<Reminder> $reminders
	 * @return list<NextFleetListedReminder>
	 * @throws \OCP\DB\Exception
	 */
	public function wired(Vehicle $vehicle, array $reminders): array {
		$now = $this->time->now();

		return $this->wiredAt($vehicle, $reminders, $this->paces($reminders, $now)[(int)$vehicle->getId()] ?? [], $now);
	}

	/**
	 * The main chains a pace is read off (ReminderEngine::estimate()), for the vehicles these
	 * reminders hang off - only the window, not the whole chain, and only where a reminder goes
	 * by km. `$now` is the one wiredAt() estimates at, so the window ends where it looks.
	 *
	 * @param list<Reminder> $reminders
	 * @return array<int, list<OdoReading>> by vehicle
	 * @throws \OCP\DB\Exception
	 */
	private function paces(array $reminders, \DateTimeImmutable $now): array {
		$ids = [];
		foreach ($reminders as $reminder) {
			if ($reminder->getMode() !== Reminder::DATE) {
				$ids[$reminder->getVehicleId()] = true;
			}
		}
		$at = $now->getTimestamp();

		return $this->readings->findMainWithin(array_keys($ids), $at - ReminderEngine::PACE_WINDOW, $at);
	}

	/**
	 * @param list<Reminder> $reminders
	 * @param list<OdoReading> $pace the vehicle's main chain over the pace window
	 * @return list<NextFleetListedReminder>
	 */
	private function wiredAt(Vehicle $vehicle, array $reminders, array $pace, \DateTimeImmutable $now): array {
		$today = $now->format('Y-m-d');
		// The newest value, flagged or not, as the odometer caches it on every write.
		$odo = $vehicle->getOdoValue();

		return array_map(
			static fn (Reminder $reminder): array => [
				'state' => ReminderEngine::evaluate($reminder, $today, $odo)['state'],
				'estimate' => ReminderEngine::estimate($reminder, $pace, $now),
			] + $reminder->jsonSerialize(),
			$reminders,
		);
	}

	/**
	 * What the sheet may start a reminder from on this vehicle, with what each fills in.
	 *
	 * `first_due_months` is set on the inspection only: a new vehicle's first one may come later
	 * than its cadence, and the sticker question prefills from it.
	 *
	 * @return list<NextFleetReminderTemplate>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function templates(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$scheme = $this->jurisdictions->get($vehicle->getJurisdiction())->inspectionScheme();
		$inspection = $scheme?->template($vehicle->getVehicleType())->key;

		return array_map(static fn (ReminderTemplate $template): array => [
			'key' => $template->key,
			'mode' => $template->mode,
			'recur_months' => $template->recurMonths,
			'recur_odo' => $template->recurOdo,
			'lead_odo' => $template->leadOdo,
			'first_due_months' => $template->key === $inspection ? $scheme?->firstDueMonths($vehicle->getVehicleType()) : null,
		], $this->offered($vehicle));
	}

	/**
	 * Writes one reminder, from a template or under the user's own title. A template fills what
	 * the request left out; the due date or km is always the request's.
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetReminder the row as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->reminders,
			static fn (Reminder $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			static fn (Reminder $row): array => $row->jsonSerialize(),
		);

		return $once->run(fn (): array => $this->insert($userId, $vehicle, $fields, $once));
	}

	/**
	 * What create() writes once it knows the request is no retry.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Reminder> $once
	 * @return NextFleetReminder
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, Vehicle $vehicle, array $fields, Once $once): array {
		$reminder = new Reminder();
		$once->stamp($reminder);
		$reminder->setVehicleId((int)$vehicle->getId());
		$reminder->setCreatedBy($userId);
		$reminder->setState(Reminder::PLANNED);
		$reminder->setOccurrence(1);

		$key = Field::read('template_key', 'text', 32, $fields['template_key'] ?? null);
		$template = is_string($key) ? $this->template($vehicle, $key) : null;
		$reminder->setTemplateKey($template?->key);
		$this->apply($reminder, $fields, $template);

		// Retried for the reason TripService::record() gives.
		$written = $this->atomicRetry(function () use ($vehicle, $reminder, $once): Reminder {
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();

			return $this->reminders->insert($reminder);
		}, $this->db);

		return $written->jsonSerialize();
	}

	/**
	 * Rewrites what the sheet sets. The template, the state and the occurrence stay: the first is
	 * what the reminder is, the others are the engine's. A template fills only at creation, so a
	 * field left out here is cleared.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return NextFleetReminder the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		[$updated, $told] = $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt, $fields): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->reminders->findOnVehicle((int)$vehicle->getId(), $reminderUuid);
			$was = clone $reminder;
			$this->apply($reminder, $fields, null);
			$updated = $this->reminders->updateChecked($reminder, $expectedUpdatedAt);

			return [$updated, $this->notifications->rearm($was, $updated)];
		}, $this->db);
		// After the commit, as the sweep sends: a rollback would leave the receipt and no notice.
		foreach ($told as $recipient) {
			$this->notifications->withdraw($updated, $recipient);
		}

		return $updated->jsonSerialize();
	}

	/**
	 * Ends the reminder, recurrence and all. Dismissing skips one occurrence; this is the explicit
	 * act rule 6 asks for, so it takes EDIT, not DELETE.
	 *
	 * @return NextFleetReminder the row as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$deleted = $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt): Reminder {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->reminders->findOnVehicle((int)$vehicle->getId(), $reminderUuid);

			return $this->reminders->softDelete($reminder, $expectedUpdatedAt);
		}, $this->db);
		// The job reads live reminders only, so it would never take this one's back.
		$this->notifications->withdraw($deleted);

		return $deleted->jsonSerialize();
	}

	/**
	 * Undo, on the token the delete answered with (TripService::restore()).
	 *
	 * @return NextFleetReminder the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		return $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->reminders->findAnyOnVehicle((int)$vehicle->getId(), $reminderUuid);

			return $this->reminders->restoreChecked($reminder, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
	}

	/**
	 * Silences every channel until that day, when the reminder is back where it stands. The due
	 * date stays (rule 6).
	 *
	 * @param mixed $until a plain day after today, YYYY-MM-DD
	 * @return NextFleetReminder the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since
	 * @throws \InvalidArgumentException if the day is not to come, or the occurrence is over
	 * @throws \OCP\DB\Exception
	 */
	public function snooze(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt, mixed $until): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$day = Field::day('until', $until);
		if ($day->format('Y-m-d') <= $this->today()) {
			throw new \InvalidArgumentException('until is a day still to come');
		}

		$snoozed = $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt, $day): Reminder {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->open((int)$vehicle->getId(), $reminderUuid);
			$reminder->setSnoozedUntil($day);
			$reminder->setState(Reminder::SNOOZED);

			return $this->reminders->updateChecked($reminder, $expectedUpdatedAt);
		}, $this->db);
		$this->notifications->withdraw($snoozed);

		return $snoozed->jsonSerialize();
	}

	/**
	 * Skips this occurrence. A recurring reminder moves on a recurrence from the planned due, not
	 * from today, since nothing was done; one that does not recur is left dismissed.
	 *
	 * @return NextFleetReminder the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since
	 * @throws \InvalidArgumentException if the occurrence is already over
	 * @throws \OCP\DB\Exception
	 */
	public function dismiss(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$dismissed = $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt): Reminder {
			$vehicleId = (int)$vehicle->getId();
			$this->vehicles->hold($vehicleId);
			$reminder = $this->open($vehicleId, $reminderUuid);

			if (ReminderEngine::advance($reminder, $reminder->getDueDate()?->format('Y-m-d'), $reminder->getDueOdo())) {
				$this->reevaluate($reminder);
			} else {
				$reminder->setSnoozedUntil(null);
				$reminder->setState(Reminder::DISMISSED);
			}

			return $this->reminders->updateChecked($reminder, $expectedUpdatedAt);
		}, $this->db);
		$this->notifications->withdraw($dismissed);

		return $dismissed->jsonSerialize();
	}

	/**
	 * The reminder a maintenance record's `closes` names: a live, open one on the record's
	 * vehicle, or the one the record already closes, whatever became of it since.
	 *
	 * For MaintenanceService, which has reached the vehicle and holds it.
	 *
	 * @param mixed $uuid the request's `closes`
	 * @param ?Reminder $current the reminder the record closes as it stands, if it does
	 * @throws \InvalidArgumentException if it names no reminder the record may close
	 * @throws \OCP\DB\Exception
	 */
	public function closable(int $vehicleId, mixed $uuid, ?Reminder $current): ?Reminder {
		$uuid = Field::read('closes', 'text', 36, $uuid);
		if (!is_string($uuid)) {
			return null;
		}
		if ($uuid === $current?->getUuid()) {
			return $current;
		}
		try {
			return $this->open($vehicleId, $uuid);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			// Not a 404: the route's record exists, it is the field that names nothing.
			throw new \InvalidArgumentException('closes is not a reminder on this vehicle');
		}
	}

	/**
	 * Closes the reminder's occurrence with a maintenance record. One that recurs moves on from
	 * the record's own day and counter (rule 4); one that does not is done. For a caller inside an
	 * AfterCommit run: the withdrawal waits for its commit.
	 *
	 * The record keeps what the close replaces (keep()); the caller writes it.
	 *
	 * @param ?string $snoozed the snooze of the occurrence the close starts, a plain day: none for
	 *                         a new close, the one it had for the undo of a record from before
	 * @throws \InvalidArgumentException if it recurs by km and the record states no counter
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function closeBy(Reminder $reminder, Maintenance $record, ?string $snoozed = null): void {
		if (!self::counts($reminder, $record)) {
			throw new \InvalidArgumentException('odo is the counter this reminder recurs from');
		}
		self::keep($record, $reminder);
		$this->writeClosed($reminder, ReminderEngine::advance($reminder, self::dayOf($record), $record->getOdo()), $snoozed);
	}

	/**
	 * Writes the reminder a close moved on, with the snooze of the occurrence it starts, or done
	 * when it does not recur. Only an undo closes a deleted reminder.
	 *
	 * @param ?string $snoozed a plain day
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function writeClosed(Reminder $reminder, bool $recurs, ?string $snoozed): void {
		if ($recurs) {
			$reminder->setSnoozedUntil($snoozed === null ? null : new \DateTime($snoozed));
			$this->reevaluate($reminder);
		} else {
			$reminder->setState(Reminder::DONE);
		}
		$this->writeMoved($reminder);
	}

	/**
	 * Writes a reminder a close or its take-back moved, and withdraws its notice after the commit.
	 * While replay() runs, the token stays and the notice waits for the net change. A deleted
	 * reminder keeps its token too (ReminderMapper::writeWhileDeleted()), and its delete withdrew
	 * every notice, so there is none to withdraw.
	 *
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function writeMoved(Reminder $reminder): void {
		if ($reminder->getDeletedAt() !== null) {
			$this->reminders->writeWhileDeleted($reminder);

			return;
		}
		if ($this->replaying !== null) {
			$this->reminders->writeOnTheWay($reminder);

			return;
		}
		$this->reminders->updateChecked($reminder, $reminder->getUpdatedAt());
		$this->after->defer(fn () => $this->notifications->withdraw($reminder));
	}

	/**
	 * Takes back what closeBy() did with this record, unless something moved the reminder since or
	 * another record's close is on top. The withdrawal waits, as closeBy()'s does.
	 *
	 * A deleted record keeps what its close replaced, so a deleted reminder is left as it was
	 * deleted. An edit to close another or none keeps nothing of it on the record, so the deleted
	 * reminder takes the close back (writeMoved()), and its undo brings back the planned due.
	 *
	 * @param bool $deleted whether the record is deleted, rather than edited
	 * @return bool whether it was taken back
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function reopenBy(Reminder $reminder, Maintenance $record, bool $deleted): bool {
		if (($deleted && $reminder->getDeletedAt() !== null) || !$this->onTop($reminder, $record)) {
			return false;
		}
		if (ReminderEngine::recurs($reminder) && !ReminderEngine::retreat($reminder, self::dayOf($record), $record->getOdo(), self::kept($record))) {
			throw new \LogicException('retreat() found the close off top where onTop() found it on top');
		}
		$this->reevaluate($reminder);
		// The occurrence it sent for is gone, and the job sees no state change to take it back.
		$this->writeMoved($reminder);

		return true;
	}

	/**
	 * Takes the record's close back (docs/architecture.md#reminder-engine). When its close is on top
	 * (onTop()), the reminder comes back as reopenBy() puts it; a deleted one stays as it was deleted
	 * when the record is deleted too. Under the close of the record above,
	 * that record takes over what this one kept, and the reminder stays as it is. Otherwise nothing
	 * changes.
	 *
	 * A deleted record keeps its values, the snooze of the occurrence its close started, the
	 * record it handed its values to, and whether anything was taken back, so an undo can give each
	 * back. Both records are written with MaintenanceMapper::writeKept().
	 *
	 * @param bool $deleted whether the record is deleted, rather than edited to close another or none
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function takeBack(Reminder $reminder, Maintenance $record, bool $deleted): void {
		$snoozed = $reminder->getSnoozedUntil()?->format('Y-m-d');
		[$handedTo, $tookBack] = [null, true];
		if (!$this->reopenBy($reminder, $record, $deleted)) {
			$above = $this->above($reminder, $record);
			if ($above === null) {
				[$snoozed, $tookBack] = [null, false];
			} else {
				$snoozed = $above->getClosedSnoozedUntil()?->format('Y-m-d');
				$handedTo = $above->getId();
				$this->writeKeptAbove($reminder, $above, self::kept($above)->handedFrom(self::kept($record)));
			}
		}
		if ($deleted) {
			$record->setNextSnoozedUntil($snoozed);
			$record->setHandedTo($handedTo);
			$record->setTookBack($tookBack);
			$this->records->writeKept($record);
		}
	}

	/**
	 * Moves the record's close to where an edit now says the work was done
	 * (docs/architecture.md#reminder-engine). Only a day or counter the reminder recurs by moves
	 * it. When the close is on top, the reminder is counted from the new base and keeps its
	 * occurrence and snooze; the points that moved ring again, as after a hand edit. A reminder
	 * whose due does not change (the base moved on an axis the close set none for, or to a day
	 * that gives the same due) is not written. Under the close of the record above, that record
	 * keeps the due the new base gives. Either way the record stores the due its close now sets
	 * (ReminderEngine::rebase()). Otherwise nothing changes.
	 * A deleted reminder moves too, its token kept (ReminderMapper::writeWhileDeleted()), so
	 * deleting the record after the reminder's undo still finds its close on top; its moved points
	 * ring again after that undo.
	 *
	 * @param Maintenance $was the record as it was before the edit
	 * @param Maintenance $is the record as edited
	 * @throws \InvalidArgumentException if the close is in effect and set a km, or is from before,
	 *                                   the reminder recurs by km and the record no longer states a
	 *                                   counter
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function moveBy(Reminder $reminder, Maintenance $was, Maintenance $is): void {
		$byDay = $reminder->getRecurMonths() !== null && self::dayOf($was) !== self::dayOf($is);
		$byOdo = $reminder->getRecurOdo() !== null && $was->getOdo() !== $is->getOdo();
		if (!$byDay && !$byOdo) {
			return;
		}
		[$day, $odo, $kept] = [self::dayOf($was), $was->getOdo(), self::kept($was)];
		$onTop = $this->onTop($reminder, $was);
		$above = $onTop ? null : $this->above($reminder, $was);
		if (!$onTop && $above === null) {
			// Nothing to move, so the counter is not needed either.
			return;
		}
		// Only a km the close set is counted from the counter; one it set none for stays as entered.
		$setsKm = $kept->occurrence === null || $kept->nextOdo !== null || $kept->odo !== null;
		if ($byOdo && $setsKm && !self::counts($reminder, $is)) {
			throw new \InvalidArgumentException('odo is the counter this reminder recurs from');
		}
		// Only an axis whose base moved is counted again: the interval may have changed since the close.
		[$toDay, $toOdo] = [$byDay ? self::dayOf($is) : null, $byOdo ? $is->getOdo() : null];
		$moved = ReminderEngine::rebase($reminder, $kept, $toDay, $toOdo);
		$changed = [$moved->nextDay, $moved->nextOdo] !== [$kept->nextDay, $kept->nextOdo];
		if ($changed) {
			$is->setNextDueDate($moved->nextDay);
			$is->setNextDueOdo($moved->nextOdo);
			$this->records->writeKept($is);
		}
		if ($above !== null) {
			if ($changed) {
				$this->writeKeptAbove($reminder, $above, ReminderEngine::keptAbove($moved, self::kept($above)));
			}

			return;
		}
		$before = clone $reminder;
		// The record already holds the due rebase() gave; a reminder left where it was would strand
		// the close off top, so the throw rolls both back.
		if (!ReminderEngine::move($reminder, $day, $odo, $kept, $toDay, $toOdo)) {
			throw new \LogicException('move() found the close off top where onTop() found it on top');
		}
		if (ReminderEngine::moved($before, $reminder) === []) {
			// A write would move the token, and a sheet open on the reminder would fail for nothing.
			return;
		}
		$this->reevaluate($reminder);
		if ($reminder->getDeletedAt() !== null) {
			// Its delete withdrew every notice, but what was sent stays for its undo.
			$this->notifications->rearm($before, $reminder, standing: false);
			$this->reminders->writeWhileDeleted($reminder);

			return;
		}
		$this->reminders->updateChecked($reminder, $reminder->getUpdatedAt());
		$this->rearm($before, $reminder);
	}

	/**
	 * Lets the points that moved within the occurrence ring again (NotificationService::rearm()),
	 * and withdraws, after the commit, the notice of each user it was for.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function rearm(Reminder $was, Reminder $is): void {
		foreach ($this->notifications->rearm($was, $is) as $recipient) {
			$this->after->defer(fn () => $this->notifications->withdraw($is, $recipient));
		}
	}

	/**
	 * Sets what the record above keeps and writes it, its token untouched
	 * (MaintenanceMapper::writeKept()): the kept due, km, snooze and kept occurrence. Its own
	 * occurrence and the due its close set stay.
	 * When its due moves within the same occurrence, the points that moved ring again once the
	 * reminder returns there, as after a hand edit (NotificationService::rearm()). That occurrence
	 * was closed, which withdrew its notice, so there is none to withdraw now.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function writeKeptAbove(Reminder $reminder, Maintenance $above, ClosedDue $kept): void {
		$was = self::kept($above);
		$above->setClosedDueDate($kept->day);
		$above->setClosedDueOdo($kept->odo);
		$above->setClosedSnoozedUntil($kept->snoozedUntil);
		$above->setKeptOccurrence($kept->keptOccurrence);
		$this->records->writeKept($above);
		if ($this->replaying !== null) {
			$this->replaying[(int)$above->getId()] ??= $was;

			return;
		}
		$this->rearmKept($reminder, $was, $kept);
	}

	/**
	 * Lets the points ring again that moved when a record above's kept due moved within the same
	 * occurrence (writeKeptAbove()).
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function rearmKept(Reminder $reminder, ClosedDue $was, ClosedDue $kept): void {
		$occurrence = $kept->returnsTo();
		if ($occurrence !== null && $occurrence === $was->returnsTo()) {
			$this->notifications->rearm(self::at($reminder, $occurrence, $was), self::at($reminder, $occurrence, $kept), standing: false);
		}
	}

	/** The reminder as it stood at that occurrence, due where the record kept it. */
	private static function at(Reminder $reminder, int $occurrence, ClosedDue $kept): Reminder {
		$at = clone $reminder;
		$at->setOccurrence($occurrence);
		$at->setDueDate($kept->day === null ? null : new \DateTime($kept->day));
		$at->setDueOdo($kept->odo);

		return $at;
	}

	/**
	 * The live record that closed the occurrence this record's close started, if it kept the due
	 * that close gave. A record from before kept no occurrence, so it has none.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function above(Reminder $reminder, Maintenance $record): ?Maintenance {
		$above = $this->closingAbove($reminder, $record);

		return $above !== null && ReminderEngine::follows(self::kept($record), self::kept($above)) ? $above : null;
	}

	/**
	 * Whether another live record's close is the one on top, whatever ReminderEngine::onTop() finds:
	 * it closed the occurrence before the reminder's current one, or, for a one-off, which a close
	 * does not move on, the current one. A record from before kept no occurrence, so the engine
	 * alone takes its close for one from the same day and km. On a recurring reminder, a close
	 * that set no axis it recurs by has nothing to tell, so it is never on top
	 * (ReminderEngine::onTop()): it claims nothing, as when an undo kept its link without closing.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function claimedByAnother(Reminder $reminder, Maintenance $record): bool {
		$recurs = ReminderEngine::recurs($reminder);
		$others = $this->records->findClosingOthers($record->getVehicleId(), (int)$reminder->getId(), (int)$record->getId(), $reminder->getOccurrence() - ($recurs ? 1 : 0));
		foreach ($others as $other) {
			if (!$recurs || ReminderEngine::tells($reminder, self::kept($other))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The live record that closed the lowest occurrence above the one this record closed, whatever
	 * it kept; it can lie further up than the next one. A record from before kept no occurrence, so
	 * none lies above it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function closingAbove(Reminder $reminder, Maintenance $record): ?Maintenance {
		$occurrence = $record->getClosedOccurrence();

		return $occurrence === null ? null : $this->records->findClosingAbove($record->getVehicleId(), (int)$reminder->getId(), $occurrence);
	}

	/**
	 * Undo of a record's delete (docs/architecture.md#reminder-engine), by the first case that holds:
	 * - the record it handed its values to still holds them: that record gets back what it kept
	 *   before, with the occurrence of that due;
	 * - its close is still in effect, on top or under the record above: nothing to do;
	 * - its delete took nothing back: nothing to do;
	 * - the reminder stands where taking the close back left it: the record closes it again, the
	 *   reminder deleted or not, at the due its close set (ReminderEngine::reclose()) and the
	 *   occurrence it started before; a one-off's close, made to recur since, sets the due its day
	 *   and counter give (ReminderEngine::recurred()); a close that sets no axis the reminder recurs
	 *   by keeps the link and leaves the reminder where it is; a record from before closes it from
	 *   its own day and counter (closeBy()).
	 * Otherwise the record no longer closes it; the architecture section names each such case.
	 *
	 * Sets the record's kept values, and clears the next occurrence's snooze, the record it handed
	 * to and what its delete took back; the caller writes it.
	 *
	 * @return bool whether the record still closes the reminder
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function restoreBy(Reminder $reminder, Maintenance $record): bool {
		$nextSnooze = $record->getNextSnoozedUntil()?->format('Y-m-d');
		$handedTo = $this->handedTo($reminder, $record);
		$tookBack = $record->getTookBack();
		$record->setNextSnoozedUntil(null);
		$record->setHandedTo(null);
		$record->setTookBack(null);
		[$day, $odo, $kept] = [self::dayOf($record), $record->getOdo(), self::kept($record)];

		if ($handedTo !== null && $kept->occurrence !== null && self::kept($handedTo)->took($kept)) {
			$back = ReminderEngine::keptAbove($kept, self::kept($handedTo));
			// The due this close gives is the one of the occurrence it started.
			$started = self::keptOccurrence($kept->occurrence + 1, $back->occurrence);
			$this->writeKeptAbove($reminder, $handedTo, new ClosedDue($back->day, $back->odo, $nextSnooze, $back->occurrence, $started, nextDay: $back->nextDay, nextOdo: $back->nextOdo));

			return true;
		}
		if ($this->inEffect($reminder, $record)) {
			return true;
		}
		// A delete that took nothing back left the reminder as it was, so its undo has nothing to put
		// back. A close on top since, a one-off's too, stays that record's (claimedByAnother()).
		if ($tookBack === false) {
			return true;
		}
		// A record from before closes again from its own day and counter, so it needs the counter the
		// reminder now recurs by; without it the reminder already holds what the record kept, so
		// dropping the link loses nothing.
		if (!ReminderEngine::over($reminder)
			&& ($kept->occurrence !== null || self::counts($reminder, $record)) && ReminderEngine::standsAt($reminder, $day, $odo, $kept)) {
			if ($kept->occurrence !== null && ReminderEngine::recurs($reminder)) {
				// A one-off's close counts its km from the counter as a new close would, so it needs
				// one, as a record from before does (above).
				if ($kept->odo !== null && $kept->nextOdo === null && !self::counts($reminder, $record)) {
					return false;
				}
				// A one-off made to recur since: the record now stores the due a new close would set.
				$kept = ReminderEngine::recurred($reminder, $day, $odo, $kept);
				$record->setNextDueDate($kept->nextDay);
				$record->setNextDueOdo($kept->nextOdo);
				if (!ReminderEngine::tells($reminder, $kept)) {
					// Closed again, the reminder would move on with no due moved. Kept, the link
					// loses nothing, and the next occurrence's snooze comes back by the later day.
					$this->snoozeAtLeast($reminder, $nextSnooze);

					return true;
				}
			}
			// Taking the close back left the later of the two snoozes. Any other was set while the
			// record was deleted, and is the one this close replaces now.
			$snoozed = $reminder->getSnoozedUntil()?->format('Y-m-d');
			$snoozed = $snoozed === max($kept->snoozedUntil, $nextSnooze) ? $kept->snoozedUntil : $snoozed;
			if ($kept->occurrence === null) {
				$this->closeBy($reminder, $record, $nextSnooze);
			} else {
				// standsAt() found the reminder at what the record kept, so the record keeps it as it is.
				$this->writeClosed($reminder, ReminderEngine::reclose($reminder, $kept), $nextSnooze);
			}
			$record->setClosedSnoozedUntil($snoozed);

			return true;
		}

		return false;
	}

	/**
	 * Snoozes the reminder until that day, if it is later than its own snooze, and writes it.
	 *
	 * @param ?string $day a plain day
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function snoozeAtLeast(Reminder $reminder, ?string $day): void {
		$snoozed = $reminder->getSnoozedUntil()?->format('Y-m-d');
		// The later of two, as ReminderEngine::retreat() takes it.
		if (max($snoozed, $day) === $snoozed) {
			return;
		}
		$reminder->setSnoozedUntil(new \DateTime((string)$day));
		$this->reevaluate($reminder);
		$this->writeMoved($reminder);
	}

	/**
	 * Undo of a record's delete with the later deletes on its reminder replayed
	 * (docs/architecture.md#reminder-engine). Each later record is undone, newest first, as
	 * restoreBy() undoes it; `$restore` then restores the record; each later record is deleted
	 * again, oldest first, as takeBack() deletes it. That leaves what those deletes give had the
	 * record's own delete never been made, except for a later record from before: it is closed
	 * again from its own day and counter, so from then on it keeps what the reminder held at that
	 * point. The later records keep their `deleted_at` and their token, so each one's own undo
	 * still matches.
	 *
	 * The reminder and the notices follow the net change alone. The reminder's token moves once,
	 * and only if it ended elsewhere or in another state. Its notice is withdrawn once, or the points that moved within
	 * its occurrence ring again; so do those of each live record above whose kept due moved.
	 *
	 * @template T
	 * @param Reminder $reminder live: a later delete's take-back leaves a deleted one as it is
	 * @param list<Maintenance> $later newest first (MaintenanceMapper::findDeletedAfter())
	 * @param \Closure(): T $restore restores the record
	 * @return T what `$restore` returns
	 * @throws ReplayRefused if a later record would no longer close the reminder, or `$restore`
	 *                       throws it; the caller rolls back what was written
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function replay(Reminder $reminder, array $later, \Closure $restore): mixed {
		$before = clone $reminder;
		$deletedAt = [];
		$this->replaying = [];
		try {
			foreach ($later as $one) {
				$deletedAt[(int)$one->getId()] = $one->getDeletedAt();
				$one->setDeletedAt(null);
				$this->records->writeDeletedAt($one);
				if (!$this->restoreBy($this->reread($reminder), $one)) {
					throw new ReplayRefused('record ' . (int)$one->getId() . ' would no longer close its reminder');
				}
				$this->records->writeKept($one);
			}
			$result = $restore();
			foreach (array_reverse($later) as $one) {
				// Read again: the restore or another replayed undo may have written what it keeps.
				$one = $this->records->findAnyById((int)$one->getId());
				$one->setDeletedAt($deletedAt[(int)$one->getId()]);
				$this->records->writeDeletedAt($one);
				$this->takeBack($this->reread($reminder), $one, deleted: true);
			}
			$moved = $this->replaying;
		} finally {
			$this->replaying = null;
		}

		$after = $this->reread($reminder);
		if (self::course($before) !== self::course($after)) {
			$after = $this->reminders->updateChecked($after, $after->getUpdatedAt());
		}
		if (self::noticeOf($before) !== self::noticeOf($after)) {
			$this->after->defer(fn () => $this->notifications->withdraw($after));
		} else {
			$this->rearm($before, $after);
		}
		foreach ($moved as $id => $was) {
			$above = $this->records->findAnyById($id);
			if ($above->getDeletedAt() === null) {
				$this->rearmKept($after, $was, self::kept($above));
			}
		}

		return $result;
	}

	/**
	 * What a close or its take-back moves (ReminderMapper::writeOnTheWay()), read from the mapper's
	 * list, so a column it writes without the token cannot be missed here.
	 *
	 * @return list<mixed>
	 */
	private static function course(Reminder $reminder): array {
		return array_map(static function (string $property) use ($reminder): mixed {
			$value = $reminder->{'get' . ucfirst($property)}();

			return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
		}, ReminderMapper::COURSE);
	}

	/**
	 * What closeBy() and reopenBy() withdraw the notice for when it changes: the occurrence, its
	 * snooze, and whether it is over. A state evaluated again on the way is not a change.
	 *
	 * @return array{int, ?string, bool}
	 */
	private static function noticeOf(Reminder $reminder): array {
		return [$reminder->getOccurrence(), $reminder->getSnoozedUntil()?->format('Y-m-d'), ReminderEngine::over($reminder)];
	}

	/**
	 * The reminder as the database holds it now, deleted or not.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function reread(Reminder $reminder): Reminder {
		$id = (int)$reminder->getId();

		return $this->reminders->findAnyByIds($reminder->getVehicleId(), [$id])[$id];
	}

	/**
	 * Whether the record's close is still the one in effect: on top, or under the record above. A
	 * one-off has no record above.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function inEffect(Reminder $reminder, Maintenance $record): bool {
		return $this->onTop($reminder, $record) || (ReminderEngine::recurs($reminder) && $this->above($reminder, $record) !== null);
	}

	/**
	 * Whether the record's close is the one on top: the reminder stands where it left it
	 * (ReminderEngine::onTop()), or, for a one-off, which has no due to tell two closes apart, the
	 * record made it done (doneBy()); and no other live record closed it as it stands
	 * (claimedByAnother()).
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function onTop(Reminder $reminder, Maintenance $record): bool {
		$stands = ReminderEngine::recurs($reminder)
			? ReminderEngine::onTop($reminder, self::dayOf($record), $record->getOdo(), self::kept($record))
			: self::doneBy($reminder, $record);

		return $stands && !$this->claimedByAnother($reminder, $record);
	}

	/**
	 * Whether a reminder that does not recur is done by this record's close. A one-off's close does
	 * not move the occurrence, so the record that made it done closed the current one; a record that
	 * closed an older one closed it while it still recurred. A record from before kept no
	 * occurrence, so it is taken at its word.
	 */
	private static function doneBy(Reminder $reminder, Maintenance $record): bool {
		$occurrence = $record->getClosedOccurrence();

		return $reminder->getState() === Reminder::DONE && ($occurrence === null || $occurrence === $reminder->getOccurrence());
	}

	/**
	 * The live record on the same reminder that this record handed its values to when its close
	 * was taken back, if there is one.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function handedTo(Reminder $reminder, Maintenance $record): ?Maintenance {
		$id = $record->getHandedTo();
		if ($id === null) {
			return null;
		}
		try {
			$taker = $this->records->findAnyById($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
			return null;
		}

		return $taker->getDeletedAt() === null && $taker->getVehicleId() === $record->getVehicleId()
			&& $taker->getReminderId() === $reminder->getId() ? $taker : null;
	}

	/**
	 * Where a reminder stands now that its occurrence starts afresh: the old state must not carry
	 * over, so it is evaluated from planned, against the newest counter.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function reevaluate(Reminder $reminder): void {
		$reminder->setState(Reminder::PLANNED);
		$odo = $this->readings->findNewestAtOrBefore($reminder->getVehicleId(), OdoReading::MAIN, $this->time->getTime())?->getValue();
		$reminder->setState(ReminderEngine::evaluate($reminder, $this->today(), $odo)['state']);
	}

	/**
	 * Sets on the record the due, snooze and occurrence the reminder holds now, which its close
	 * replaces and taking the close back puts back, and the due the close sets from the record's
	 * day and counter; none for a record that closes no reminder. The next occurrence's snooze is
	 * only kept once the close is taken back. The caller writes it.
	 *
	 * Here the null rule holds or breaks (ClosedDue): a due is kept on each axis the reminder has,
	 * and one is set on each axis it recurs by.
	 */
	public static function keep(Maintenance $record, ?Reminder $reminder): void {
		// Counted here, not after the close, so a record is inserted with it.
		[$nextDay, $nextOdo] = $reminder === null ? [null, null] : ReminderEngine::nextDue($reminder, self::dayOf($record), $record->getOdo());
		$record->setNextDueDate($nextDay);
		$record->setNextDueOdo($nextOdo);
		// As plain days: the setter makes each a DateTime of the record's own.
		$record->setClosedDueDate($reminder?->getDueDate()?->format('Y-m-d'));
		$record->setClosedDueOdo($reminder?->getDueOdo());
		$record->setClosedSnoozedUntil($reminder?->getSnoozedUntil()?->format('Y-m-d'));
		$record->setClosedOccurrence($reminder?->getOccurrence());
		$record->setNextSnoozedUntil(null);
		$record->setHandedTo(null);
		$record->setKeptOccurrence(null);
		$record->setTookBack(null);
	}

	/**
	 * The `kept_occurrence` to store for a kept due of that occurrence on a record that closed
	 * `$closedOccurrence`: null when it is the record's own, as ClosedDue::returnsTo() reads it.
	 */
	private static function keptOccurrence(int $occurrence, ?int $closedOccurrence): ?int {
		return $occurrence === $closedOccurrence ? null : $occurrence;
	}

	/** What keep() set on the record, as the engine reads it. */
	private static function kept(Maintenance $record): ClosedDue {
		return new ClosedDue(
			$record->getClosedDueDate()?->format('Y-m-d'),
			$record->getClosedDueOdo(),
			$record->getClosedSnoozedUntil()?->format('Y-m-d'),
			$record->getClosedOccurrence(),
			$record->getKeptOccurrence(),
			$record->getNextDueDate()?->format('Y-m-d'),
			$record->getNextDueOdo(),
		);
	}

	/**
	 * Whether the record states the counter the reminder recurs by, if it recurs by one. Without it
	 * the next due km would be the old one, and the next occurrence due at once.
	 */
	private static function counts(Reminder $reminder, Maintenance $record): bool {
		return $reminder->getRecurOdo() === null || $record->getOdo() !== null;
	}

	/** The plain day the work was done on, where it was done (docs/architecture.md#time). */
	private static function dayOf(Maintenance $record): string {
		return gmdate('Y-m-d', $record->getDoneAt() + $record->getDoneAtOff() * 60);
	}

	/**
	 * A reminder whose occurrence is still running (ReminderEngine::over()).
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function open(int $vehicleId, string $reminderUuid): Reminder {
		$reminder = $this->reminders->findOnVehicle($vehicleId, $reminderUuid);
		if (ReminderEngine::over($reminder)) {
			throw new \InvalidArgumentException('this occurrence is over');
		}

		return $reminder;
	}

	/**
	 * The server's day. A due date is a plain day with no zone (docs/architecture.md#time), so
	 * this is the one place a zone is chosen.
	 */
	private function today(): string {
		return $this->time->now()->format('Y-m-d');
	}

	/**
	 * The template a key names on this vehicle: a service interval, or the inspection its
	 * jurisdiction requires for its type.
	 *
	 * @throws \InvalidArgumentException if the vehicle offers no such template
	 */
	private function template(Vehicle $vehicle, string $key): ReminderTemplate {
		foreach ($this->offered($vehicle) as $template) {
			if ($template->key === $key) {
				return $template;
			}
		}

		throw new \InvalidArgumentException('template_key is not a template this vehicle offers');
	}

	/** @return list<ReminderTemplate> the service intervals, then the inspection where there is one */
	private function offered(Vehicle $vehicle): array {
		$offered = $this->services->all();
		$scheme = $this->jurisdictions->get($vehicle->getJurisdiction())->inspectionScheme();
		if ($scheme !== null) {
			$offered[] = $scheme->template($vehicle->getVehicleType());
		}

		return $offered;
	}

	/**
	 * What the sheet sets, checked against the mode: a date reminder takes no counter and an
	 * odometer one no date, since a field the engine never reads is one the sheet shows wrong.
	 *
	 * @param array<string, mixed> $fields
	 * @param ?ReminderTemplate $defaults what fills a field the request left out
	 * @throws \InvalidArgumentException
	 */
	private function apply(Reminder $reminder, array $fields, ?ReminderTemplate $defaults): void {
		$title = Field::read('title', 'text', 255, $fields['title'] ?? null);
		if ($reminder->getTemplateKey() === null && $title === null) {
			throw new \InvalidArgumentException('title is a field every reminder without a template carries');
		}
		if ($reminder->getTemplateKey() !== null && $title !== null) {
			// A template's title translates; a stored one would not (CONTEXT.md, Reminder Template).
			throw new \InvalidArgumentException('title comes from the template');
		}
		$reminder->setTitle(is_string($title) ? $title : null);

		$mode = Field::read('mode', 'word', [Reminder::DATE, Reminder::ODO, Reminder::EITHER], $fields['mode'] ?? null) ?? $defaults?->mode;
		if (!is_string($mode)) {
			throw new \InvalidArgumentException('mode is a field every reminder carries');
		}
		$reminder->setMode($mode);
		$byDate = $mode !== Reminder::ODO;
		$byOdo = $mode !== Reminder::DATE;

		$dueDate = $fields['due_date'] ?? null;
		$dueDate = $dueDate === null || $dueDate === '' ? null : Field::day('due_date', $dueDate);
		$dueOdo = self::count('due_odo', $fields);
		// A field the request names, even empty, is one the user cleared; only an absent one is
		// the template's to fill.
		$filled = static fn (string $column, bool $read, ?int $default): ?int => array_key_exists($column, $fields) || !$read
			? self::count($column, $fields)
			: $default;
		$leadOdo = $filled('lead_odo', $byOdo, $defaults?->leadOdo);
		$recurMonths = $filled('recur_months', $byDate, $defaults?->recurMonths);
		$recurOdo = $filled('recur_odo', $byOdo, $defaults?->recurOdo);

		if ($byDate && $dueDate === null) {
			throw new \InvalidArgumentException('due_date is a field every reminder by date carries');
		}
		if ($byOdo && $dueOdo === null) {
			throw new \InvalidArgumentException('due_odo is a field every reminder by odometer carries');
		}
		if (!$byDate && ($dueDate !== null || $recurMonths !== null)) {
			throw new \InvalidArgumentException('due_date and recur_months are for a reminder by date');
		}
		if (!$byOdo && ($dueOdo !== null || $leadOdo !== null || $recurOdo !== null)) {
			throw new \InvalidArgumentException('due_odo, lead_odo and recur_odo are for a reminder by odometer');
		}
		if ($byDate && $byOdo && ($recurMonths === null) !== ($recurOdo === null)) {
			// The next occurrence would keep the old due on the other axis, and be due at once. And a
			// record closing it would keep a due where its close sets none (the null rule, ClosedDue).
			throw new \InvalidArgumentException('a reminder by either recurs by both or by neither');
		}
		if ($recurMonths === 0 || $recurOdo === 0) {
			// It would fall due again the moment it was done.
			throw new \InvalidArgumentException('a recurrence is more than zero');
		}

		$reminder->setDueDate($dueDate);
		$reminder->setDueOdo($dueOdo);
		$reminder->setLeadOdo($leadOdo);
		$reminder->setRecurMonths($recurMonths);
		$reminder->setRecurOdo($recurOdo);

		// An unanswered point is the column's default, as the entity reads it.
		$fresh = new Reminder();
		$reminder->setWarnMonthBefore(self::flag('warn_month_before', $fields) ?? $fresh->getWarnMonthBefore());
		$reminder->setWarnMonthStart(self::flag('warn_month_start', $fields) ?? $fresh->getWarnMonthStart());
		$reminder->setWarnDueDate(self::flag('warn_due_date', $fields) ?? $fresh->getWarnDueDate());
	}

	/**
	 * @param key-of<self::BOUNDS> $column
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private static function count(string $column, array $fields): ?int {
		$value = Field::read($column, 'count', self::BOUNDS[$column], $fields[$column] ?? null);

		return is_int($value) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private static function flag(string $column, array $fields): ?bool {
		$value = Field::read($column, 'flag', null, $fields[$column] ?? null);

		return is_bool($value) ? $value : null;
	}
}
