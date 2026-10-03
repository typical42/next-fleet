<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\KpiService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A vehicle's figures through the OCS door: the twin of the internal KpiController (docs/api.md,
 * docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @psalm-import-type NextFleetFigures from ResponseDefinitions
 * @psalm-import-type NextFleetYear from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class KpiController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private KpiService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * A vehicle's figures for a period
	 *
	 * Consumption, cost and hours for the period the client names, net of VAT when `net` says so;
	 * where a year starts is the person's midnight, not the server's.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param int|null $from required: the period's start, unix seconds
	 * @param int|null $to required: its end, unix seconds, not part of it
	 * @param bool|null $net net of VAT, for whoever reclaims it
	 * @return DataResponse<Http::STATUS_OK, NextFleetFigures, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the figures
	 * 400: `from` and `to` are not a period, or `net` is not true or false
	 */
	#[NoAdminRequired]
	public function index(string $uuid, mixed $from = null, mixed $to = null, mixed $net = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->of($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * A vehicle's year
	 *
	 * The year's figures, its CO₂ and each month's cost, cut at midnight in the zone `tz`.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $year four digits
	 * @param string|null $tz required: the IANA zone the months are cut in
	 * @param bool|null $net net of VAT, for whoever reclaims it
	 * @return DataResponse<Http::STATUS_OK, NextFleetYear, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the year
	 * 400: the year or the zone is not one, or `net` is not true or false
	 */
	#[NoAdminRequired]
	public function year(string $uuid, string $year, mixed $tz = null, mixed $net = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse(
			$this->service->year($this->userId(), $uuid, $year, $this->request->getParams()),
		));
	}
}
