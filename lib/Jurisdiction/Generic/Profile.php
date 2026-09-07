<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\Generic;

use OCA\NextFleet\Jurisdiction\IJurisdiction;

/**
 * What an install in a country nobody has written gets: metric units and no currency, so a
 * vehicle under it states its own (docs/contributing.md). It is also the seam's own test - every
 * caller meets a jurisdiction that answers "I don't know" here first.
 */
class Profile implements IJurisdiction {
	public function key(): string {
		return 'generic';
	}

	public function displayName(): string {
		return 'Generic';
	}

	public function odoUnit(): string {
		return 'km';
	}

	public function currency(): ?string {
		return null;
	}
}
