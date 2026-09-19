<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\ExpenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Expenses that hang off one vehicle. Every rule lives in ExpenseService, including the access
 * check.
 */
class ExpenseController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ExpenseService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/** What the sheet fills in before the person types anything: the VAT rate on the day. */
	#[NoAdminRequired]
	public function prefill(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->prefill($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * @param string $expense the Expense's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $expense): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $uuid, $expense, $token, $this->request->getParams()));
	}

	/**
	 * @param string $expense the Expense's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $expense): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $uuid, $expense, $token));
	}

	/**
	 * @param string $expense the Expense's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $expense): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->restore($this->userId(), $uuid, $expense, $token));
	}
}
