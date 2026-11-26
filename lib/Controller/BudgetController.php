<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\BudgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A vehicle's budgets. Every rule lives in BudgetService, including who may read and who may write.
 */
class BudgetController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private BudgetService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/** BudgetService does not say whether the vehicle or its budget is gone. */
	private function missing(): string {
		return 'No such vehicle or budget';
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * @param string $budget the budget's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $budget): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $uuid, $budget, $token, $this->request->getParams()));
	}

	/**
	 * @param string $budget the budget's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $budget): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $uuid, $budget, $token));
	}
}
