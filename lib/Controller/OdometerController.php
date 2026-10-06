<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Service\OdometerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The readings that hang off one vehicle. Every rule lives in OdometerService
 * (docs/architecture.md#odometer-rules), including the access check.
 */
class OdometerController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private OdometerService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * A new Reading carries no token: nothing was read that it could have lost a race against.
	 * `flagged` is restated from the whole chain rather than edited
	 * (docs/architecture.md#concurrency).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * An Odometer Entry corrected from its row, checked against the token it was read with.
	 *
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->update($this->userId(), $uuid, $reading, $token, $this->request->getParams()));
	}

	/**
	 * Answers with the row the delete left, so the undo toast holds the token the restore is
	 * checked against.
	 *
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->delete($this->userId(), $uuid, $reading, $token));
	}

	/**
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->restore($this->userId(), $uuid, $reading, $token));
	}

	/**
	 * The answer "the counter was replaced" to a Reading in question, any Entry's.
	 *
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reset(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->reset($this->userId(), $uuid, $reading, $token));
	}
}
