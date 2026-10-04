<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\ReminderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A vehicle's reminders through the OCS door: the twin of the internal ReminderController
 * (docs/api.md). Their state is the engine's (docs/architecture.md#reminder-engine), so no write
 * here sets it.
 *
 * @psalm-import-type NextFleetReminder from ResponseDefinitions
 * @psalm-import-type NextFleetListedReminder from ResponseDefinitions
 * @psalm-import-type NextFleetFleetReminder from ResponseDefinitions
 * @psalm-import-type NextFleetReminderTemplate from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class ReminderController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ReminderService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List a vehicle's reminders
	 *
	 * The live ones, each in the state it stands in now.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetListedReminder>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the reminders
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * List the reminders across the fleet
	 *
	 * Those of every vehicle the caller may see and that is not sold, in one read.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetFleetReminder>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException never: no vehicle is named; listed because every read can answer it
	 * @throws OCSNotFoundException never: no vehicle is named; listed because every read can answer it
	 *
	 * 200: the reminders, each naming its vehicle
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function fleet(): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->fleet($this->userId())));
	}

	/**
	 * List the reminder templates for a vehicle
	 *
	 * What a reminder on this vehicle may start from, with what each fills in.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetReminderTemplate>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the templates
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function templates(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->templates($this->userId(), $uuid)));
	}

	/**
	 * Create a reminder
	 *
	 * From a template's `template_key`, which fills in what the request leaves out, or under the
	 * caller's own `title`. A reminder by date takes `due_date`, one by odometer `due_odo`, one by
	 * either both.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string|null $template_key the template it starts from; then no `title`
	 * @param string|null $title what is due, for a reminder without a template
	 * @param string|null $mode date, odo or either; the template's when left out
	 * @param string|null $due_date the day it is due, YYYY-MM-DD
	 * @param int|null $due_odo the counter it is due at
	 * @param int|null $lead_odo how far before `due_odo` it warns
	 * @param int|null $recur_months it recurs this many months after it is done
	 * @param int|null $recur_odo it recurs this far on the counter after it is done
	 * @param bool|null $warn_month_before warn a month before the due date
	 * @param bool|null $warn_month_start warn when the month it is due in starts
	 * @param bool|null $warn_due_date warn on the due date
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetReminder, array{}>|DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the reminder
	 * 200: the reminder an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds, or does not fit the mode; the message names it
	 * 412: never for a create; listed because every write can answer it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $template_key = null,
		mixed $title = null,
		mixed $mode = null,
		mixed $due_date = null,
		mixed $due_odo = null,
		mixed $lead_odo = null,
		mixed $recur_months = null,
		mixed $recur_odo = null,
		mixed $warn_month_before = null,
		mixed $warn_month_start = null,
		mixed $warn_due_date = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => $this->created(
			fn (): array => $this->service->create($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Edit a reminder
	 *
	 * Checked against the `updated_at` it carries. A PUT states the whole reminder; on one from a
	 * template, a field left out is the template's again.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reminder the reminder's uuid
	 * @param int|null $updated_at required: the reminder's `updated_at` as the caller read it
	 * @param string|null $title what is due, for a reminder without a template
	 * @param string|null $mode date, odo or either; the template's when left out
	 * @param string|null $due_date the day it is due, YYYY-MM-DD
	 * @param int|null $due_odo the counter it is due at
	 * @param int|null $lead_odo how far before `due_odo` it warns
	 * @param int|null $recur_months it recurs this many months after it is done
	 * @param int|null $recur_odo it recurs this far on the counter after it is done
	 * @param bool|null $warn_month_before warn a month before the due date
	 * @param bool|null $warn_month_start warn when the month it is due in starts
	 * @param bool|null $warn_due_date warn on the due date
	 * @return DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle or reminder
	 *
	 * 200: the reminder as edited, with its new `updated_at`
	 * 400: a field is not what it holds, or does not fit the mode; the message names it
	 * 412: the reminder changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $reminder,
		mixed $updated_at = null,
		mixed $title = null,
		mixed $mode = null,
		mixed $due_date = null,
		mixed $due_odo = null,
		mixed $lead_odo = null,
		mixed $recur_months = null,
		mixed $recur_odo = null,
		mixed $warn_month_before = null,
		mixed $warn_month_start = null,
		mixed $warn_due_date = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $reminder, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete a reminder
	 *
	 * Ends it, recurrence and all; answered with the row it left, whose `updated_at` the restore
	 * takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reminder the reminder's uuid
	 * @param int|null $updated_at required: the reminder's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle or reminder
	 *
	 * 200: the reminder as deleted
	 * 400: `updated_at` is missing
	 * 412: the reminder changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $reminder, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $reminder, $this->token()),
		));
	}

	/**
	 * Undo a reminder's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reminder the reminder's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle or reminder
	 *
	 * 200: the reminder restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the reminder changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $reminder, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $reminder, $this->token()),
		));
	}

	/**
	 * Snooze a reminder
	 *
	 * Silences it until the day `until`. The due date stays.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reminder the reminder's uuid
	 * @param int|null $updated_at required: the reminder's `updated_at` as the caller read it
	 * @param string|null $until required: the day it speaks again, YYYY-MM-DD, after today
	 * @return DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle or reminder
	 *
	 * 200: the reminder, snoozed
	 * 400: `until` is not a day after today, or `updated_at` is missing
	 * 412: the reminder changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function snooze(string $uuid, string $reminder, mixed $updated_at = null, mixed $until = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->snooze($this->userId(), $uuid, $reminder, $this->token(), $this->request->getParams()['until'] ?? null),
		));
	}

	/**
	 * Dismiss a reminder's occurrence
	 *
	 * Skips this occurrence: a recurring reminder moves on, one that does not is left dismissed.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reminder the reminder's uuid
	 * @param int|null $updated_at required: the reminder's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetReminder, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle or reminder
	 *
	 * 200: the reminder, moved on or dismissed
	 * 400: `updated_at` is missing
	 * 412: the reminder changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function dismiss(string $uuid, string $reminder, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->dismiss($this->userId(), $uuid, $reminder, $this->token()),
		));
	}
}
