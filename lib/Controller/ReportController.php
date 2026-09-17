<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\LogbookExport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Printable pages the browser turns into paper (docs/adr/0005-no-pdf-library.md). What is on them
 * is the services'; this serves them as pages.
 */
class ReportController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private LogbookExport $export,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * One vehicle's Fahrtenbuch for one year, as its jurisdiction prints it.
	 *
	 * No CSRF token, because the page is opened by navigating to it and a navigation carries none;
	 * it writes nothing, and Nextcloud still demands the same-site cookie. Rate-limited like every
	 * export (docs/security.md).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function logbook(string $uuid, string $year): Response {
		$year = $this->parseYear($year);
		if ($year === null) {
			return new DataResponse(['message' => 'A year is four digits'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$page = $this->export->year($this->userId(), $uuid, $year);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		}
		if ($page === null) {
			return new DataResponse(['message' => 'No logbook export for this jurisdiction'], Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse($page, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
		// The renderer promises to load nothing (docs/security.md#hostile-content). This is what
		// still holds if one breaks the promise: no script, no fetch, no frame around it.
		$response->setContentSecurityPolicy((new EmptyContentSecurityPolicy())->allowInlineStyle());

		return $response;
	}

	/**
	 * Not an `int` parameter: the framework's cast would read `2026.5` or `2026abc` as a year. Four
	 * digits keeps `gmmktime` inside what an instant column holds.
	 */
	private function parseYear(string $value): ?int {
		return preg_match('/^\d{4}$/', $value) === 1 ? (int)$value : null;
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) {
			// The route requires a login, so this is a broken container rather than an anonymous
			// request.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
