<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\CostCentred;
use OCA\NextFleet\Db\CostCentreMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Which cost centre a row names: one of its vehicle owner's list, picked by uuid. A driver picks
 * and never creates one. Under the vehicle's hold, so the owner read is the one the row is
 * written for; and under the record's (hold()), so a delete of it (CostCentreService::delete())
 * is either done or waits.
 */
class CostCentreLinks {
	/** The field a request names the cost centre in. */
	public const FIELD = 'cost_centre_uuid';

	public function __construct(
		private CostCentreMapper $centres,
		private VehicleMapper $vehicles,
	) {
	}

	/**
	 * Links what the request names, for a caller in a transaction that holds the vehicle. A new
	 * entry that names nothing takes the vehicle's default; an edit that names nothing keeps its
	 * own, as an older client does not know the field. An explicit null is none, but keeps the mark
	 * of an erased record, which reads as none already.
	 *
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if the uuid is no cost centre of the vehicle owner's
	 * @throws \OCP\DB\Exception
	 */
	public function apply(CostCentred $row, Vehicle $vehicle, array $fields, bool $new): void {
		$was = $row->getCostCentreId();
		if (array_key_exists(self::FIELD, $fields)) {
			$centre = $this->named($this->vehicles->ownerOf((int)$vehicle->getId()), $fields[self::FIELD]);
			if ($centre !== null || $new || $row->getCostCentreUuid() !== null) {
				$row->linkCostCentre($centre);
			}
		} elseif ($new) {
			$row->linkCostCentre($this->defaultOf($vehicle));
		}
		// A new row always: a deadlock replays it with the row of the rolled-back attempt, which
		// names the record already, and that attempt's hold went with the rollback.
		if ($new || $row->getCostCentreId() !== $was) {
			$this->hold($row);
		}
	}

	/**
	 * Holds the record a row comes to name, and refuses it if a delete took it meanwhile. One the
	 * row named before needs none: the delete sees it. In the caller's transaction, after the
	 * vehicle's hold, the order an entry takes them in.
	 *
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	public function hold(CostCentred $row): void {
		$id = $row->getCostCentreId();
		if ($id === null || $row->getCostCentreUuid() === null) {
			return;
		}
		$this->centres->hold($id);
		if (($this->centres->findAnyOf([$id])[$id] ?? null)?->getDeletedAt() !== null) {
			throw self::refused();
		}
	}

	/**
	 * The default a vehicle names, as the request sends it: one of the owner's list, or null for
	 * none. An archived one is no longer offered, so it is refused unless the vehicle names it
	 * already.
	 *
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	public function defaultNamed(Vehicle $vehicle, mixed $value): ?CostCentre {
		$centre = $this->named($vehicle->getUserId(), $value);
		if ($centre !== null && $centre->getArchived() && $centre->getId() !== $vehicle->getCostCentreId()) {
			throw new \InvalidArgumentException(self::FIELD . ' is archived, so no new entry would take it');
		}

		return $centre;
	}

	/**
	 * The vehicle's default as a new entry takes it: none once it is archived, since an archived
	 * one is no longer offered. Read again, not taken from the vehicle the access check read: a
	 * transfer or a new default can have committed before the caller's hold.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function defaultOf(Vehicle $vehicle): ?CostCentre {
		$id = $this->vehicles->costCentreOf((int)$vehicle->getId());
		if ($id === null) {
			return null;
		}
		$centre = $this->centres->findAnyOf([$id])[$id] ?? null;

		return $centre === null || $centre->getDeletedAt() !== null || $centre->getArchived() ? null : $centre;
	}

	/**
	 * The live record of the owner's list the value names, or null for none. An archived one is
	 * taken: a client that was offline while it was archived still writes its entry.
	 *
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function named(string $owner, mixed $value): ?CostCentre {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value)) {
			throw self::refused();
		}
		try {
			$centre = $this->centres->findByUuid($value);
		} catch (DoesNotExistException) {
			throw self::refused();
		}
		// One answer for a record of another list, so it tells nobody which uuids exist.
		if ($centre->getUserId() !== $owner) {
			throw self::refused();
		}

		return $centre;
	}

	private static function refused(): \InvalidArgumentException {
		return new \InvalidArgumentException(self::FIELD . ' is a cost centre of the vehicle owner\'s');
	}
}
