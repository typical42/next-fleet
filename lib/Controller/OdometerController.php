<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\OdometerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The readings that hang off one vehicle. Every rule lives in OdometerService
 * (docs/architecture.md#odometer-rules), including the access check.
 */
class OdometerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private OdometerService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): array => $this->service->list($this->userId(), $uuid));
	}

	/**
	 * A new Reading carries no token: nothing was read that it could have lost a race against.
	 * `flagged` is restated from the whole chain rather than edited
	 * (docs/architecture.md#concurrency).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(
			fn (): OdoReading => $this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		);
	}

	/**
	 * An Odometer Entry corrected from its row, checked against the token it was read with.
	 *
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->update($this->userId(), $uuid, $reading, $token, $this->request->getParams()));
	}

	/**
	 * Answers with the row the delete left, so the undo toast holds the token the restore is
	 * checked against.
	 *
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->delete($this->userId(), $uuid, $reading, $token));
	}

	/**
	 * @param string $reading the Reading's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $reading): DataResponse {
		return $this->checked(fn (int $token): OdoReading => $this->service->restore($this->userId(), $uuid, $reading, $token));
	}

	/**
	 * A write checked against the `updated_at` the client read: in the body of a PUT, in the query
	 * string of a DELETE, and a request parameter either way.
	 *
	 * @param callable(int):OdoReading $write given the token
	 */
	private function checked(callable $write): DataResponse {
		$token = filter_var($this->request->getParams()['updated_at'] ?? null, FILTER_VALIDATE_INT);
		if ($token === false) {
			return new DataResponse(['message' => 'updated_at is missing, so this write cannot be checked'], Http::STATUS_BAD_REQUEST);
		}

		return $this->answer(fn (): OdoReading => $write($token));
	}

	/**
	 * The answers every route here shares.
	 *
	 * @param callable():(OdoReading|list<OdoReading>) $work
	 */
	private function answer(callable $work, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($work(), $status);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (StaleUpdateException) {
			// `conflict` tells this apart from Nextcloud's own failed CSRF check, which is a 412 as
			// well (docs/architecture.md#concurrency).
			return new DataResponse(
				['message' => 'Changed since you read it', 'conflict' => true],
				Http::STATUS_PRECONDITION_FAILED,
			);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) {
			// The routes require a login, so this is a broken container rather than an anonymous
			// request.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
