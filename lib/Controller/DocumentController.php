<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\DocumentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * One vehicle's papers. Every rule lives in DocumentService, including the access check. Each
 * route answers with the list as it now stands, so there is no token: nothing edits a document.
 */
class DocumentController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private DocumentService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->attach($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * @param string $document the document's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $document): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->detach($this->userId(), $uuid, $document)));
	}
}
