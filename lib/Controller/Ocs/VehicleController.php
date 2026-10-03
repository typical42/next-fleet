<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\VehicleService;
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
 * The vehicles through the OCS door: the twin of the internal VehicleController, over the same
 * service calls (docs/api.md).
 *
 * @psalm-import-type NextFleetVehicle from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class VehicleController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private VehicleService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the vehicles the caller may see
	 *
	 * Their own, and those a grant reaches.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetVehicle>, array{}>
	 *
	 * 200: the vehicles
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse(array_map(static fn (Vehicle $vehicle): array => $vehicle->jsonSerialize(), $this->service->list($this->userId())));
	}

	/**
	 * Get one vehicle
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetVehicle, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the vehicle
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function show(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->find($this->userId(), $uuid)->jsonSerialize()));
	}

	/**
	 * Create a vehicle
	 *
	 * The caller's own. A field left out takes the caller's default: their jurisdiction's units and
	 * currency, a car in service.
	 *
	 * @param string|null $plate the plate, a label and never the identity
	 * @param string|null $manufacturer the make
	 * @param string|null $model the model
	 * @param string|null $vehicle_type car, van, truck, trailer, tractor or generator
	 * @param string|null $engine petrol, diesel, lpg, cng, electric or hybrid
	 * @param list<string>|null $energy_types what it fills up with: petrol, diesel, lpg, cng, electric
	 * @param int|null $tank_ml the tank in millilitres
	 * @param int|null $battery_wh the battery in watt-hours
	 * @param string|null $first_reg first registered, YYYY-MM-DD
	 * @param string|null $disposed_at sold or scrapped, YYYY-MM-DD
	 * @param string|null $vin the vehicle identification number
	 * @param string|null $odo_unit km, or h for engine hours
	 * @param string|null $second_unit h, for a vehicle that counts engine hours beside km
	 * @param int|null $purchase_price in cents
	 * @param int|null $residual_est what it would sell for, in cents
	 * @param string|null $currency an ISO 4217 code
	 * @param string|null $jurisdiction the country whose rules it is kept under, such as de
	 * @param bool|null $logbook_mode trips are kept as a tax logbook
	 * @param string|null $lifecycle active, laid_up or disposed
	 * @param int|null $retention_months how long its records are kept
	 * @param string|null $color a colour, as the owner names it
	 * @param string|null $notes anything else
	 * @param string|null $reminder_mail off, daily, weekly or monthly
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetVehicle, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException never for a create; listed because every write can answer it
	 * @throws OCSNotFoundException never for a create; listed because every write can answer it
	 *
	 * 201: the vehicle
	 * 400: a field is not what it holds; the message names it
	 * 412: never for a create; listed because every write can answer it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		mixed $plate = null,
		mixed $manufacturer = null,
		mixed $model = null,
		mixed $vehicle_type = null,
		mixed $engine = null,
		mixed $energy_types = null,
		mixed $tank_ml = null,
		mixed $battery_wh = null,
		mixed $first_reg = null,
		mixed $disposed_at = null,
		mixed $vin = null,
		mixed $odo_unit = null,
		mixed $second_unit = null,
		mixed $purchase_price = null,
		mixed $residual_est = null,
		mixed $currency = null,
		mixed $jurisdiction = null,
		mixed $logbook_mode = null,
		mixed $lifecycle = null,
		mixed $retention_months = null,
		mixed $color = null,
		mixed $notes = null,
		mixed $reminder_mail = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $this->request->getParams())->jsonSerialize(),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Edit a vehicle
	 *
	 * Checked against the `updated_at` it carries. A field left out stays as it is; a field sent
	 * empty is emptied.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $updated_at required: the vehicle's `updated_at` as the caller read it
	 * @param string|null $plate the plate, a label and never the identity
	 * @param string|null $manufacturer the make
	 * @param string|null $model the model
	 * @param string|null $vehicle_type car, van, truck, trailer, tractor or generator
	 * @param string|null $engine petrol, diesel, lpg, cng, electric or hybrid
	 * @param list<string>|null $energy_types what it fills up with: petrol, diesel, lpg, cng, electric
	 * @param int|null $tank_ml the tank in millilitres
	 * @param int|null $battery_wh the battery in watt-hours
	 * @param string|null $first_reg first registered, YYYY-MM-DD
	 * @param string|null $disposed_at sold or scrapped, YYYY-MM-DD
	 * @param string|null $vin the vehicle identification number
	 * @param string|null $odo_unit km, or h for engine hours
	 * @param string|null $second_unit h, for a vehicle that counts engine hours beside km
	 * @param int|null $purchase_price in cents
	 * @param int|null $residual_est what it would sell for, in cents
	 * @param string|null $currency an ISO 4217 code
	 * @param string|null $jurisdiction the country whose rules it is kept under, such as de
	 * @param bool|null $logbook_mode trips are kept as a tax logbook
	 * @param string|null $lifecycle active, laid_up or disposed
	 * @param int|null $retention_months how long its records are kept
	 * @param string|null $color a colour, as the owner names it
	 * @param string|null $notes anything else
	 * @param string|null $reminder_mail off, daily, weekly or monthly
	 * @return DataResponse<Http::STATUS_OK, NextFleetVehicle, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the vehicle as edited, with its new `updated_at`
	 * 400: a field is not what it holds; the message names it
	 * 412: the vehicle changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		mixed $updated_at = null,
		mixed $plate = null,
		mixed $manufacturer = null,
		mixed $model = null,
		mixed $vehicle_type = null,
		mixed $engine = null,
		mixed $energy_types = null,
		mixed $tank_ml = null,
		mixed $battery_wh = null,
		mixed $first_reg = null,
		mixed $disposed_at = null,
		mixed $vin = null,
		mixed $odo_unit = null,
		mixed $second_unit = null,
		mixed $purchase_price = null,
		mixed $residual_est = null,
		mixed $currency = null,
		mixed $jurisdiction = null,
		mixed $logbook_mode = null,
		mixed $lifecycle = null,
		mixed $retention_months = null,
		mixed $color = null,
		mixed $notes = null,
		mixed $reminder_mail = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $this->token(), $this->request->getParams())->jsonSerialize(),
		));
	}

	/**
	 * Delete a vehicle
	 *
	 * Answered with the row it left: its `updated_at` is the token the restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $updated_at required: the vehicle's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetVehicle, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the vehicle as deleted
	 * 400: `updated_at` is missing
	 * 412: the vehicle changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $this->token())->jsonSerialize(),
		));
	}

	/**
	 * Undo a vehicle's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetVehicle, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the vehicle restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the vehicle changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $this->token())->jsonSerialize(),
		));
	}
}
