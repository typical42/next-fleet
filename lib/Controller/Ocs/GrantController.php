<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\GrantService;
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
 * Who else may use one vehicle, through the OCS door: the twin of the internal GrantController
 * (docs/api.md). The owner reads and writes the list; a grantee reads and leaves their own access.
 * Each answer is what the caller now holds or the list as it now stands, and no write carries a
 * token, for the reason the twin gives; a 412 is still answered as the twin answers it
 * (RecipientController).
 *
 * @psalm-import-type NextFleetGrant from ResponseDefinitions
 * @psalm-import-type NextFleetHeld from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class GrantController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private GrantService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List a vehicle's grants
	 *
	 * The owner's alone to read.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetGrant>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the grants
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * Grant a user or a group a role
	 *
	 * The grantee is told.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string|null $grantee required: the user's or the group's id
	 * @param string|null $grantee_type required: user or group
	 * @param string|null $role required: manager, driver or viewer
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetGrant>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the grants as they now stand
	 * 400: no grantee the owner may grant to, the grantee is the owner, or the role is none
	 * 412: another write to the grant raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid, mixed $grantee = null, mixed $grantee_type = null, mixed $role = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->grant($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * Change a grant's role
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $grant the grant's uuid
	 * @param string|null $role required: manager, driver or viewer
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetGrant>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle or grant
	 *
	 * 200: the grants as they now stand
	 * 400: the role is none
	 * 412: another write to the grant raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $grant, mixed $role = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->change($this->userId(), $uuid, $grant, $this->request->getParams()),
		));
	}

	/**
	 * Revoke a grant
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $grant the grant's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetGrant>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle or grant
	 *
	 * 200: the grants as they now stand
	 * 400: the request is not one this route takes
	 * 412: another write to the grant raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $grant): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->revoke($this->userId(), $uuid, $grant)));
	}

	/**
	 * What the caller holds on a vehicle
	 *
	 * Their own grant's role, and each group's that reaches them.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetHeld, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: what they hold
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function held(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->held($this->userId(), $uuid)));
	}

	/**
	 * Leave a vehicle
	 *
	 * Gives back the caller's own grant; answers with what they still hold through a group.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetHeld, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle, or no grant of the caller's own on it
	 *
	 * 200: what they still hold
	 * 400: the request is not one this route takes
	 * 412: another write to the grant raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function leave(string $uuid): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->leave($this->userId(), $uuid)));
	}
}
