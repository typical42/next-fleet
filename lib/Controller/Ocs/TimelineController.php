<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\TimelineService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A vehicle's one timeline through the OCS door: the twin of the internal TimelineController
 * (docs/api.md, docs/architecture.md#the-timeline).
 *
 * @psalm-import-type NextFleetTimelinePage from ResponseDefinitions
 * @psalm-import-type NextFleetTimelineRow from ResponseDefinitions
 * @psalm-import-type NextFleetGap from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class TimelineController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private TimelineService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * A page of a vehicle's timeline
	 *
	 * What happened to the vehicle, newest first, every kind of Entry at once.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string|null $type one kind of Entry - odometer, trip, energy, maintenance or expense - or none for all
	 * @param string|null $cursor the `next` of the page before, or none for the newest
	 * @return DataResponse<Http::STATUS_OK, NextFleetTimelinePage, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the page; `next` is null on the last
	 * 400: the type or the cursor is not one
	 */
	#[NoAdminRequired]
	public function index(string $uuid, mixed $type = null, mixed $cursor = null): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->page(
			$this->userId(),
			$uuid,
			$this->word('type', $type),
			$this->word('cursor', $cursor),
		)));
	}

	/**
	 * List the Gaps in a vehicle's trips
	 *
	 * For the whole timeline at once.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @return DataResponse<Http::STATUS_OK, list<NextFleetGap>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle
	 *
	 * 200: the Gaps
	 * 400: the request is not one this route takes
	 */
	#[NoAdminRequired]
	public function gaps(string $uuid): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->gaps($this->userId(), $uuid)));
	}

	/**
	 * Get one Entry as its timeline row
	 *
	 * What a client reads back when an edit lost a race.
	 *
	 * @param string $uuid the vehicle's uuid
	 * @param string $type the kind of Entry, as a row names it
	 * @param string $entry the Entry's uuid
	 * @return DataResponse<Http::STATUS_OK, NextFleetTimelineRow, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException the caller may not see this vehicle
	 * @throws OCSNotFoundException no such vehicle or Entry
	 *
	 * 200: the row
	 * 400: the type is not a kind of Entry
	 */
	#[NoAdminRequired]
	public function show(string $uuid, string $type, string $entry): DataResponse {
		return $this->read(fn (): DataResponse => new DataResponse($this->service->one($this->userId(), $uuid, $type, $entry)));
	}
}
