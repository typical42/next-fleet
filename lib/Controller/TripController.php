<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\TripService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The trips that hang off one vehicle. Every rule lives in TripService, including the access
 * check.
 */
class TripController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private TripService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Answers with the row the server wrote, so the sheet learns what it was given rather than
	 * assuming what it sent - `reconciled` is not a field a client fills in.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(
			fn (): Trip => $this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		);
	}

	/**
	 * A delete voids (docs/features.md#logbook-mode), and answers with the row it left behind so
	 * the undo toast holds the token the restore is checked against.
	 *
	 * @param string $trip the trip's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $trip): DataResponse {
		$token = $this->token();
		if ($token === null) {
			return $this->refuse('updated_at is missing, so this write cannot be checked');
		}

		return $this->answer(fn (): Trip => $this->service->delete($this->userId(), $uuid, $trip, $token));
	}

	/**
	 * @param string $trip the trip's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $trip): DataResponse {
		$token = $this->token();
		if ($token === null) {
			return $this->refuse('updated_at is missing, so this write cannot be checked');
		}

		return $this->answer(fn (): Trip => $this->service->restore($this->userId(), $uuid, $trip, $token));
	}

	/**
	 * The two answers every route here shares: the trip a client asked for, or the reason it is
	 * not getting one.
	 *
	 * @param callable():Trip $work
	 */
	private function answer(callable $work, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($work(), $status);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (StaleUpdateException) {
			// `conflict` is what tells this apart from Nextcloud's own failed CSRF check, which
			// is a 412 as well (docs/architecture.md#concurrency).
			return new DataResponse(
				['message' => 'Changed since you read it', 'conflict' => true],
				Http::STATUS_PRECONDITION_FAILED,
			);
		} catch (\InvalidArgumentException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	private function refuse(string $message): DataResponse {
		return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * The `updated_at` the client read, which the write is checked against
	 * (docs/architecture.md#concurrency). A DELETE has no body, so it travels in the query
	 * string; both arrive as request parameters.
	 */
	private function token(): ?int {
		$token = filter_var($this->request->getParams()['updated_at'] ?? null, FILTER_VALIDATE_INT);

		return $token === false ? null : $token;
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
