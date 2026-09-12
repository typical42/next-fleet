<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\TimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The one timeline a vehicle has. Every rule lives in TimelineService, including the access check.
 */
class TimelineController extends Controller {
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
		try {
			return new DataResponse($this->service->page(
				$this->userId(),
				$uuid,
				$this->word('type', $type),
				$this->word('cursor', $cursor),
			));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such vehicle'], Http::STATUS_NOT_FOUND);
		} catch (AccessDeniedException) {
			return new DataResponse(['message' => 'Not yours'], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * One query-string value as the service takes it. The framework casts a controller's int,
	 * float and bool parameters and nothing else, so `?type[]=trip` arrives as an array - a
	 * `?string` parameter would make that a 500, when it is a request this route never handed out
	 * like any other.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function word(string $name, mixed $value): ?string {
		if ($value !== null && !is_string($value)) {
			throw new \InvalidArgumentException($name . ' is a word or nothing at all');
		}

		return $value;
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
