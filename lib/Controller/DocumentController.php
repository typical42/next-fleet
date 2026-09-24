<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\DocumentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;

/**
 * One vehicle's papers. Every rule lives in DocumentService, including the access check. Each
 * route answers with the list as it now stands, so there is no token: nothing edits a document.
 */
class DocumentController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private DocumentService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->attach($this->userId(), $uuid, $this->request->getParams()),
		));
	}

	/**
	 * @param string $document the document's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $document): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->detach($this->userId(), $uuid, $document)));
	}

	/**
	 * The file behind one paper, to save. Always an attachment and under a CSP that runs nothing,
	 * since the content is whatever somebody put in Files (docs/security.md#hostile-content). No
	 * CSRF token, because it is a link and a navigation carries none; it writes nothing.
	 *
	 * @param string $document the document's uuid
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function download(string $uuid, string $document): Response {
		try {
			$file = $this->service->download($this->userId(), $uuid, $document);
			$stream = $file->fopen('rb');
		} catch (DoesNotExistException|NotFoundException|NotPermittedException) {
			return new DataResponse(['message' => 'No such document'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (LockedException) {
			// Somebody's client is writing it; a moment later it serves.
			return new DataResponse(['message' => 'Being written, try again'], Http::STATUS_LOCKED);
		}
		if ($stream === false) {
			return new DataResponse(['message' => 'No such document'], Http::STATUS_NOT_FOUND);
		}

		$name = $file->getName();
		// The quoted form for old clients, in ASCII a header can carry; `filename*` is the real name.
		$fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name);
		// Nextcloud's stream takes a copy of no bytes for a failure and answers 400.
		$response = $file->getSize() === 0 ? new DataDisplayResponse('') : new StreamResponse($stream);
		// Set after construction: DataDisplayResponse writes an inline disposition of its own.
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
		$response->addHeader('Content-Type', $file->getMimeType());
		$response->addHeader('Content-Length', (string)$file->getSize());
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());

		return $response;
	}
}
