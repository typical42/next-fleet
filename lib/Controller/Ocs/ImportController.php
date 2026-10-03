<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\Controller\ImportAnswers;
use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\ImportService;
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
 * Another tool's export through the OCS door: the twin of the internal ImportController.
 *
 * @psalm-import-type NextFleetImportPreview from ResponseDefinitions
 * @psalm-import-type NextFleetImportRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetImportResult from ResponseDefinitions
 * @psalm-import-type NextFleetImportUndone from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class ImportController extends OCSController {
	use OcsAnswers;
	use ImportAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ImportService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Preview an import
	 *
	 * What importing a file from the caller's own Files would create, row by row, without writing
	 * anything. Takes `edit` on a vehicle that is not disposed. A client puts the file there over
	 * WebDAV first. The answer carries the file's etag; the import is checked against it.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $file_id required: the file's id in Files
	 * @param string|null $importer required: lubelogger or spritmonitor
	 * @param string|null $record_type required: what the file holds; for lubelogger fuel, service, repair, upgrade, tax, supplies or odometer, for spritmonitor fuel or costs
	 * @param array<string, string>|null $units required: `distance` km or mi, and for fuel `volume` l, us_gal or uk_gal
	 * @param string|null $tz required: the IANA zone a date without a time is noon in
	 * @param string|null $date_order dmy or mdy, when the file's slash dates fit both
	 * @param string|null $energy one of the vehicle's energy types, for fill-ups whose row names none, when it takes several
	 * @param array<string, string>|null $category_map each cost category text to `skip`, `expense.<category>` or `maintenance.<type>`
	 * @param bool|null $include_duplicates whether rows already on the vehicle count as created; false when left out
	 * @return DataResponse<Http::STATUS_OK, NextFleetImportPreview, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, NextFleetImportRefusal, array{}>|DataResponse<Http::STATUS_LOCKED, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle, or file of the caller's own
	 *
	 * 200: the preview
	 * 400: a field or an answer is not one the request takes, or the vehicle is disposed or takes no energy yet; the message names it
	 * 422: the file is not one an import reads; `reason` says why and `row` where reading stopped
	 * 423: somebody is writing the file; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function preview(
		string $uuid,
		mixed $file_id = null,
		mixed $importer = null,
		mixed $record_type = null,
		mixed $units = null,
		mixed $tz = null,
		mixed $date_order = null,
		mixed $energy = null,
		mixed $category_map = null,
		mixed $include_duplicates = null,
	): DataResponse {
		return $this->read(fn (): DataResponse => $this->reading(fn (): DataResponse => new DataResponse(
			$this->service->preview($this->userId(), $uuid, $this->request->getParams()),
		)));
	}

	/**
	 * Import a file
	 *
	 * Writes what the preview with the same fields counted as created, all of it or nothing, each
	 * row as the entry routes would and entered by the caller. Answers the counts and every entry
	 * it created; that list is what undoing the import names.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $file_id required: the file's id in Files
	 * @param string|null $importer required: lubelogger or spritmonitor
	 * @param string|null $record_type required: what the file holds, as for the preview
	 * @param array<string, string>|null $units required: as for the preview
	 * @param string|null $tz required: the IANA zone a date without a time is noon in
	 * @param string|null $etag required: the etag the preview answered
	 * @param string|null $date_order dmy or mdy, when the file's slash dates fit both
	 * @param string|null $energy one of the vehicle's energy types, for fill-ups whose row names none, when it takes several
	 * @param array<string, string>|null $category_map each cost category text to `skip`, `expense.<category>` or `maintenance.<type>`
	 * @param bool|null $include_duplicates whether rows already on the vehicle are created again; false when left out
	 * @return DataResponse<Http::STATUS_OK, NextFleetImportResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, NextFleetImportRefusal, array{}>|DataResponse<Http::STATUS_LOCKED, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle, or file of the caller's own
	 *
	 * 200: what was created
	 * 400: a field or an answer is not one the request takes, a question is still open, or a row is one its entry route refuses; the message names it, and nothing was written
	 * 409: the file changed since the preview; preview it again
	 * 422: the file is not one an import reads; `reason` says why and `row` where reading stopped
	 * 423: somebody is writing the file; send it again
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function import(
		string $uuid,
		mixed $file_id = null,
		mixed $importer = null,
		mixed $record_type = null,
		mixed $units = null,
		mixed $tz = null,
		mixed $etag = null,
		mixed $date_order = null,
		mixed $energy = null,
		mixed $category_map = null,
		mixed $include_duplicates = null,
	): DataResponse {
		return $this->read(fn (): DataResponse => $this->importing(fn (): DataResponse => new DataResponse(
			$this->service->import($this->userId(), $uuid, $this->request->getParams()),
		)));
	}

	/**
	 * Undo an import
	 *
	 * Soft-deletes every entry one import created, named by the `created` list its answer carried,
	 * all of them or none. Each must still be live on this vehicle and entered by the caller. Takes
	 * `edit`, as the import did.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param list<array{type: string, uuid: string}>|null $created required: the import's answer's list, as it came
	 * @return DataResponse<Http::STATUS_OK, NextFleetImportUndone, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not edit this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: every entry was deleted
	 * 400: `created` is not a list of `{type, uuid}` an import answers, or names an entry twice
	 * 409: an entry is no longer live on this vehicle or was not entered by the caller; nothing was deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function undo(string $uuid, mixed $created = null): DataResponse {
		return $this->read(fn (): DataResponse => $this->undoing(fn (): DataResponse => new DataResponse(
			$this->service->undo($this->userId(), $uuid, $this->request->getParams()),
		)));
	}
}
