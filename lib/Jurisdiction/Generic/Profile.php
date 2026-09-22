<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\Generic;

use OCA\NextFleet\Jurisdiction\IInspectionScheme;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\IRateProvider;
use OCA\NextFleet\Jurisdiction\IReportRenderer;

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

	/** No country's requirements to state, and inventing some would be worse than stating none. */
	public function logbookRules(): ?ILogbookRules {
		return null;
	}

	/** The generic report is M5's (plan.md). Until then there is no export, not an empty one. */
	public function logbookRenderer(): ?IReportRenderer {
		return null;
	}

	/** A rate here would be some other country's. */
	public function rates(): ?IRateProvider {
		return null;
	}

	/** An inspection cadence here would be some other country's law. */
	public function inspectionScheme(): ?IInspectionScheme {
		return null;
	}
}
