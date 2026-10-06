<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\TimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The one timeline a vehicle has. Every rule lives in TimelineService, including the access check.
 */
class TimelineController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private TimelineService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The chip and the scroll position are the query string's, and both are optional: a client that
	 * sends neither asks for the newest rows of every kind.
	 *
	 * @param mixed $type the chip: one kind of Entry, or null for all of them
	 * @param mixed $cursor what a previous page answered with, or null for the top
	 */
	#[NoAdminRequired]
	public function index(string $uuid, mixed $type = null, mixed $cursor = null): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->page(
			$this->userId(),
			$uuid,
			$this->word('type', $type),
			$this->word('cursor', $cursor),
		)));
	}

	/** The Gaps the month headers state, for the whole timeline at once. */
	#[NoAdminRequired]
	public function gaps(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->gaps($this->userId(), $uuid)));
	}

	/**
	 * One Entry as its timeline row.
	 *
	 * @param string $type the kind of Entry, as a row names it
	 * @param string $entry the Entry's uuid
	 */
	#[NoAdminRequired]
	public function show(string $uuid, string $type, string $entry): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->one($this->userId(), $uuid, $type, $entry)));
	}
}
