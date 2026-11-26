<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\BudgetService;
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
 * A vehicle's budgets through the OCS door: the twin of the internal BudgetController
 * (docs/api.md).
 *
 * @psalm-import-type NextFleetBudget from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class BudgetController extends OCSController {
	use OcsAnswers;

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

	/**
	 * List a vehicle's budgets
	 *
	 * By year. Empty for a caller the vehicle's costs are kept from: a budget is money.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetBudget>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the budgets
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * Set the budget of a year
	 *
	 * The owner's alone. One per vehicle and calendar year, in the vehicle's currency.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $year required: the calendar year, in the owner's time zone
	 * @param int|null $amount required: cents, above 0
	 * @param bool|null $net required: whether the amount is net of VAT; the costs are counted the same way
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetBudget, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller does not own the vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the budget
	 * 400: a field is not what it holds, the vehicle has no currency (`reason` `no_currency`), or it has a budget for the year (`year_taken`)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid, mixed $year = null, mixed $amount = null, mixed $net = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Change a budget
	 *
	 * The owner's alone. The amount and whether it is net; the year stays. A share of it the
	 * year's costs no longer reach is warned of again.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $budget the budget's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read
	 * @param int|null $amount required: cents, above 0
	 * @param bool|null $net required: whether the amount is net of VAT
	 * @return DataResponse<Http::STATUS_OK, NextFleetBudget, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the vehicle
	 * @throws OCSNotFoundException no such vehicle or budget
	 *
	 * 200: the budget as it now stands
	 * 400: a field is not what it holds
	 * 412: the budget changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $budget, mixed $updated_at = null, mixed $amount = null, mixed $net = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $budget, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete a budget
	 *
	 * The owner's alone. There is no undo: set the year's budget again.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $budget the budget's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read, in the query string
	 * @return DataResponse<Http::STATUS_OK, NextFleetBudget, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the vehicle
	 * @throws OCSNotFoundException no such vehicle or budget
	 *
	 * 200: the budget as deleted
	 * 400: `updated_at` is missing
	 * 412: the budget changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $budget, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->delete($this->userId(), $uuid, $budget, $this->token())));
	}
}
