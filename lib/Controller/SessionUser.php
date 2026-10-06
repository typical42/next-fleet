<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

/**
 * Wants an `IUserSession $session` on the class that uses it.
 */
trait SessionUser {
	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) {
			// The routes require a login, so no user means a broken container.
			throw new \RuntimeException('No user in session');
		}

		return $user->getUID();
	}
}
