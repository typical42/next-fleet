<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Another tool's export, from a file the user picked in their own Files. Every rule lives in
 * ImportService, the access check included.
 */
class ImportController extends Controller {
	use EntryAnswers;
	use ImportAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ImportService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/** Limited like a write, and tighter: each call reads a whole file. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function preview(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => $this->reading(fn (): DataResponse => new DataResponse(
			$this->service->preview($this->userId(), $uuid, $this->request->getParams()),
		)));
	}

	/** Limited as the preview is: it reads the whole file again, and then writes every row. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function import(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => $this->importing(fn (): DataResponse => new DataResponse(
			$this->service->import($this->userId(), $uuid, $this->request->getParams()),
		)));
	}

	/** Limited as the import is: it deletes as many rows as one wrote. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function undo(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => $this->undoing(fn (): DataResponse => new DataResponse(
			$this->service->undo($this->userId(), $uuid, $this->request->getParams()),
		)));
	}
}
