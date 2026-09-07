<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\PreferencesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The personal settings screen's two calls. Like every other controller here it carries no rules
 * (docs/adr/0006-one-api-surface-in-v1.md); a preference carries no `updated_at` either, because
 * a user's own setting has no second writer to lose a race against.
 */
class PreferencesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PreferencesService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->forUser($this->userId()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(): DataResponse {
		try {
			return new DataResponse($this->service->write($this->userId(), $this->request->getParams()));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) {
			// The routes require a login, so this is a broken container rather than an anonymous
			// request.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
