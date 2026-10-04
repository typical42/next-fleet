<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Exception\RefusedException;
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
 * (docs/adr/0009-the-ocs-api-v1-is-the-public-contract.md).
 */
class VehicleController extends Controller {
	use RequestValues;

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
		return $this->answer(
			fn (): Vehicle => $this->service->update($this->userId(), $uuid, $this->token(), $this->request->getParams()),
		);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid): DataResponse {
		return $this->answer(fn (): Vehicle => $this->service->delete($this->userId(), $uuid, $this->token()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid): DataResponse {
		return $this->answer(fn (): Vehicle => $this->service->restore($this->userId(), $uuid, $this->token()));
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
		} catch (AlreadyCreatedException $e) {
			return new DataResponse($e->answer);
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
		} catch (RefusedException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->reason], Http::STATUS_BAD_REQUEST);
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
