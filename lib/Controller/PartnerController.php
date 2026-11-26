<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\PartnerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The owner's partner list (ADR 0013). Every rule lives in PartnerService, including who may read
 * and who may write.
 */
class PartnerController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private PartnerService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	private function missing(): string {
		return 'No such partner';
	}

	/** The caller's own list. */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId())));
	}

	/**
	 * @param string $record the partner's uuid
	 */
	#[NoAdminRequired]
	public function show(string $record): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->show($this->userId(), $record)));
	}

	/**
	 * The name, the address and the note, checked against the token the client read.
	 *
	 * @param string $record the partner's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $record): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->update($this->userId(), $record, $token, $this->request->getParams()));
	}

	/**
	 * Into the record `into` names; answers with that record.
	 *
	 * @param string $record the uuid of the partner that goes
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function merge(string $record): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->merge($this->userId(), $record, $token, $this->request->getParams()['into'] ?? null));
	}

	/**
	 * An erasure, so there is no undo (PartnerService::delete()).
	 *
	 * @param string $record the partner's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $record): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->delete($this->userId(), $record, $token));
	}

	/**
	 * The partner's trips over the period the query string names.
	 *
	 * @param string $record the partner's uuid
	 */
	#[NoAdminRequired]
	public function trips(string $record): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->trips($this->userId(), $record, $this->request->getParams())));
	}

	/**
	 * The same as a CSV file to save. No CSRF token and a rate limit, as ReportController::csv().
	 *
	 * @param string $record the partner's uuid
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function csv(string $record): Response {
		try {
			$file = $this->service->csv($this->userId(), $record, $this->request->getParams());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->missing()], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataDownloadResponse($file['body'], $file['name'], 'text/csv; charset=utf-8');
	}
}
