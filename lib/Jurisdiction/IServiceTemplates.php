<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * Service intervals by market or manufacturer (docs/contributing.md). Law does not set them, so
 * they are not a jurisdiction's: the inspection a country requires is `IInspectionScheme`.
 *
 * Internal seam, not a public API - see docs/contributing.md.
 */
interface IServiceTemplates {
	/**
	 * In the order a reminder sheet offers them, each key unique.
	 *
	 * @return list<ReminderTemplate>
	 */
	public function all(): array;
}
