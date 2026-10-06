<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Middleware;

use OCA\NextFleet\Exception\PlaceholderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Nextcloud merges a JSON body over the URL's parameters, and a controller argument is bound from
 * the merge: a body field named like a placeholder would pick the row the request acts on, or fail
 * the argument's type with a 500. Such a request is refused with a 400 before any controller runs,
 * through both doors.
 */
class PlaceholderMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		/**
		 * @psalm-suppress NoInterfaceProperties IRequest declares it as @property-read, which Psalm
		 *                 does not read on an interface
		 */
		$placeholders = $this->request->urlParams;
		foreach ($placeholders as $name => $value) {
			$sent = $this->request->getParam($name);
			// A scalar is compared as text: the URL's value is always a string.
			if (!is_scalar($sent) || (string)$sent !== $value) {
				throw new PlaceholderException($name . ' is the one in the URL');
			}
		}
	}

	#[\Override]
	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof PlaceholderException) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		throw $exception;
	}
}
