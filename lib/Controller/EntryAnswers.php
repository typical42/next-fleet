<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * What the fill-up, maintenance, expense and reminder controllers answer alike: the refusals a service
 * throws, as the status each one means, and the token an edit, a delete or an undo is checked
 * against. The trip's controller spells the same out on its own, since its service answers with an
 * entity.
 *
 * Wants `$request` (Controller's) and an `IUserSession $session` on the class that uses it.
 */
trait EntryAnswers {
	/**
	 * @param \Closure(): DataResponse $call
	 */
	private function answer(\Closure $call): DataResponse {
		try {
			return $call();
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

	/**
	 * A write checked against the `updated_at` the client read. A DELETE has no body, so the token
	 * travels in the query string; a PUT carries it beside the fields. Both arrive as parameters.
	 *
	 * @param \Closure(int): array<string, mixed> $write given the token
	 */
	private function checked(\Closure $write): DataResponse {
		$token = filter_var($this->request->getParams()['updated_at'] ?? null, FILTER_VALIDATE_INT);
		if ($token === false) {
			return new DataResponse(['message' => 'updated_at is missing, so this write cannot be checked'], Http::STATUS_BAD_REQUEST);
		}

		return $this->answer(fn (): DataResponse => new DataResponse($write($token)));
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
