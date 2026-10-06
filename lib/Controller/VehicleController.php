<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The vehicle routes the web UI uses. Every rule lives in VehicleService, so what happens here
 * is the translation between a request and an answer
 * (docs/adr/0009-the-ocs-api-v1-is-the-public-contract.md).
 */
class VehicleController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private VehicleService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->list($this->userId()));
	}

	#[NoAdminRequired]
	public function show(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->find($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid): DataResponse {
		return $this->checked(fn (int $token): Vehicle => $this->service->update($this->userId(), $uuid, $token, $this->request->getParams()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid): DataResponse {
		return $this->checked(fn (int $token): Vehicle => $this->service->delete($this->userId(), $uuid, $token));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid): DataResponse {
		return $this->checked(fn (int $token): Vehicle => $this->service->restore($this->userId(), $uuid, $token));
	}
}
