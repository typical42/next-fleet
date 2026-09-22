<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Service\EnergyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The fill-ups and charging sessions that hang off one vehicle. Every rule lives in
 * EnergyService, including the access check.
 */
class EnergyController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private EnergyService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Answers with the row the server wrote, so the sheet learns what it was given rather than
	 * assuming what it sent - a derived `unit_price` and the `flags` are not fields a client
	 * fills in.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->record($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * What the sheet fills in before the driver types anything: the VAT rate on the day of the
	 * fill-up, and the stations this vehicle has used with the price each last charged.
	 */
	#[NoAdminRequired]
	public function prefill(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->prefill($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * An edit, answered with the row as it now stands - flags included, since an edit can raise or
	 * clear one - and the token the next write is checked against.
	 *
	 * @param string $fillUp the fill-up's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $fillUp): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $uuid, $fillUp, $token, $this->request->getParams()));
	}

	/**
	 * A delete, answered with the row it left so the undo toast holds the token the restore is
	 * checked against.
	 *
	 * @param string $fillUp the fill-up's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $fillUp): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $uuid, $fillUp, $token));
	}

	/**
	 * @param string $fillUp the fill-up's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function restore(string $uuid, string $fillUp): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->restore($this->userId(), $uuid, $fillUp, $token));
	}
}
