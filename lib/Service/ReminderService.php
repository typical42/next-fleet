<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Maintenance;
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
 */
class ReminderService {
	use TTransactional;

	/**
	 * The states a reminder is still open in, most urgent first. A snooze silences it, so it sinks
	 * below one merely planned.
	 */
	private const URGENCY = [Reminder::OVERDUE => 0, Reminder::DUE => 1, Reminder::WARNED => 2, Reminder::PLANNED => 3, Reminder::SNOOZED => 4];

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
	) {
	}

	/**
	 * The state is evaluated at now rather than read from the row: the row holds what the job last
	 * persisted, and the banner shows where the reminder stands. Nothing is written.
	 *
	 * @return list<array<string, mixed>> the vehicle's live reminders, in their wire form, each
	 *                                    with its `estimate` (ReminderEngine::estimate())
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
	 * @return list<array<string, mixed>>
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
	 * @return list<array{vehicle: Vehicle, reminder: array<string, mixed>}>
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
	 * @return list<array{vehicle: Vehicle, reminder: array<string, mixed>}>
	 * @throws \OCP\DB\Exception
	 */
	private function fleetBeside(string $userId): array {
		$rows = [];
		foreach ($this->fleet->list($userId) as $vehicle) {
			if ($vehicle->getLifecycle() === Vehicle::DISPOSED) {
				continue;
			}
			foreach ($this->listed($vehicle) as $row) {
				$rows[] = ['vehicle' => $vehicle, 'reminder' => $row];
			}
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function listed(Vehicle $vehicle): array {
		$reminders = $this->reminders->findByVehicle((int)$vehicle->getId());
		$now = $this->time->now();
		$today = $this->today();
		// Only a reminder by km reads the chain, so a vehicle without one skips the query.
		$chain = array_filter($reminders, static fn (Reminder $r): bool => $r->getMode() !== Reminder::DATE) === []
			? []
			: $this->readings->findChain((int)$vehicle->getId(), OdoReading::MAIN);
		// The newest value, flagged or not, as dismiss() reads it. The chain is oldest first.
		$odo = $chain === [] ? null : $chain[array_key_last($chain)]->getValue();

		return array_map(
			static fn (Reminder $reminder): array => [
				'state' => ReminderEngine::evaluate($reminder, $today, $odo)['state'],
				'estimate' => ReminderEngine::estimate($reminder, $chain, $now),
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
	 * @return list<array{key: string, mode: string, recur_months: ?int, recur_odo: ?int, lead_odo: ?int, first_due_months: ?int}>
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
	 * @return array<string, mixed> the row as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$reminder = new Reminder();
		$reminder->setVehicleId((int)$vehicle->getId());
		$reminder->setCreatedBy($userId);
		$reminder->setState(Reminder::PLANNED);
		$reminder->setOccurrence(1);

		$key = Field::read('template_key', 'text', 32, $fields['template_key'] ?? null);
		$template = is_string($key) ? $this->template($vehicle, $key) : null;
		$reminder->setTemplateKey($template?->key);
		$this->apply($reminder, $fields, $template);

		// Retried for the reason TripService::record() gives.
		$written = $this->atomicRetry(function () use ($vehicle, $reminder): Reminder {
			$this->vehicles->hold((int)$vehicle->getId());

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
	 * @return array<string, mixed> the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the reminder has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $reminderUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		return $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt, $fields): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->reminders->findOnVehicle((int)$vehicle->getId(), $reminderUuid);
			$this->apply($reminder, $fields, null);

			return $this->reminders->updateChecked($reminder, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
	}

	/**
	 * Ends the reminder, recurrence and all. Dismissing skips one occurrence; this is the explicit
	 * act rule 6 asks for, so it takes EDIT, not DELETE.
	 *
	 * @return array<string, mixed> the row as it was left, in its wire form
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
	 * @return array<string, mixed> the row as it now stands, in its wire form
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
	 * @return array<string, mixed> the row as it now stands, in its wire form
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
	 * @return array<string, mixed> the row as it now stands, in its wire form
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
	 * the record's own day and counter (rule 4); one that does not is done.
	 *
	 * @throws \InvalidArgumentException if it recurs by km and the record states no counter
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function closeBy(Reminder $reminder, Maintenance $record): void {
		if ($reminder->getRecurOdo() !== null && $record->getOdo() === null) {
			// The next due km would be the old one, and the next occurrence due at once.
			throw new \InvalidArgumentException('odo is the counter this reminder recurs from');
		}
		if (ReminderEngine::advance($reminder, self::dayOf($record), $record->getOdo())) {
			$this->reevaluate($reminder);
		} else {
			$reminder->setState(Reminder::DONE);
		}
		$this->reminders->updateChecked($reminder, $reminder->getUpdatedAt());
		$this->notifications->withdraw($reminder);
	}

	/**
	 * Takes back what closeBy() did with this record, when nothing has moved the reminder since.
	 * A deleted reminder is left as it was deleted.
	 *
	 * @return bool whether it was taken back
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function reopenBy(Reminder $reminder, Maintenance $record): bool {
		if ($reminder->getDeletedAt() !== null) {
			return false;
		}
		$recurs = $reminder->getRecurMonths() !== null || $reminder->getRecurOdo() !== null;
		if ($recurs ? !ReminderEngine::retreat($reminder, self::dayOf($record), $record->getOdo()) : $reminder->getState() !== Reminder::DONE) {
			return false;
		}
		$this->reevaluate($reminder);
		$this->reminders->updateChecked($reminder, $reminder->getUpdatedAt());
		// The occurrence it sent for is gone, and the job sees no state change to take it back.
		$this->notifications->withdraw($reminder);

		return true;
	}

	/**
	 * Undo of a withdrawal: closes the reminder with the record again, when it stands where
	 * reopenBy() left it. One another record closed meanwhile stays closed by that one.
	 *
	 * @return bool whether it was closed again
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function recloseBy(Reminder $reminder, Maintenance $record): bool {
		if ($reminder->getDeletedAt() !== null || in_array($reminder->getState(), [Reminder::DONE, Reminder::DISMISSED], true)
			|| !ReminderEngine::standsAt($reminder, self::dayOf($record), $record->getOdo())) {
			return false;
		}
		$this->closeBy($reminder, $record);

		return true;
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

	/** The plain day the work was done on, where it was done (docs/architecture.md#time). */
	private static function dayOf(Maintenance $record): string {
		return gmdate('Y-m-d', $record->getDoneAt() + $record->getDoneAtOff() * 60);
	}

	/**
	 * A reminder whose occurrence is still running: done and dismissed are over, so there is
	 * nothing left to silence or skip.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function open(int $vehicleId, string $reminderUuid): Reminder {
		$reminder = $this->reminders->findOnVehicle($vehicleId, $reminderUuid);
		if (in_array($reminder->getState(), [Reminder::DONE, Reminder::DISMISSED], true)) {
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
			// The next occurrence would keep the old due on the other axis, and be due at once.
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
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private static function count(string $column, array $fields): ?int {
		$value = Field::read($column, 'count', null, $fields[$column] ?? null);

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
