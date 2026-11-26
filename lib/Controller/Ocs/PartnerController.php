<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\PartnerService;
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
 * The owner's partner list through the OCS door: the twin of the internal PartnerController
 * (docs/api.md). The CSV stays a download (docs/api.md#downloads).
 *
 * @psalm-import-type NextFleetPartner from ResponseDefinitions
 * @psalm-import-type NextFleetPartnerGone from ResponseDefinitions
 * @psalm-import-type NextFleetPartnerTrips from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class PartnerController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private PartnerService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	private function missing(): string {
		return 'No such partner';
	}

	/**
	 * List the caller's partners
	 *
	 * The caller's own list, by name, with address and note.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetPartner>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException never: every caller has a list
	 * @throws OCSNotFoundException never
	 *
	 * 200: the partners
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId())));
	}

	/**
	 * Read a partner
	 *
	 * Whole to the owner of the list; to whoever may log on one of the owner's vehicles, without
	 * address and note.
	 *
	 * @param string $record the partner's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetPartner, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may log on no vehicle of the list's owner
	 * @throws OCSNotFoundException no such partner
	 *
	 * 200: the partner
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function show(string $record): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->show($this->userId(), $record)));
	}

	/**
	 * Rename a partner, or set its address and note
	 *
	 * The whole record: a field left out is emptied. A trip's text stays as it was entered.
	 *
	 * @param string $record the partner's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read
	 * @param string|null $name required: the name, unique in the list
	 * @param string|null $address the address, free text
	 * @param string|null $note a note
	 * @return DataResponse<Http::STATUS_OK, NextFleetPartner, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such partner
	 *
	 * 200: the partner as it now stands
	 * 400: a field is not what it holds, or another partner has the name (`reason` `name_taken`: merge instead)
	 * 412: the partner changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $record, mixed $updated_at = null, mixed $name = null, mixed $address = null, mixed $note = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->update($this->userId(), $record, $this->token(), $this->request->getParams()),
		));
	}

	/**
	 * Merge a partner into another
	 *
	 * Every trip that links it links the other; the trips' texts stay, and the partner is gone.
	 *
	 * @param string $record the uuid of the partner that goes
	 * @param int|null $updated_at required: its `updated_at` as the client read it
	 * @param string|null $into required: the uuid of the partner it goes into, of the same list
	 * @return DataResponse<Http::STATUS_OK, NextFleetPartner, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such partner
	 *
	 * 200: the partner merged into
	 * 400: `into` is not another partner of the list (`reason` `target_gone` for a uuid not on it)
	 * 412: the partner changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function merge(string $record, mixed $updated_at = null, mixed $into = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->merge($this->userId(), $record, $this->token(), $this->request->getParams()['into'] ?? null),
		));
	}

	/**
	 * Delete a partner
	 *
	 * Its name, address and note go, and no trip links it any more; the trips' texts stay. There
	 * is no undo.
	 *
	 * @param string $record the partner's uuid
	 * @param int|null $updated_at required: the `updated_at` the client read, in the query string
	 * @return DataResponse<Http::STATUS_OK, NextFleetPartnerGone, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such partner
	 *
	 * 200: the partner is gone
	 * 400: the request is not one this route takes
	 * 412: the partner changed since it was read
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $record, mixed $updated_at = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->delete($this->userId(), $record, $this->token())));
	}

	/**
	 * A partner's trips over a period
	 *
	 * The live trips on the owner's vehicles that link the partner and set off in the period by
	 * their own clock, with the distance in each unit. The owner's alone.
	 *
	 * @param string $record the partner's uuid
	 * @param string|null $from the first day, YYYY-MM-DD; with `to`, or neither for all of it
	 * @param string|null $to the day after the last
	 * @return DataResponse<Http::STATUS_OK, NextFleetPartnerTrips, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller does not own the list
	 * @throws OCSNotFoundException no such partner
	 *
	 * 200: the trips
	 * 400: the period is not one
	 */
	#[NoAdminRequired]
	public function trips(string $record, mixed $from = null, mixed $to = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->trips($this->userId(), $record, $this->request->getParams())));
	}
}
