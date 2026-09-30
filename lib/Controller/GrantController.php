<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\GrantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Who else may use one vehicle. Every rule lives in GrantService, the access check included. Each
 * route answers with the list as it now stands, and there is no token for the reason
 * RecipientController gives: only the owner writes it, and each write names its grantee.
 */
class GrantController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private GrantService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->grant($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * @param string $grant the grant's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $grant): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->change($this->userId(), $uuid, $grant, $this->request->getParams()),
		));
	}

	/**
	 * @param string $grant the grant's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $grant): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->revoke($this->userId(), $uuid, $grant)));
	}
}
