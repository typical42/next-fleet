<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\EnergyService;
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
 * A vehicle's fill-ups and charging sessions through the OCS door: the twin of the internal
 * EnergyController (docs/api.md).
 *
 * @psalm-import-type NextFleetEnergy from ResponseDefinitions
 * @psalm-import-type NextFleetEnergyPrefill from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class EnergyController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private EnergyService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Log a fill-up or a charge
	 *
	 * Answered as the server wrote it: a derived `unit_price` and the `flags` are not fields a
	 * client fills in.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $filled_at required: when, unix seconds
	 * @param int|null $filled_at_off required: its UTC offset in minutes
	 * @param string|null $energy required: petrol, diesel, lpg, cng or electric
	 * @param int|null $amount required: millilitres, or watt-hours for electric
	 * @param int|null $unit_price tenths of a cent per litre or kWh
	 * @param int|null $total cents paid
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $station where
	 * @param string|null $location_kind home or public
	 * @param bool|null $full_tank filled up to full; false when left out
	 * @param bool|null $missed_previous a fill-up before this one was not logged
	 * @param bool|null $is_dc a DC fast charge
	 * @param int|null $odo the counter
	 * @param int|null $second_odo the engine hours, on a vehicle that counts them
	 * @param string|null $client_uuid a uuid of the client's for the new row: a retry under it answers that row
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetEnergy, array{}>|DataResponse<Http::STATUS_OK, NextFleetEnergy, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the fill-up
	 * 200: the fill-up an earlier create under the same `client_uuid` wrote
	 * 400: a field is not what it holds; the message names it
	 * 412: a row the fill-up follows changed meanwhile; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $filled_at = null,
		mixed $filled_at_off = null,
		mixed $energy = null,
		mixed $amount = null,
		mixed $unit_price = null,
		mixed $total = null,
		mixed $vat_rate = null,
		mixed $station = null,
		mixed $location_kind = null,
		mixed $full_tank = null,
		mixed $missed_previous = null,
		mixed $is_dc = null,
		mixed $odo = null,
		mixed $second_odo = null,
		mixed $client_uuid = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => $this->created(
			fn (): array => $this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * What a fill-up form starts from
	 *
	 * The VAT rate on the day `at` falls on, and the stations this vehicle has used with the price
	 * each last charged.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $at required: the moment the form is on, unix seconds
	 * @param int|null $off required: its UTC offset in minutes
	 * @return DataResponse<Http::STATUS_OK, NextFleetEnergyPrefill, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the rate and the stations
	 * 400: `at` or `off` is missing or not a whole number
	 */
	#[NoAdminRequired]
	public function prefill(string $uuid, mixed $at = null, mixed $off = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->prefill($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * Edit a fill-up
	 *
	 * Checked against the `updated_at` it carries. A PUT states the whole fill-up: a field left out
	 * is emptied.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $fillUp the fill-up's uuid
	 * @param int|null $updated_at required: the fill-up's `updated_at` as the caller read it
	 * @param int|null $filled_at required: when, unix seconds
	 * @param int|null $filled_at_off required: its UTC offset in minutes
	 * @param string|null $energy required: petrol, diesel, lpg, cng or electric
	 * @param int|null $amount required: millilitres, or watt-hours for electric
	 * @param int|null $unit_price tenths of a cent per litre or kWh
	 * @param int|null $total cents paid
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $station where
	 * @param string|null $location_kind home or public
	 * @param bool|null $full_tank filled up to full; false when left out
	 * @param bool|null $missed_previous a fill-up before this one was not logged
	 * @param bool|null $is_dc a DC fast charge
	 * @param int|null $odo the counter
	 * @param int|null $second_odo the engine hours, on a vehicle that counts them
	 * @return DataResponse<Http::STATUS_OK, NextFleetEnergy, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this fill-up
	 * @throws OCSNotFoundException no such vehicle or fill-up
	 *
	 * 200: the fill-up as edited, with its new `updated_at`
	 * 400: a field is not what it holds; the message names it
	 * 412: the fill-up changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $fillUp,
		mixed $updated_at = null,
		mixed $filled_at = null,
		mixed $filled_at_off = null,
		mixed $energy = null,
		mixed $amount = null,
		mixed $unit_price = null,
		mixed $total = null,
		mixed $vat_rate = null,
		mixed $station = null,
		mixed $location_kind = null,
		mixed $full_tank = null,
		mixed $missed_previous = null,
		mixed $is_dc = null,
		mixed $odo = null,
		mixed $second_odo = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $fillUp, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete a fill-up
	 *
	 * Answered with the row it left: its `updated_at` is the token the restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $fillUp the fill-up's uuid
	 * @param int|null $updated_at required: the fill-up's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetEnergy, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this fill-up
	 * @throws OCSNotFoundException no such vehicle or fill-up
	 *
	 * 200: the fill-up as deleted
	 * 400: `updated_at` is missing
	 * 412: the fill-up changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $fillUp, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $fillUp, $this->token()),
		));
	}

	/**
	 * Undo a fill-up's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $fillUp the fill-up's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetEnergy, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this fill-up
	 * @throws OCSNotFoundException no such vehicle or fill-up
	 *
	 * 200: the fill-up restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the fill-up changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $fillUp, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $fillUp, $this->token()),
		));
	}
}
