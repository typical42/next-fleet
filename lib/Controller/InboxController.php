<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\InboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Inbox screen's one read. Choosing the folder is a preference (PreferencesController), so
 * nothing here writes.
 */
class InboxController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private InboxService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		$user = $this->session->getUser()
			// The route requires a login, so this is a broken container rather than an anonymous
			// request.
			?? throw new \RuntimeException('No user in session');

		return new DataResponse($this->service->list($user->getUID()));
	}
}
