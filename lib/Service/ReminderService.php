<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

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

	public function __construct(
		private ReminderMapper $reminders,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IServiceTemplates $services,
		private Jurisdictions $jurisdictions,
		private OdoReadingMapper $readings,
		private ITimeFactory $time,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array<string, mixed>> the vehicle's live reminders, in their wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);

		return array_map(
			static fn (Reminder $reminder): array => $reminder->jsonSerialize(),
			$this->reminders->findByVehicle((int)$vehicle->getId()),
		);
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

		return $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->reminders->findOnVehicle((int)$vehicle->getId(), $reminderUuid);

			return $this->reminders->softDelete($reminder, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
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

		return $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt, $day): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$reminder = $this->open((int)$vehicle->getId(), $reminderUuid);
			$reminder->setSnoozedUntil($day);
			$reminder->setState(Reminder::SNOOZED);

			return $this->reminders->updateChecked($reminder, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
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

		return $this->atomicRetry(function () use ($vehicle, $reminderUuid, $expectedUpdatedAt): array {
			$vehicleId = (int)$vehicle->getId();
			$this->vehicles->hold($vehicleId);
			$reminder = $this->open($vehicleId, $reminderUuid);

			if (ReminderEngine::advance($reminder, $reminder->getDueDate()?->format('Y-m-d'), $reminder->getDueOdo())) {
				// The new occurrence starts afresh, so the evaluation must not keep the old state.
				$reminder->setState(Reminder::PLANNED);
				$odo = $this->readings->findNewestAtOrBefore($vehicleId, OdoReading::MAIN, $this->time->getTime())?->getValue();
				$reminder->setState(ReminderEngine::evaluate($reminder, $this->today(), $odo)['state']);
			} else {
				$reminder->setSnoozedUntil(null);
				$reminder->setState(Reminder::DISMISSED);
			}

			return $this->reminders->updateChecked($reminder, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
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
		$offered = $this->services->all();
		$scheme = $this->jurisdictions->get($vehicle->getJurisdiction())->inspectionScheme();
		if ($scheme !== null) {
			$offered[] = $scheme->template($vehicle->getVehicleType());
		}

		foreach ($offered as $template) {
			if ($template->key === $key) {
				return $template;
			}
		}

		throw new \InvalidArgumentException('template_key is not a template this vehicle offers');
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
		$leadOdo = self::count('lead_odo', $fields) ?? ($byOdo ? $defaults?->leadOdo : null);
		$recurMonths = self::count('recur_months', $fields) ?? ($byDate ? $defaults?->recurMonths : null);
		$recurOdo = self::count('recur_odo', $fields) ?? ($byOdo ? $defaults?->recurOdo : null);

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
