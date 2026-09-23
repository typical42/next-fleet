<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\KpiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The figures in a vehicle's header and on its Costs screen. Every rule lives in KpiService,
 * including the access check.
 */
class KpiController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private KpiService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/** The period and the net preference are the query string's. */
	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->of($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * The Costs screen's year; the zone and the net preference are the query string's. Not an
	 * `int $year`, for the reason ReportController::parseYear() gives.
	 */
	#[NoAdminRequired]
	public function year(string $uuid, string $year): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->year($this->userId(), $uuid, $year, $this->request->getParams()),
		));
	}
}
