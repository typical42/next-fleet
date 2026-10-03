<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\RecipientService;
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
 * Who one vehicle's reminders go to, through the OCS door: the twin of the internal
 * RecipientController (docs/api.md). Each answer is the list as it now stands, and no write carries
 * a token, for the reason the twin gives. A write still maps every refusal the twin maps, a 412
 * included, so the two doors cannot answer one failure differently.
 *
 * @psalm-import-type NextFleetRecipient from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class RecipientController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private RecipientService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List who a vehicle's reminders go to
	 *
	 * To whoever may change the list.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * Add a reminder recipient
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string|null $user_id required: the account; being on the list grants nothing
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients as they now stand
	 * 400: no such account on this instance
	 * 412: another write to the list raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid, mixed $user_id = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->add($this->userId(), $uuid, $this->request->getParams()['user_id'] ?? null),
		));
	}

	/**
	 * Remove a reminder recipient
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $recipient the account to take off the list
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients as they now stand; an account not on the list is already off it
	 * 400: the request is not one this route takes
	 * 412: another write to the list raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $recipient): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->remove($this->userId(), $uuid, $recipient)));
	}
}
