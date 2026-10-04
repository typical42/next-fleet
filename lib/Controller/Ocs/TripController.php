<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\TripService;
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
 * A vehicle's trips through the OCS door: the twin of the internal TripController (docs/api.md).
 *
 * @psalm-import-type NextFleetTrip from ResponseDefinitions
 * @psalm-import-type NextFleetTripPrefill from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 * @psalm-import-type NextFleetBookingConflict from ResponseDefinitions
 */
class TripController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private TripService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Log a trip
	 *
	 * Answered as the server wrote it, with the Reading it left on the counter. Logged from a
	 * booking (`booking_uuid`), it is a 409 naming that booking when the car is not back or the
	 * booking has its trip.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $started_at required: when it set off, unix seconds
	 * @param int|null $started_at_off required: its UTC offset in minutes
	 * @param int|null $ended_at required: when it arrived, unix seconds
	 * @param int|null $ended_at_off required: its UTC offset in minutes
	 * @param int|null $start_odo the counter at the start
	 * @param int|null $end_odo the counter at the end
	 * @param int|null $distance the distance driven, when the counters were not read
	 * @param string|null $from_label where it set off from
	 * @param string|null $to_label where it went
	 * @param string|null $purpose why it was driven
	 * @param string|null $partner whom it was driven for
	 * @param string|null $category required: business, private or commute
	 * @param string|null $booking_uuid the booking it was driven on
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetTrip, array{}>|DataResponse<Http::STATUS_OK, NextFleetTrip, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetBookingConflict, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle, or the booking is not theirs
	 * @throws OCSNotFoundException no such vehicle or booking
	 *
	 * 201: the trip
	 * 200: the trip an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds; the message names it
	 * 409: the booking is not back yet, or has its trip
	 * 412: a row the trip follows changed meanwhile; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $started_at = null,
		mixed $started_at_off = null,
		mixed $ended_at = null,
		mixed $ended_at_off = null,
		mixed $start_odo = null,
		mixed $end_odo = null,
		mixed $distance = null,
		mixed $from_label = null,
		mixed $to_label = null,
		mixed $purpose = null,
		mixed $partner = null,
		mixed $category = null,
		mixed $booking_uuid = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->booked(fn (): DataResponse => $this->created(
			fn (): array => $this->service->record($this->userId(), $uuid, $this->request->getParams())->jsonSerialize(),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Suggestions for a trip form
	 *
	 * The places, purposes and partners of this vehicle's own trips, the category the caller last
	 * entered here, and how the vehicle's last trip ended.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetTripPrefill, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the suggestions
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function prefill(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->prefill($this->userId(), $uuid)));
	}

	/**
	 * Edit a trip
	 *
	 * Checked against the `updated_at` it carries; allowed whenever it arrives, and marked late
	 * under Logbook Mode. A PUT states the whole trip: a field left out is emptied.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $trip the trip's uuid
	 * @param int|null $updated_at required: the trip's `updated_at` as the caller read it
	 * @param int|null $started_at required: when it set off, unix seconds
	 * @param int|null $started_at_off required: its UTC offset in minutes
	 * @param int|null $ended_at required: when it arrived, unix seconds
	 * @param int|null $ended_at_off required: its UTC offset in minutes
	 * @param int|null $start_odo the counter at the start
	 * @param int|null $end_odo the counter at the end
	 * @param int|null $distance the distance driven, when the counters were not read
	 * @param string|null $from_label where it set off from
	 * @param string|null $to_label where it went
	 * @param string|null $purpose why it was driven
	 * @param string|null $partner whom it was driven for
	 * @param string|null $category required: business, private or commute
	 * @return DataResponse<Http::STATUS_OK, NextFleetTrip, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this trip
	 * @throws OCSNotFoundException no such vehicle or trip
	 *
	 * 200: the trip as edited, with its new `updated_at`; a save that changed nothing writes nothing and keeps it
	 * 400: a field is not what it holds; the message names it
	 * 412: the trip changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $trip,
		mixed $updated_at = null,
		mixed $started_at = null,
		mixed $started_at_off = null,
		mixed $ended_at = null,
		mixed $ended_at_off = null,
		mixed $start_odo = null,
		mixed $end_odo = null,
		mixed $distance = null,
		mixed $from_label = null,
		mixed $to_label = null,
		mixed $purpose = null,
		mixed $partner = null,
		mixed $category = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $trip, $this->token(), $this->request->getParams())->jsonSerialize(),
		));
	}

	/**
	 * Delete a trip
	 *
	 * A void under Logbook Mode. Answered with the row it left: its `updated_at` is the token the
	 * restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $trip the trip's uuid
	 * @param int|null $updated_at required: the trip's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetTrip, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this trip
	 * @throws OCSNotFoundException no such vehicle or trip
	 *
	 * 200: the trip as deleted
	 * 400: `updated_at` is missing
	 * 412: the trip changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $trip, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $trip, $this->token())->jsonSerialize(),
		));
	}

	/**
	 * Undo a trip's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $trip the trip's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetTrip, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this trip
	 * @throws OCSNotFoundException no such vehicle or trip
	 *
	 * 200: the trip restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the trip changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $trip, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $trip, $this->token())->jsonSerialize(),
		));
	}

	/**
	 * Close a Gap
	 *
	 * One Reconciliation Trip, from the Gap as the gaps route answered it; a 412 when the Gap no
	 * longer matches.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $trip the uuid of the trip whose claim opened the Gap
	 * @param int|null $distance required: the Gap's `distance`
	 * @param int|null $from_at required: the Gap's `from_at`
	 * @param int|null $to_at required: the Gap's `to_at`
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetTrip, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle or trip
	 *
	 * 201: the Reconciliation Trip
	 * 400: the Gap is not named in full
	 * 412: the vehicle has no such Gap any more; read the gaps again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reconcile(string $uuid, string $trip, mixed $distance = null, mixed $from_at = null, mixed $to_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->reconcile($this->userId(), $uuid, $trip, ...$this->gap())->jsonSerialize(),
			Http::STATUS_CREATED,
		));
	}
}
