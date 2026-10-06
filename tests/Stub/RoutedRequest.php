<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Stub;

use OCP\IRequest;

/**
 * A request a mock can carry the route's placeholders on: IRequest promises `$urlParams` as a
 * property, which an interface cannot declare.
 */
abstract class RoutedRequest implements IRequest {
	/** @var string[] */
	public array $urlParams = [];
}
