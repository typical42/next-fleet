<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;

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

	/**
	 * Built here rather than injected: the ruleset answers out of its own constants and takes
	 * nothing. The first one that needs a clock or a rate table takes it in this profile's
	 * constructor, which the container already builds (`Jurisdictions`).
	 */
	public function logbookRules(): ?ILogbookRules {
		return new LogbookRules();
	}
}
