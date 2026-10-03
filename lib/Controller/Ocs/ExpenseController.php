<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\ExpenseService;
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
 * A vehicle's Expenses through the OCS door: the twin of the internal ExpenseController
 * (docs/api.md).
 *
 * @psalm-import-type NextFleetExpense from ResponseDefinitions
 * @psalm-import-type NextFleetExpensePrefill from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class ExpenseController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ExpenseService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Log an Expense
	 *
	 * Answered as the server wrote it.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $spent_at required: when, unix seconds
	 * @param int|null $spent_at_off required: its UTC offset in minutes
	 * @param int|null $amount required: cents paid
	 * @param string|null $category insurance, tax, toll, parking, fine, lease or other
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $notes what it was
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetExpense, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 201: the Expense
	 * 400: a field is not what it holds; the message names it
	 * 412: the vehicle changed meanwhile; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(
		string $uuid,
		mixed $spent_at = null,
		mixed $spent_at_off = null,
		mixed $amount = null,
		mixed $category = null,
		mixed $vat_rate = null,
		mixed $notes = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * What an Expense form starts from
	 *
	 * The VAT rate on the day `at` falls on, or none for a category the jurisdiction charges no VAT
	 * on.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $at required: the moment the form is on, unix seconds
	 * @param int|null $off required: its UTC offset in minutes
	 * @param string|null $category the category picked, if one is
	 * @return DataResponse<Http::STATUS_OK, NextFleetExpensePrefill, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not log on this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the rate
	 * 400: `at` or `off` is missing or not a whole number, or the category is none
	 */
	#[NoAdminRequired]
	public function prefill(string $uuid, mixed $at = null, mixed $off = null, mixed $category = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->prefill($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * Edit an Expense
	 *
	 * Checked against the `updated_at` it carries. A PUT states the whole Expense: a field left out
	 * is emptied.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $expense the Expense's uuid
	 * @param int|null $updated_at required: the Expense's `updated_at` as the caller read it
	 * @param int|null $spent_at required: when, unix seconds
	 * @param int|null $spent_at_off required: its UTC offset in minutes
	 * @param int|null $amount required: cents paid
	 * @param string|null $category insurance, tax, toll, parking, fine, lease or other
	 * @param int|null $vat_rate basis points; null is not stated, never zero
	 * @param string|null $notes what it was
	 * @return DataResponse<Http::STATUS_OK, NextFleetExpense, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not edit this Expense
	 * @throws OCSNotFoundException no such vehicle or Expense
	 *
	 * 200: the Expense as edited, with its new `updated_at`
	 * 400: a field is not what it holds; the message names it
	 * 412: the Expense changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		string $uuid,
		string $expense,
		mixed $updated_at = null,
		mixed $spent_at = null,
		mixed $spent_at_off = null,
		mixed $amount = null,
		mixed $category = null,
		mixed $vat_rate = null,
		mixed $notes = null,
	): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $uuid, $expense, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete an Expense
	 *
	 * Answered with the row it left: its `updated_at` is the token the restore takes.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $expense the Expense's uuid
	 * @param int|null $updated_at required: the Expense's `updated_at` as the caller read it
	 * @return DataResponse<Http::STATUS_OK, NextFleetExpense, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this Expense
	 * @throws OCSNotFoundException no such vehicle or Expense
	 *
	 * 200: the Expense as deleted
	 * 400: `updated_at` is missing
	 * 412: the Expense changed since `updated_at`; read it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $expense, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->delete($this->userId(), $uuid, $expense, $this->token()),
		));
	}

	/**
	 * Undo an Expense's delete
	 *
	 * Checked against the token the delete answered; answers a new one.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $expense the Expense's uuid
	 * @param int|null $updated_at required: the `updated_at` the delete answered
	 * @return DataResponse<Http::STATUS_OK, NextFleetExpense, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not delete this Expense
	 * @throws OCSNotFoundException no such vehicle or Expense
	 *
	 * 200: the Expense restored, with its new `updated_at`
	 * 400: `updated_at` is missing
	 * 412: the Expense changed since the delete, or is not deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $expense, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->restore($this->userId(), $uuid, $expense, $this->token()),
		));
	}
}
