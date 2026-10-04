<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\ResponseDefinitions;
use OCA\NextFleet\Service\SyncService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What changed for a client since its last call (docs/api.md#sync). The one OCS route with no
 * internal twin: the web UI reads what is on screen, and keeps nothing to bring up to date.
 *
 * @psalm-import-type NextFleetSync from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
class SyncController extends OCSController {
	use OcsAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * What changed since the last sync
	 *
	 * Every reachable vehicle, the rows changed since `cursor`, and the vehicles out of reach
	 * since. At least once, never missed: a client upserts by `uuid` and `updated_at`, and asks
	 * again with the answer's `cursor` while `more` is true.
	 *
	 * @param string $cursor what the last answer handed out; empty for a client that holds nothing
	 * @param int<1, 2000> $limit how many changed rows a page holds
	 * @return DataResponse<Http::STATUS_OK, NextFleetSync, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 *
	 * 200: one page; `reset` says the client is to drop what it holds first
	 * 400: the cursor is not one this server handed out, or the limit is out of range
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function index(mixed $cursor = '', int $limit = SyncService::LIMIT): DataResponse {
		// The range is in the docblock because Nextcloud 34 holds an undeclared `limit` to 1-500 and
		// refuses the rest before this runs; Nextcloud 31 checks nothing, so the service does.
		// Not read(): no vehicle is named, so there is nothing to forbid or not find.
		try {
			return new DataResponse($this->service->sync($this->userId(), $this->word('cursor', $cursor) ?? '', $limit));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
