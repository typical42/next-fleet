<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\StatisticsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Statistics screen's figures through the OCS door: the twin of the internal
 * StatisticsController (docs/api.md#statistics).
 *
 * @psalm-import-type NextFleetStatistics from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class StatisticsController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private StatisticsService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Statistics over a range
	 *
	 * One vehicle or every one the caller sees, over a range cut into months, or years beyond 24
	 * months, at midnight in the zone `tz`. Costs come in one series per currency, from the
	 * vehicles whose costs the caller sees.
	 *
	 * @param string|null $vehicle a vehicle's uuid; none for every vehicle
	 * @param string|null $from the range's first day, YYYY-MM-DD; with `to`, or neither for all of it
	 * @param string|null $to the day after its last
	 * @param string|null $tz required: the IANA zone the buckets are cut in
	 * @param bool|null $net net of VAT, for whoever reclaims it
	 * @return DataResponse<Http::STATUS_OK, NextFleetStatistics, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the statistics
	 * 400: the range, the zone or `net` is not one
	 */
	#[NoAdminRequired]
	public function index(mixed $vehicle = null, mixed $from = null, mixed $to = null, mixed $tz = null, mixed $net = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->of($this->userId(), $this->request->getParams()),
		));
	}
}
