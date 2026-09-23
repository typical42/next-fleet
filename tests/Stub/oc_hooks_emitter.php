<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OC\Hooks;

/**
 * `OCP\Files\IRootFolder` extends this private server interface, and nextcloud/ocp does not ship
 * it. For psalm only (psalm.xml): the server brings the real one at runtime.
 */
interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
