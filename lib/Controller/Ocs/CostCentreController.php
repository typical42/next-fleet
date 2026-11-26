<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\CostCentreService;
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
 * The owner's cost centres through the OCS door: the twin of the internal CostCentreController
 * (docs/api.md).
 *
 * @psalm-import-type NextFleetCostCentre from ResponseDefinitions
 * @psalm-import-type NextFleetCostCentreGone from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class CostCentreController extends OCSController {
	use OcsAnswers;

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

	/**
	 * List cost centres
	 *
	 * The caller's own list and that of every owner whose vehicle the caller reaches, archived ones
	 * too, by owner and name. `user_id` says whose each is.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetCostCentre>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException never: every caller has a list
	 * @throws OCSNotFoundException never
	 *
	 * 200: the cost centres
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId())));
	}

	/**
	 * Add a cost centre to the caller's list
	 *
	 * @param string|null $code a code such as 4711, unique in the list
	 * @param string|null $name required: the name, unique in the list
	 * @return DataResponse<Http::STATUS_CREATED, NextFleetCostCentre, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException never
	 * @throws OCSNotFoundException never
	 *
	 * 201: the cost centre
	 * 400: a field is not what it holds, or another cost centre has the code (`reason` `code_taken`) or the name (`name_taken`)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(mixed $code = null, mixed $name = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Rename a cost centre, or archive it
	 *
	 * The whole record: a field left out is emptied, and `archived` left out takes it back. An
	 * archived cost centre is no longer offered but stays on the entries that name it.
	 *
	 * @param string $record the cost centre's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read
	 * @param string|null $code a code, unique in the list
	 * @param string|null $name required: the name, unique in the list
	 * @param bool|null $archived whether it is archived
	 * @return DataResponse<Http::STATUS_OK, NextFleetCostCentre, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such cost centre
	 *
	 * 200: the cost centre as it now stands
	 * 400: a field is not what it holds, or another cost centre has the code (`reason` `code_taken`) or the name (`name_taken`)
	 * 412: the cost centre changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $record, mixed $updated_at = null, mixed $code = null, mixed $name = null, mixed $archived = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $record, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Delete a cost centre
	 *
	 * Only one that no entry and no vehicle names; archive the others.
	 *
	 * @param string $record the cost centre's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read, in the query string
	 * @return DataResponse<Http::STATUS_OK, NextFleetCostCentreGone, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such cost centre
	 *
	 * 200: the cost centre is gone
	 * 400: an entry or a vehicle names it (`reason` `in_use`)
	 * 412: the cost centre changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $record, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->delete($this->userId(), $record, $this->token())));
	}
}
