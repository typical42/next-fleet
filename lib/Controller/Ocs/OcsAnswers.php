<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller\Ocs;

use OCA\NextFleet\Controller\RequestValues;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Exception\BookingConflictException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * What every OCS controller answers alike: the refusals a service throws, each the status and the
 * body its internal twin answers with (docs/api.md). 403 and 404 are Nextcloud's own OCS
 * exceptions, so the envelope's status says them; the refusals a client acts on keep their body.
 *
 * Wants `$request` (Controller's) and an `IUserSession $session` on the class that uses it.
 *
 * A method names each field its route takes as a `mixed $field = null` parameter, typed in the
 * docblock, so the OpenAPI document can list it - and then hands the service the request whole,
 * as its twin does, since a field left out and a field sent empty differ there. `mixed` and
 * optional, so a wrong or missing field reaches the service's 400 and never a framework error
 * (tests/Unit/OcsRoutesTest.php).
 *
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetConflict from ResponseDefinitions
 * @psalm-import-type NextFleetBookingConflict from ResponseDefinitions
 */
trait OcsAnswers {
	use RequestValues;

	/**
	 * The refusals any route can meet. Split from write()'s so a read's type does not promise a
	 * 409 or a 412 it cannot give; the OpenAPI document is read from these types.
	 *
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>
	 * @throws OCSForbiddenException
	 * @throws OCSNotFoundException
	 */
	private function read(\Closure $call): DataResponse {
		try {
			return $call();
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('No such vehicle');
		} catch (AccessDeniedException) {
			throw new OCSForbiddenException('Not yours');
		} catch (RefusedException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->reason], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * And the one every write can meet: a token, its own or a row it follows, that lost the race.
	 *
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException
	 * @throws OCSNotFoundException
	 */
	private function write(\Closure $call): DataResponse {
		try {
			return $this->read($call);
		} catch (StaleUpdateException) {
			// `conflict` tells this apart from Nextcloud's own failed CSRF check, which is a 412 as
			// well (docs/architecture.md#concurrency).
			return new DataResponse(['message' => 'Changed since you read it', 'conflict' => true], Http::STATUS_PRECONDITION_FAILED);
		}
	}

	/**
	 * And a write that can meet a booking in the way: a booking's own, or a trip logged from one.
	 *
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_BAD_REQUEST, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetBookingConflict, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, NextFleetConflict, array{}>
	 * @throws OCSForbiddenException
	 * @throws OCSNotFoundException
	 */
	private function booked(\Closure $call): DataResponse {
		try {
			return $this->write($call);
		} catch (BookingConflictException $e) {
			// The booking in the way travels with the refusal, so a client can name it.
			return new DataResponse(['message' => $e->getMessage(), 'booking' => $e->booking], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * A create's answer: the row under `$status`, or - for a create sent again under the client
	 * uuid of a row it wrote - that row under 200 (Service\Once). Inside write() or booked(), which
	 * answer the refusals.
	 *
	 * @template S of Http::STATUS_CREATED|Http::STATUS_OK
	 * @template B of array
	 * @param \Closure(): B $create the row in its wire form
	 * @param S $status what a create is answered with
	 * @return DataResponse<S, B, array{}>|DataResponse<Http::STATUS_OK, B, array{}>
	 */
	private function created(\Closure $create, int $status): DataResponse {
		try {
			return new DataResponse($create(), $status);
		} catch (AlreadyCreatedException $e) {
			// The service's answer, which the closure would have put in wire form the same way.
			/** @var B $body */
			$body = $e->answer instanceof \JsonSerializable ? $e->answer->jsonSerialize() : $e->answer;

			return new DataResponse($body);
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
