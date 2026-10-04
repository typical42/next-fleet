<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\MaintenanceService;
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
 * A vehicle's Maintenance Records through the OCS door: the twin of the internal
 * MaintenanceController (docs/api.md).
 *
 * @psalm-import-type NextFleetMaintenance from ResponseDefinitions
 * @psalm-import-type NextFleetMaintenancePrefill from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class MaintenanceController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private MaintenanceService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Log a Maintenance Record
	 *
	 * Answered as the server wrote it, with the reminder it `closes`.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $done_at required: when, unix seconds
	 * @param int|null $done_at_off required: its UTC offset in minutes
	 * @param string|null $title required: what was done
	 * @param string|null $type service, repair, inspection, tyres or upgrade
	 * @param string|null $vendor who did it
	 * @param int|null $cost cents paid
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $notes anything else
	 * @param int|null $odo the counter
	 * @param int|null $second_odo the engine hours, on a vehicle that counts them
	 * @param string|null $closes the uuid of the reminder this work closes
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetMaintenance, array{}>|DataResponse<Http::STATUS_OK, NextFleetMaintenance, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the record
	 * 200: the record an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds; the message names it
	 * 412: a row the record follows changed meanwhile; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $done_at = null,
		mixed $done_at_off = null,
		mixed $title = null,
		mixed $type = null,
		mixed $vendor = null,
		mixed $cost = null,
		mixed $vat_rate = null,
		mixed $notes = null,
		mixed $odo = null,
		mixed $second_odo = null,
		mixed $closes = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => $this->created(
			fn (): array => $this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * What a record form starts from
	 *
	 * The VAT rate on the day `at` falls on, and the vendors this vehicle has used.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $at required: the moment the form is on, unix seconds
	 * @param int|null $off required: its UTC offset in minutes
	 * @return DataResponse<Http::STATUS_OK, NextFleetMaintenancePrefill, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the rate and the vendors
	 * 400: `at` or `off` is missing or not a whole number
	 */
	#[NoAdminRequired]
	public function prefill(string $uuid, mixed $at = null, mixed $off = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->prefill($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * Edit a Maintenance Record
	 *
	 * Checked against the `updated_at` it carries. A PUT states the whole record: a field left out
	 * is emptied.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $record the record's uuid
	 * @param int|null $updated_at required: the record's `updated_at` as the caller read it
	 * @param int|null $done_at required: when, unix seconds
	 * @param int|null $done_at_off required: its UTC offset in minutes
	 * @param string|null $title required: what was done
	 * @param string|null $type service, repair, inspection, tyres or upgrade
	 * @param string|null $vendor who did it
	 * @param int|null $cost cents paid
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $notes anything else
	 * @param int|null $odo the counter
	 * @param int|null $second_odo the engine hours, on a vehicle that counts them
	 * @param string|null $closes the uuid of the reminder this work closes
	 * @return DataResponse<Http::STATUS_OK, NextFleetMaintenance, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this record
	 * @throws OCSNotFoundException no such vehicle or record
	 *
	 * 200: the record as edited, with its new `updated_at`
	 * 400: a field is not what it holds; the message names it
	 * 412: the record changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $record,
		mixed $updated_at = null,
		mixed $done_at = null,
		mixed $done_at_off = null,
		mixed $title = null,
		mixed $type = null,
		mixed $vendor = null,
		mixed $cost = null,
		mixed $vat_rate = null,
		mixed $notes = null,
		mixed $odo = null,
		mixed $second_odo = null,
		mixed $closes = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $record, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete a Maintenance Record
	 *
	 * Answered with the row it left: its `updated_at` is the token the restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $record the record's uuid
	 * @param int|null $updated_at required: the record's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetMaintenance, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this record
	 * @throws OCSNotFoundException no such vehicle or record
	 *
	 * 200: the record as deleted
	 * 400: `updated_at` is missing
	 * 412: the record changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $record, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $record, $this->token()),
		));
	}

	/**
	 * Undo a Maintenance Record's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $record the record's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetMaintenance, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this record
	 * @throws OCSNotFoundException no such vehicle or record
	 *
	 * 200: the record restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the record changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $record, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $record, $this->token()),
		));
	}
}
