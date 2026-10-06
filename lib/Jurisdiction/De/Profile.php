<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\IInspectionScheme;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\IRateProvider;
use OCA\NextFleet\Jurisdiction\IReportRenderer;

/**
 * Germany. Its parts are built here rather than injected: each answers out of its own constants
 * and takes nothing. The first that needs a clock or a rate table takes it in this profile's
 * constructor, which the container already builds (`Jurisdictions`).
 */
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

	public function logbookRules(): ?ILogbookRules {
		return new LogbookRules();
	}

	public function logbookRenderer(): ?IReportRenderer {
		return new FahrtenbuchRenderer();
	}

	public function claimRenderer(): ?IClaimRenderer {
		return new MileageClaimRenderer();
	}

	public function rates(): ?IRateProvider {
		return new RateProvider();
	}

	public function inspectionScheme(): ?IInspectionScheme {
		return new InspectionScheme();
	}
}
