<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\StatisticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Statistics screen's figures. Every rule lives in StatisticsService, including the access
 * check.
 */
class StatisticsController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private StatisticsService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/** The vehicle, the range, the zone and the net preference are the query string's. */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->of($this->userId(), $this->request->getParams()),
		));
	}
}
