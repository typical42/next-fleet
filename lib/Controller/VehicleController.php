<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The vehicle routes the web UI uses. Every rule lives in VehicleService, so what happens here
 * is the translation between a request and an answer
 * (docs/adr/0006-one-api-surface-in-v1.md).
 */
class VehicleController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private VehicleService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->list($this->userId()));
	}

	#[NoAdminRequired]
	public function show(string $uuid): DataResponse {
		return $this->answer(fn (): Vehicle => $this->service->find($this->userId(), $uuid));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): DataResponse {
		return $this->answer(
			fn (): Vehicle => $this->service->create($this->userId(), $this->request->getParams()),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid): DataResponse {
		$token = $this->token();
		if ($token === null) {
			return $this->refuse('updated_at is missing, so this write cannot be checked');
		}

		return $this->answer(
			fn (): Vehicle => $this->service->update($this->userId(), $uuid, $token, $this->request->getParams()),
		);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid): DataResponse {
		$token = $this->token();
		if ($token === null) {
			return $this->refuse('updated_at is missing, so this write cannot be checked');
		}

		return $this->answer(fn (): Vehicle => $this->service->delete($this->userId(), $uuid, $token));
	}

	/**
	 * The two answers every route shares: the vehicle a client asked for, or the reason it is
	 * not getting one.
	 *
	 * @param callable():Vehicle $work
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
			// The routes require a login, so this is a broken container rather than an anonymous
			// request.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
