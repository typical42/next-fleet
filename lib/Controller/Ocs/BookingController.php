<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\BookingService;
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
 * A vehicle's bookings and their handover through the OCS door: the twin of the internal
 * BookingController (docs/api.md).
 *
 * @psalm-import-type NextFleetBooking from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 * @psalm-import-type NextFleetBookingConflict from ResponseDefinitions
 */
class BookingController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private BookingService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List a vehicle's bookings
	 *
	 * Those that reach past `from` and start before `to`, cancelled ones included, by start.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $from unix seconds; a week ago when left out
	 * @param int|null $to unix seconds; open when left out
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetBooking>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the bookings
	 * 400: `from` or `to` is not an instant
	 */
	#[NoAdminRequired]
	public function index(string $uuid, mixed $from = null, mixed $to = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid, $this->request->getParams())));
	}

	/**
	 * Book a vehicle
	 *
	 * For the caller; a 409 names the booking in the way.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $starts_at required: unix seconds
	 * @param int|null $starts_at_off required: its UTC offset in minutes
	 * @param int|null $ends_at required: unix seconds, after `starts_at` and still to come
	 * @param int|null $ends_at_off required: its UTC offset in minutes
	 * @param string|null $purpose what it is booked for
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetBooking, array{}>|DataResponse<Http::STATUS_OK, NextFleetBooking, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetBookingConflict, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the booking
	 * 200: the booking an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds, or the vehicle is not in service
	 * 409: another booking holds part of the span
	 * 412: never for a create; listed because every write can answer it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $starts_at = null,
		mixed $starts_at_off = null,
		mixed $ends_at = null,
		mixed $ends_at_off = null,
		mixed $purpose = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->booked(fn (): DataResponse => $this->created(
			fn (): array => $this->service->book($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Change a booking
	 *
	 * Moves the span or rewrites the purpose while the car is not yet taken, checked against the
	 * `updated_at` it carries. A PUT states the whole booking.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $booking the booking's uuid
	 * @param int|null $updated_at required: the booking's `updated_at` as the caller read it
	 * @param int|null $starts_at required: unix seconds
	 * @param int|null $starts_at_off required: its UTC offset in minutes
	 * @param int|null $ends_at required: unix seconds, after `starts_at` and still to come
	 * @param int|null $ends_at_off required: its UTC offset in minutes
	 * @param string|null $purpose what it is booked for
	 * @return DataResponse<Http::STATUS_OK, NextFleetBooking, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetBookingConflict, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the booking is not the caller's to change
	 * @throws OCSNotFoundException no such vehicle or booking
	 *
	 * 200: the booking as changed, with its new `updated_at`
	 * 400: a field is not what it holds, or the booking is no longer `booked`
	 * 409: another booking holds part of the new span
	 * 412: the booking changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $booking,
		mixed $updated_at = null,
		mixed $starts_at = null,
		mixed $starts_at_off = null,
		mixed $ends_at = null,
		mixed $ends_at_off = null,
		mixed $purpose = null,
	): DataResponse {
		return $this->booked(fn (): DataResponse => new DataResponse(
			$this->service->change($this->userId(), $uuid, $booking, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Cancel a booking
	 *
	 * Checked against the `updated_at` it carries: the booking stays, `cancelled`.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $booking the booking's uuid
	 * @param int|null $updated_at required: the booking's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetBooking, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the booking is not the caller's to cancel
	 * @throws OCSNotFoundException no such vehicle or booking
	 *
	 * 200: the booking, cancelled
	 * 400: the booking is no longer `booked`, or `updated_at` is missing
	 * 412: the booking changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $booking, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->cancel($this->userId(), $uuid, $booking, $this->token()),
		));
	}

	/**
	 * Take the car
	 *
	 * At the moment the server stamps. No token: what counts is the state the booking is in now.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $booking the booking's uuid
	 * @param int|null $odo required: the counter as the car is taken
	 * @param int|null $at_off required: the caller's UTC offset in minutes now
	 * @param int|null $level the tank or battery, a percentage
	 * @param string|null $notes the state it is in
	 * @return DataResponse<Http::STATUS_OK, NextFleetBooking, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetBookingConflict, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the booking is not the caller's to take
	 * @throws OCSNotFoundException no such vehicle or booking
	 *
	 * 200: the booking, `out`
	 * 400: a field is not what it holds, or the booking is not `booked` or is over
	 * 409: another booking of the vehicle is `out`, or holds the moment
	 * 412: another write to the booking raced this one; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function checkOut(
		string $uuid,
		string $booking,
		mixed $odo = null,
		mixed $at_off = null,
		mixed $level = null,
		mixed $notes = null,
	): DataResponse {
		return $this->booked(fn (): DataResponse => new DataResponse(
			$this->service->checkOut($this->userId(), $uuid, $booking, $this->request->getParams()),
		));
	}

	/**
	 * Give the car back
	 *
	 * As check-out took it; answered with the trip to log as `trip_draft`.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $booking the booking's uuid
	 * @param int|null $odo required: the counter as the car is given back
	 * @param int|null $at_off required: the caller's UTC offset in minutes now
	 * @param int|null $level the tank or battery, a percentage
	 * @param string|null $notes the state it is in
	 * @return DataResponse<Http::STATUS_OK, NextFleetBooking, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the booking is not the caller's to give back
	 * @throws OCSNotFoundException no such vehicle or booking
	 *
	 * 200: the booking, back, with its `trip_draft`
	 * 400: a field is not what it holds, or the car is not out
	 * 412: another write to the booking raced this one; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function checkIn(
		string $uuid,
		string $booking,
		mixed $odo = null,
		mixed $at_off = null,
		mixed $level = null,
		mixed $notes = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->checkIn($this->userId(), $uuid, $booking, $this->request->getParams()),
		));
	}
}
