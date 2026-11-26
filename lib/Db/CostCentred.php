<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

/**
 * A row that names a cost centre of its vehicle owner's list: an entry, or a vehicle for the one
 * its new entries take. It keeps the id; every answer carries the uuid, which BaseMapper hangs on
 * at each read.
 */
interface CostCentred {
	public function getCostCentreId(): ?int;

	public function getCostCentreUuid(): ?string;

	public function setCostCentreUuid(?string $costCentreUuid): void;

	/** Names the cost centre, or none. */
	public function linkCostCentre(?CostCentre $costCentre): void;
}
