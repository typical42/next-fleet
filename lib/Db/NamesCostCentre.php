<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

/**
 * CostCentred for an entity with a `cost_centre_id` column. The entity's constructor adds the
 * column's type, as it does for every other column.
 */
trait NamesCostCentre {
	/** The cost centre of the owner's list; never in an answer, which carries its uuid. */
	protected ?int $costCentreId = null;
	/**
	 * That record's uuid. Not a column, and private for the reason Trip::$partnerUuid gives.
	 */
	private ?string $costCentreUuid = null;

	public function getCostCentreId(): ?int {
		return $this->costCentreId;
	}

	public function setCostCentreId(?int $costCentreId): void {
		$this->setter('costCentreId', [$costCentreId]);
	}

	public function getCostCentreUuid(): ?string {
		return $this->costCentreUuid;
	}

	public function setCostCentreUuid(?string $costCentreUuid): void {
		$this->costCentreUuid = $costCentreUuid;
	}

	/**
	 * A deleted record, which only an erasure of its owner leaves, is linked by id alone, as
	 * Trip::linkPartner() keeps a deleted partner.
	 */
	public function linkCostCentre(?CostCentre $costCentre): void {
		$this->setCostCentreId($costCentre === null ? null : (int)$costCentre->getId());
		$this->costCentreUuid = $costCentre?->getDeletedAt() === null ? $costCentre?->getUuid() : null;
	}
}
