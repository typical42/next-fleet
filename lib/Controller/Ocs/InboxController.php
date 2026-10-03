<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\InboxService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What waits in the caller's inbox folder, through the OCS door: the twin of the internal
 * InboxController (docs/api.md). The folder is a preference, so nothing here writes.
 *
 * @psalm-import-type NextFleetInbox from ResponseDefinitions
 */
class InboxController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private InboxService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the caller's receipt inbox
	 *
	 * The images and PDFs in the folder the preferences name that no paper holds yet, newest
	 * first: `files` the first hundred, `count` all of them. `folder` is null when none is named.
	 *
	 * @return DataResponse<Http::STATUS_OK, NextFleetInbox, array{}>
	 *
	 * 200: the inbox
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->list($this->userId()));
	}
}
