<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\OdometerService;
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
 * A vehicle's Readings through the OCS door: the twin of the internal OdometerController
 * (docs/api.md, docs/architecture.md#odometer-rules).
 *
 * @psalm-import-type NextFleetReading from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class OdometerController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private OdometerService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List a vehicle's Readings
	 *
	 * The live ones, every source's, oldest first.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetReading>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the Readings
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(array_map(
			static fn (OdoReading $reading): array => $reading->jsonSerialize(),
			$this->service->list($this->userId(), $uuid),
		)));
	}

	/**
	 * Log an Odometer Entry
	 *
	 * The counter as read, or the distance driven since the newest Reading before `read_at` -
	 * one of the two. It carries no token: nothing was read it could have lost a race against.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $read_at required: when, unix seconds
	 * @param int|null $read_at_off required: its UTC offset in minutes
	 * @param int|null $value the counter as read; required unless `distance` is given
	 * @param int|null $distance the distance driven since the Reading before
	 * @param string|null $counter main, or second for the engine hours; main when left out
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetReading, array{}>|DataResponse<Http::STATUS_OK, NextFleetReading, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the Reading
	 * 200: the Reading an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds, or both `value` and `distance` are given
	 * 412: a Reading on the chain changed meanwhile; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $read_at = null,
		mixed $read_at_off = null,
		mixed $value = null,
		mixed $distance = null,
		mixed $counter = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => $this->created(
			fn (): array => $this->service->record($this->userId(), $uuid, $this->request->getParams())->jsonSerialize(),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Correct an Odometer Entry
	 *
	 * Checked against the `updated_at` it carries. A Reading another Entry wrote is not one: it
	 * follows that Entry.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reading the Reading's uuid
	 * @param int|null $updated_at required: the Reading's `updated_at` as the caller read it
	 * @param int|null $read_at required: when, unix seconds
	 * @param int|null $read_at_off required: its UTC offset in minutes
	 * @param int|null $value required: the counter as read
	 * @param string|null $counter main, or second for the engine hours; main when left out
	 * @return DataResponse<Http::STATUS_OK, NextFleetReading, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this Reading
	 * @throws OCSNotFoundException no such vehicle or Odometer Entry
	 *
	 * 200: the Reading as corrected, with its new `updated_at`
	 * 400: a field is not what it holds; the message names it
	 * 412: the Reading changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $reading,
		mixed $updated_at = null,
		mixed $read_at = null,
		mixed $read_at_off = null,
		mixed $value = null,
		mixed $counter = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $reading, $this->token(), $this->request->getParams())->jsonSerialize(),
		));
	}

	/**
	 * Delete an Odometer Entry
	 *
	 * Answered with the row it left: its `updated_at` is the token the restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reading the Reading's uuid
	 * @param int|null $updated_at required: the Reading's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetReading, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this Reading
	 * @throws OCSNotFoundException no such vehicle or Odometer Entry
	 *
	 * 200: the Reading as deleted
	 * 400: `updated_at` is missing
	 * 412: the Reading changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $reading, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $reading, $this->token())->jsonSerialize(),
		));
	}

	/**
	 * Undo an Odometer Entry's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reading the Reading's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetReading, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this Reading
	 * @throws OCSNotFoundException no such vehicle or Odometer Entry
	 *
	 * 200: the Reading restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the Reading changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $reading, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $reading, $this->token())->jsonSerialize(),
		));
	}

	/**
	 * Answer a Reading in question: the counter was replaced
	 *
	 * The Reading becomes a `reset`: it stands, and starts a new segment. Any Entry's Reading, and
	 * answering takes what changing that Entry takes. The other answer, a typo, is an edit of the
	 * Entry.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $reading the Reading's uuid
	 * @param int|null $updated_at required: the Reading's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetReading, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not change this Reading's Entry
	 * @throws OCSNotFoundException no such vehicle or live Reading
	 *
	 * 200: the Reading as a reset, with its new `updated_at`
	 * 400: `updated_at` is missing, or the Reading is not in question (`reason`: `not_in_question`)
	 * 412: the Reading changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reset(string $uuid, string $reading, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->reset($this->userId(), $uuid, $reading, $this->token())->jsonSerialize(),
		));
	}
}
