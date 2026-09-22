<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\ReminderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The reminders that hang off one vehicle. Every rule lives in ReminderService, including the
 * access check.
 */
class ReminderController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private ReminderService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	public function fleet(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->fleet($this->userId())));
	}

	#[NoAdminRequired]
	public function templates(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->templates($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->create($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * @param string $reminder the reminder's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $reminder): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $uuid, $reminder, $token, $this->request->getParams()));
	}

	/**
	 * @param string $reminder the reminder's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $reminder): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $uuid, $reminder, $token));
	}

	/**
	 * @param string $reminder the reminder's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $reminder): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->restore($this->userId(), $uuid, $reminder, $token));
	}

	/**
	 * @param string $reminder the reminder's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function snooze(string $uuid, string $reminder): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->snooze($this->userId(), $uuid, $reminder, $token, $this->request->getParams()['until'] ?? null));
	}

	/**
	 * @param string $reminder the reminder's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function dismiss(string $uuid, string $reminder): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->dismiss($this->userId(), $uuid, $reminder, $token));
	}
}
