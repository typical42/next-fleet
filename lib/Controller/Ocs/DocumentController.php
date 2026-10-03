<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\DocumentService;
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
 * One vehicle's papers through the OCS door: the twin of the internal DocumentController, all but
 * the download, which stays a link (docs/api.md#downloads). Each answer is the list as it now
 * stands, and no write carries a token, for the reason the twin gives; a 412 is still answered as
 * the twin answers it (RecipientController).
 *
 * @psalm-import-type NextFleetDocument from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 */
class DocumentController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private DocumentService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List a vehicle's papers
	 *
	 * Each with what the caller may do to it.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetDocument>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the papers
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	/**
	 * Attach a paper
	 *
	 * A file the caller has in their own Files, to the vehicle or to the row `linked_type` and
	 * `linked_uuid` name. There is no upload: a client puts the file there over WebDAV first.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $file_id required: the file's id in Files
	 * @param string|null $kind required: registration, insurance, manual, receipt or photo
	 * @param string|null $linked_type energy, maintenance, expense or booking; with `linked_uuid`
	 * @param string|null $linked_uuid the uuid of the row it backs; with `linked_type`
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetDocument>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not keep papers on this vehicle, or on that row
	 * @throws OCSNotFoundException no such vehicle, file of the caller's own, or linked row
	 *
	 * 200: the papers as they now stand
	 * 400: a field is not what it holds; the message names it
	 * 412: another write to the paper raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid, mixed $file_id = null, mixed $kind = null, mixed $linked_type = null, mixed $linked_uuid = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse(
			$this->service->attach($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * Detach a paper
	 *
	 * The file stays in Files.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $document the document's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetDocument>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not detach this paper
	 * @throws OCSNotFoundException no such vehicle or paper
	 *
	 * 200: the papers as they now stand
	 * 400: the request is not one this route takes
	 * 412: another write to the paper raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $document): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->detach($this->userId(), $uuid, $document)));
	}

	/**
	 * Undo a paper's detach
	 *
	 * No token, for the reason detaching takes none. A paper already back, or whose file is on the
	 * same row again, stays as it is.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $document the document's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetDocument>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException the caller may not keep this paper
	 * @throws OCSNotFoundException no such vehicle or paper, live or detached, or the row it belonged to is deleted
	 *
	 * 200: the papers as they now stand
	 * 400: the request is not one this route takes
	 * 412: another write to the paper raced this one; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $document): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->service->restore($this->userId(), $uuid, $document)));
	}
}
