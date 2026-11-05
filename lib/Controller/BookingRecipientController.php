<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\RecipientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Who hears of one vehicle's bookings. Every rule lives in RecipientService, including the access
 * check; no token, for the reason RecipientController gives.
 */
class BookingRecipientController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private RecipientService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->listBooking($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		$params = $this->request->getParams();

		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->addBooking($this->userId(), $uuid, $params['recipient'] ?? null, $params['recipient_type'] ?? null, $params['events'] ?? null),
		));
	}

	/**
	 * @param string $type `user` or `group`
	 * @param string $recipient the account or the group to take off the list
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $type, string $recipient): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->removeBooking($this->userId(), $uuid, $type, $recipient)));
	}
}
