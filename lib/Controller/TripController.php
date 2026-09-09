<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\TripService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The trips that hang off one vehicle. Every rule lives in TripService, including the access
 * check.
 */
class TripController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TripService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Answers with the row the server wrote, so the sheet learns what it was given rather than
	 * assuming what it sent - `reconciled` is not a field a client fills in.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		try {
			$trip = $this->service->record($this->userId(), $uuid, $this->request->getParams());

			return new DataResponse($trip, Http::STATUS_CREATED);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) {
			// The route requires a login, so this is a broken container rather than an anonymous
			// request.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
