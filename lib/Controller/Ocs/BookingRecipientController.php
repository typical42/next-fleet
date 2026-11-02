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
 * Who hears of one vehicle's bookings, through the OCS door: the twin of the internal
 * BookingRecipientController (docs/api.md), answering as RecipientController does.
 *
 * @psalm-import-type NextFleetBookingRecipient from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class BookingRecipientController extends OCSController {
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
	 * List who hears of a vehicle's bookings
	 *
	 * To the owner and the managers. An empty list means the owner.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetBookingRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->listBooking($this->userId(), $uuid)));
	}

	/**
	 * Add an account or a group to hear of the bookings
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string|null $recipient required: the account or the group; being on the list grants nothing
	 * @param string|null $recipient_type required: `user` or `group`
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetBookingRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients as they now stand
	 * 400: no such account or group on this instance
	 * 412: another write to the list raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid, mixed $recipient = null, mixed $recipient_type = null): DataResponse {
		$params = $this->request->getParams();

		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->addBooking($this->userId(), $uuid, $params['recipient'] ?? null, $params['recipient_type'] ?? null),
		));
	}

	/**
	 * Remove an account or a group from hearing of the bookings
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $type `user` or `group`
	 * @param string $recipient the account or the group to take off the list
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetBookingRecipient>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the recipients as they now stand; one not on the list is already off it
	 * 400: the type is neither user nor group
	 * 412: another write to the list raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $type, string $recipient): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->removeBooking($this->userId(), $uuid, $type, $recipient)));
	}
}
