<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\Generic;

use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\IInspectionScheme;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\IRateProvider;
use OCA\NextFleet\Jurisdiction\IReportRenderer;
use OCP\IL10N;

/**
 * What an install in a country nobody has written gets: metric units and no currency, so a
 * vehicle under it states its own (docs/contributing.md). It is also the seam's own test - every
 * caller meets a jurisdiction that answers "I don't know" here first.
 */
class Profile implements IJurisdiction {
	/** The reader's language, which the plain logbook prints in (`LogbookRenderer`). */
	public function __construct(
		private IL10N $l,
	) {
	}

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

	/** A plain trip listing, with no country's requirements laid over it. */
	public function logbookRenderer(): ?IReportRenderer {
		return new LogbookRenderer($this->l);
	}

	/** There is no rate to value a trip at (`rates()`), so there is no claim to print. */
	public function claimRenderer(): ?IClaimRenderer {
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
