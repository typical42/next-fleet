<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\MileageClaimExport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Printable pages the browser turns into paper (docs/adr/0005-no-pdf-library.md), and the CSV files
 * beside them. What is in them is the services'; this serves them.
 */
class ReportController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private LogbookExport $logbook,
		private MileageClaimExport $claim,
		private ExportService $spreadsheets,
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
		return $this->page($uuid, $year, $this->logbook->year(...), 'No logbook export for this jurisdiction');
	}

	/**
	 * One vehicle's business trips for one year at its jurisdiction's rate
	 * (docs/architecture.md#the-mileage-claim). Opened, limited and fenced as logbook() is.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function mileage(string $uuid, string $year): Response {
		return $this->page($uuid, $year, $this->claim->year(...), 'No mileage claim for this vehicle');
	}

	/**
	 * A printable page, or why there is none.
	 *
	 * @param \Closure(string, string, int): ?string $print the user, the vehicle and the year in; the page out,
	 *                                                      or null where there is none to print
	 */
	private function page(string $uuid, string $year, \Closure $print, string $none): Response {
		$year = $this->parseYear($year);
		if ($year === null) {
			return new DataResponse(['message' => 'A year is four digits'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$page = $print($this->userId(), $uuid, $year);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		}
		if ($page === null) {
			return new DataResponse(['message' => $none], Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse($page, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
		// The renderer promises to load nothing (docs/security.md#hostile-content). This is what
		// still holds if one breaks the promise: no script, no fetch, no frame around it.
		$response->setContentSecurityPolicy((new EmptyContentSecurityPolicy())->allowInlineStyle());

		return $response;
	}

	/**
	 * One table of one vehicle's year as a CSV file to save (docs/architecture.md#csv-export). No
	 * CSRF token and a rate limit, for the reasons logbook() gives; an unknown table is a path this
	 * route never handed out.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function csv(string $uuid, string $year, string $table): Response {
		$year = $this->parseYear($year);
		if ($year === null) {
			return new DataResponse(['message' => 'A year is four digits'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$file = $this->spreadsheets->csv($this->userId(), $uuid, $year, $table);
		} catch (DoesNotExistException|\InvalidArgumentException) {
			return new DataResponse(['message' => 'No such export'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		}

		return new DataDownloadResponse($file['body'], $file['name'], 'text/csv; charset=utf-8');
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
