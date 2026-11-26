<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\CostCentreService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The owner's cost centres. Every rule lives in CostCentreService, including who may read and who
 * may write.
 */
class CostCentreController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private CostCentreService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	private function missing(): string {
		return 'No such cost centre';
	}

	/** The caller's own list and those of the owners whose vehicles they reach. */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId())));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * The code, the name and whether it is archived, checked against the token the client read.
	 *
	 * @param string $record the cost centre's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $record): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $record, $token, $this->request->getParams()));
	}

	/**
	 * Only while nothing names it (CostCentreService::delete()).
	 *
	 * @param string $record the cost centre's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $record): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $record, $token));
	}
}
