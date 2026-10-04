<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\PreferencesService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The caller's own settings through the OCS door: the twin of the internal PreferencesController
 * (docs/api.md). No token, for the reason the twin gives.
 *
 * @psalm-import-type NextFleetPreferences from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class PreferencesController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private PreferencesService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the caller's preferences
	 *
	 * And the jurisdictions they may choose from.
	 *
	 * @return DataResponse<Http::STATUS_OK, NextFleetPreferences, array{}>
	 *
	 * 200: the preferences
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->forUser($this->userId()));
	}

	/**
	 * Change the caller's preferences
	 *
	 * Writes the preferences the request names and leaves the rest; one refused leaves all
	 * unchanged.
	 *
	 * @param string|null $jurisdiction the country new vehicles are kept under, such as de
	 * @param list<string>|null $dismissed_hints the vehicles whose completeness hint is dismissed, by uuid
	 * @param list<string>|null $dismissed_logbook_hints the vehicles whose Logbook Mode question is answered, by uuid
	 * @param bool|null $reclaim_vat the caller reclaims VAT, so figures are net
	 * @param string|null $kpi_period last-12, this-year, last-year or month
	 * @param int|null $grid_factor grams of CO₂ per kWh from the caller's tariff, 0 to 2000; null for the country's
	 * @param int|null $inbox_folder the id of a folder in the caller's Files; null for none
	 * @return DataResponse<Http::STATUS_OK, NextFleetPreferences, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException never: no vehicle is named; listed because every read can answer it
	 * @throws OCSNotFoundException never: no vehicle is named; listed because every read can answer it
	 *
	 * 200: the preferences as they now stand
	 * 400: a preference is not one of the answers it takes; the message names it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(
		mixed $jurisdiction = null,
		mixed $dismissed_hints = null,
		mixed $dismissed_logbook_hints = null,
		mixed $reclaim_vat = null,
		mixed $kpi_period = null,
		mixed $grid_factor = null,
		mixed $inbox_folder = null,
	): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->write($this->userId(), $this->request->getParams())));
	}
}
