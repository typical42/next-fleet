<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Db\VehicleTables;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * What `occ nextfleet:check` finds: rows that break a rule no constraint in the database holds
 * them to. It writes nothing; each finding names the row, so an admin can look.
 *
 * It takes no hold, so a write that commits between two of its reads can show as a finding that
 * a second run no longer reports.
 *
 * @psalm-type Finding = array{check: string, table: ?string, row: ?string, vehicle: ?string, reason: string}
 */
class CheckService {
	/** What a reason calls the Entry behind a Reading. */
	private const ENTRIES = [OdoReading::TRIP => 'trip', OdoReading::ENERGY => 'fill-up', OdoReading::MAINTENANCE => 'maintenance record'];

	public function __construct(
		private VehicleMapper $vehicles,
		private OdometerService $odometer,
		private OdoReadingMapper $readings,
		private TripMapper $trips,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private AccessMapper $access,
		private IUserManager $users,
		private IGroupManager $groups,
		private Pending $pending,
		private VehicleTables $tables,
		private ErasureService $erasure,
	) {
	}

	/**
	 * Every check, or those about one vehicle.
	 *
	 * @param Vehicle|null $only one vehicle, live or deleted; null for the whole instance
	 * @return array{findings: list<Finding>, warnings: list<Finding>}
	 * @throws \OCP\DB\Exception
	 */
	public function run(?Vehicle $only): array {
		$vehicles = $only === null
			? $this->vehicles->findForAdmin(null, null)
			: [$only];

		$pending = $this->pending();
		$exists = [];
		$findings = $only === null ? $this->orphans() : [];
		foreach ($vehicles as $vehicle) {
			array_push($findings, ...$this->owner($vehicle, $pending, $exists), ...$this->odometer($vehicle), ...$this->entries($vehicle), ...$this->grants($vehicle, $pending, $exists));
		}
		if ($only !== null) {
			return ['findings' => $findings, 'warnings' => []];
		}

		['old' => $old, 'kept' => $kept] = $this->erasure->oldPseudonyms();
		foreach ($old as $account) {
			$findings[] = self::finding('pseudonym', null, null, null, $account . ' is a pseudonym from before 0.3.0 that a new account could take; the upgrade\'s repair step renames it (occ maintenance:repair)');
		}
		$warnings = array_map(static fn (string $account): array => self::finding('pseudonym', null, null, null,
			'the account ' . $account . ' is named like an erased driver and kept its rows; if it was made to take them, deleting it erases them'), $kept);

		return ['findings' => $findings, 'warnings' => $warnings];
	}

	/**
	 * The vehicle a check is narrowed to, live or in the trash: a deleted one is checked too,
	 * since an undo brings it back as it is.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function vehicle(string $uuid): Vehicle {
		return $this->vehicles->findAnyByUuid($uuid);
	}

	/**
	 * Rows whose vehicle is gone, not merely deleted: a deleted vehicle keeps its rows for the
	 * undo (docs/architecture.md, "Nothing purges yet").
	 *
	 * @return list<Finding>
	 * @throws \OCP\DB\Exception
	 */
	private function orphans(): array {
		$findings = [];
		foreach ($this->tables->orphans() as ['table' => $table, 'uuid' => $uuid, 'vehicle_id' => $vehicleId]) {
			$findings[] = self::finding('orphan', $table, $uuid, null, 'vehicle_id ' . $vehicleId . ' names no vehicle');
		}

		return $findings;
	}

	/**
	 * Each chain's flags and cache, against what settling it would write (rules 2 and 3).
	 *
	 * @return list<Finding>
	 * @throws \OCP\DB\Exception
	 */
	private function odometer(Vehicle $vehicle): array {
		$findings = [];
		foreach ($this->odometer->drift($vehicle) as $change) {
			if ($change['column'] === 'flagged') {
				$reason = $change['after'] === true ? 'not flagged, but the chain questions it' : 'flagged, but the chain does not question it';
				$findings[] = self::finding('flag', $change['table'], $change['row'], $vehicle, $reason);
			} else {
				$findings[] = self::finding('cache', $change['table'], $change['row'], $vehicle,
					$change['column'] . ' ' . self::number($change['before']) . ', but the newest ' . $change['counter'] . ' Reading is ' . self::number($change['after']));
			}
		}

		return $findings;
	}

	/**
	 * Each Entry's Readings against the Entry as it stands (rule 5): one live Reading per counter
	 * it states while it is live, none otherwise. A trip states `main` and nothing else.
	 *
	 * @return list<Finding>
	 * @throws \OCP\DB\Exception
	 */
	private function entries(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		$readings = $this->readings->findAnyFromEntries($vehicleId);
		$ids = [];
		$standing = [];
		foreach ($readings as $reading) {
			$ids[$reading->getSourceType()][] = (int)$reading->getSourceId();
			if ($reading->getDeletedAt() === null) {
				$key = $reading->getSourceType() . ' ' . (int)$reading->getSourceId() . ' ' . $reading->getCounter();
				$standing[$key] = ($standing[$key] ?? 0) + 1;
			}
		}
		$mappers = [OdoReading::TRIP => $this->trips, OdoReading::ENERGY => $this->energy, OdoReading::MAINTENANCE => $this->maintenance];
		$entries = [];
		foreach ($mappers as $type => $mapper) {
			$entries[$type] = $mapper->findAnyByIds($vehicleId, array_values(array_unique($ids[$type] ?? [])));
		}

		$findings = [];
		foreach ($readings as $reading) {
			$type = $reading->getSourceType();
			$sourceId = (int)$reading->getSourceId();
			$counter = $reading->getCounter();
			$live = $reading->getDeletedAt() === null;
			$entry = $entries[$type][$sourceId] ?? null;
			$name = self::ENTRIES[$type] ?? $type;
			if ($entry === null) {
				$reason = $live ? 'live, but its ' . $name . ' #' . $sourceId . ' does not exist' : null;
			} else {
				$states = match (true) {
					$entry instanceof Energy, $entry instanceof Maintenance => ($counter === OdoReading::MAIN ? $entry->getOdo() : $entry->getSecondOdo()) !== null,
					default => $counter === OdoReading::MAIN,
				};
				$entryLive = $entry->getDeletedAt() === null;
				$alike = $standing[$type . ' ' . $sourceId . ' ' . $counter] ?? 0;
				$reason = match (true) {
					$live && !$entryLive => 'live, but its ' . $name . ' ' . $entry->getUuid() . ' is deleted',
					$live && !$states => 'live, but its ' . $name . ' ' . $entry->getUuid() . ' states no ' . $counter . ' counter',
					$live && $alike > 1 => 'one of ' . $alike . ' live Readings its ' . $name . ' ' . $entry->getUuid() . ' wrote on the ' . $counter . ' counter',
					!$live && $entryLive && $states && $alike === 0 => 'deleted, but its ' . $name . ' ' . $entry->getUuid() . ' is live and states its ' . $counter . ' counter',
					default => null,
				};
			}
			if ($reason !== null) {
				$findings[] = self::finding('entry', 'fleet_odo_readings', $reading->getUuid(), $vehicle, $reason);
			}
		}

		// An Entry with no Reading on a counter has nothing for the loop above to start from.
		foreach ($mappers as $type => $mapper) {
			$stated = $type === OdoReading::TRIP ? [OdoReading::MAIN => null] : [OdoReading::MAIN => 'odo', OdoReading::SECOND => 'second_odo'];
			foreach ($stated as $counter => $column) {
				foreach ($this->readings->findUnreadEntries($vehicleId, $type, $mapper->getTableName(), $counter, $column) as $uuid) {
					$findings[] = self::finding('entry', $mapper->getTableName(), $uuid, $vehicle, 'live and states the ' . $counter . ' counter, but wrote no Reading on it');
				}
			}
		}

		return $findings;
	}

	/**
	 * The owner, an account that still exists. Gone with no erasure marked is an account deleted
	 * while the app was disabled, since only then did no listener mark it: the vehicle keeps the
	 * uid, and a new account made under it would own the vehicle and read every row. An erased
	 * owner's pseudonym is how an erasure leaves a vehicle; one from before 0.3.0 is found here too.
	 *
	 * @param array{erasure: array<string, true>, group: array<string, true>} $pending
	 * @param array<string, bool> $exists the accounts and groups asked about so far
	 * @return list<Finding>
	 */
	private function owner(Vehicle $vehicle, array $pending, array &$exists): array {
		$owner = $vehicle->getUserId();
		if (isset($pending['erasure'][$owner]) || str_starts_with($owner, ErasureService::PREFIX)
			|| ($exists['the user ' . $owner] ??= $this->users->userExists($owner))) {
			return [];
		}

		return [self::finding('owner', 'fleet_vehicles', $vehicle->getUuid(), $vehicle,
			'owned by the user ' . $owner . ', whom no backend knows (or it is out of reach), and no erasure is pending; deleted while the app was disabled?')];
	}

	/**
	 * Each live grant: one per grantee, to a user or group that still exists. A grantee gone is
	 * not a finding while an erasure or a group's revokes are pending for it (PendingJob finishes
	 * them), nor when it is an erased account's pseudonym, which is how an erasure leaves a grant;
	 * one from before 0.3.0 is the pseudonym check's.
	 *
	 * Gone is what the backends answer, and one out of reach (LDAP) answers it for a live account,
	 * so the reason says so: GrantService::took() does not erase on it either.
	 *
	 * @param array{erasure: array<string, true>, group: array<string, true>} $pending
	 * @param array<string, bool> $exists the grantees asked about so far, by type and name
	 * @return list<Finding>
	 * @throws \OCP\DB\Exception
	 */
	private function grants(Vehicle $vehicle, array $pending, array &$exists): array {
		$grants = $this->access->findByVehicle((int)$vehicle->getId());
		$alike = [];
		foreach ($grants as $grant) {
			$alike[$grant->getGranteeType() . ' ' . $grant->getGrantee()][] = $grant;
		}

		$findings = [];
		foreach ($grants as $grant) {
			$grantee = 'the ' . $grant->getGranteeType() . ' ' . $grant->getGrantee();
			$twins = count($alike[$grant->getGranteeType() . ' ' . $grant->getGrantee()]);
			if ($twins > 1) {
				$findings[] = self::finding('duplicate', 'fleet_access', $grant->getUuid(), $vehicle, 'one of ' . $twins . ' live grants to ' . $grantee);
			}
			$name = $grant->getGrantee();
			$group = $grant->getGranteeType() === Access::GROUP;
			$expected = $group
				? isset($pending['group'][$name])
				: isset($pending['erasure'][$name]) || str_starts_with($name, ErasureService::PREFIX);
			if (!$expected && !($exists[$grantee] ??= $group ? $this->groups->groupExists($name) : $this->users->userExists($name))) {
				$findings[] = self::finding('grantee', 'fleet_access', $grant->getUuid(), $vehicle,
					'live, but no backend knows ' . $grantee . ' (or it is out of reach) and nothing pending revokes the grant');
			}
		}

		return $findings;
	}

	/**
	 * The uids and gids Pending holds, by kind.
	 *
	 * @return array{erasure: array<string, true>, group: array<string, true>}
	 */
	private function pending(): array {
		$marked = [];
		foreach ([Pending::ERASURE, Pending::GROUP] as $kind) {
			$marked[$kind] = array_fill_keys(array_column($this->pending->of($kind), 'id'), true);
		}

		return $marked;
	}

	private static function number(int|bool|null $value): string {
		return $value === null ? 'empty' : (string)(int)$value;
	}

	/** @return Finding */
	private static function finding(string $check, ?string $table, ?string $row, ?Vehicle $vehicle, string $reason): array {
		return ['check' => $check, 'table' => $table, 'row' => $row, 'vehicle' => $vehicle?->getUuid(), 'reason' => $reason];
	}
}
