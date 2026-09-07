<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IJurisdiction;

/** Germany, the first jurisdiction (plan.md). */
class Profile implements IJurisdiction {
	public function key(): string {
		return 'de';
	}

	public function displayName(): string {
		return 'Germany';
	}

	public function odoUnit(): string {
		return 'km';
	}

	public function currency(): ?string {
		return 'EUR';
	}
}
