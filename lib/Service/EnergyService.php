<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Everything a fill-up or a charging session is written under. The Readings its counters leave
 * are OdometerService's, in the one place that writes one (docs/architecture.md#odometer-rules).
 */
class EnergyService {
	use TTransactional;

	/**
	 * What a fill-up can be flagged for (CONTEXT.md). Computed on read and never stored, as a
	 * trip's completeness is: the answer is the row plus the vehicle, and the vehicle's
	 * `energy_types` can change after the fill-up.
	 */
	public const FOREIGN_ENERGY = 'foreign_energy';
	public const NO_PRICE = 'no_price';
	public const OVERFILLED = 'overfilled';

	/**
	 * The columns a request may set, each with the setter it reaches and what it has to look
	 * like - the shape TripService::WRITABLE has. The counters are not among them: each is a
	 * Reading as well as a column, and record() writes both.
	 *
	 * @var array<string, array{string, string, int|list<string>|null}>
	 */
	private const WRITABLE = [
		'filled_at' => ['setFilledAt', 'count', null],
		'filled_at_off' => ['setFilledAtOff', 'offset', null],
		'energy' => ['setEnergy', 'word', VehicleService::ENERGIES],
		'amount' => ['setAmount', 'count', null],
		'unit_price' => ['setUnitPrice', 'count', null],
		'total' => ['setTotal', 'count', null],
		'vat_rate' => ['setVatRate', 'count', null],
		'station' => ['setStation', 'text', 255],
		'location_kind' => ['setLocationKind', 'word', ['home', 'public']],
	];

	/**
	 * The booleans, which the database defaults to false. Written every time, because "full
	 * tank" defaults to on in the sheet and not in the column (docs/ui.md).
	 *
	 * @var array<string, string>
	 */
	private const SWITCHES = [
		'full_tank' => 'setFullTank',
		'missed_previous' => 'setMissedPrevious',
		'is_dc' => 'setIsDc',
	];

	/**
	 * The columns the database will not default. The sheet requires the amount and prefills the
	 * rest, so a request without one is a client that did not.
	 */
	private const REQUIRED = ['filled_at', 'filled_at_off', 'energy', 'amount'];

	/** How far back prefill() looks for stations: a few years of weekly fill-ups. */
	private const STATION_HISTORY = 200;

	public function __construct(
		private EnergyMapper $energies,
		private OdometerService $odometer,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private Jurisdictions $jurisdictions,
		private IDBConnection $db,
	) {
	}

	/**
	 * Writes one fill-up and a Reading per counter it was given.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed> the row as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$energy = new Energy();
		$energy->setVehicleId((int)$vehicle->getId());
		$energy->setCreatedBy($userId);
		$this->apply($vehicle, $energy, $fields);

		// Retried for the reason TripService::record() gives.
		$written = $this->atomicRetry(function () use ($userId, $vehicle, $energy): Energy {
			$this->vehicles->hold((int)$vehicle->getId());
			$written = $this->energies->insert($energy);
			$this->follow($vehicle, $userId, $written);

			return $written;
		}, $this->db);

		return self::wire($vehicle, $written);
	}

	/**
	 * Rewrites one fill-up in place, and its Readings with it. The request is the whole fill-up,
	 * as record() takes it: a field it leaves out is one the driver emptied (TripService::update()).
	 * No audit row - Logbook Mode covers trips only (docs/features.md#logbook-mode).
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return array<string, mixed> the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the fill-up has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $energyUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		// Looked up inside the transaction for the reason TripService::delete() gives.
		$edited = $this->atomicRetry(function () use ($userId, $vehicle, $energyUuid, $expectedUpdatedAt, $fields): Energy {
			$this->vehicles->hold((int)$vehicle->getId());
			$energy = $this->energies->findOnVehicle((int)$vehicle->getId(), $energyUuid);
			$this->apply($vehicle, $energy, $fields);
			$edited = $this->energies->updateChecked($energy, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $edited);

			return $edited;
		}, $this->db);

		return self::wire($vehicle, $edited);
	}

	/**
	 * Soft-deletes one fill-up and its Readings, and answers with the token the undo is checked
	 * against.
	 *
	 * @return array<string, mixed> the row as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the fill-up has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $energyUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		$deleted = $this->atomicRetry(function () use ($userId, $vehicle, $energyUuid, $expectedUpdatedAt): Energy {
			$this->vehicles->hold((int)$vehicle->getId());
			$energy = $this->energies->findOnVehicle((int)$vehicle->getId(), $energyUuid);
			$deleted = $this->energies->softDelete($energy, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $deleted);

			return $deleted;
		}, $this->db);

		return self::wire($vehicle, $deleted);
	}

	/**
	 * Undo, on the token the delete answered with (TripService::restore()).
	 *
	 * @return array<string, mixed> the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the fill-up has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $energyUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::DELETE, $vehicleUuid);

		$back = $this->atomicRetry(function () use ($userId, $vehicle, $energyUuid, $expectedUpdatedAt): Energy {
			$this->vehicles->hold((int)$vehicle->getId());
			$energy = $this->energies->findAnyOnVehicle((int)$vehicle->getId(), $energyUuid);
			$back = $this->energies->restoreChecked($energy, $expectedUpdatedAt);
			$this->follow($vehicle, $userId, $back);

			return $back;
		}, $this->db);

		return self::wire($vehicle, $back);
	}

	/**
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	private function follow(Vehicle $vehicle, string $userId, Energy $energy): void {
		$this->odometer->followEntry(
			$vehicle,
			$userId,
			OdoReading::ENERGY,
			(int)$energy->getId(),
			$energy->getFilledAt(),
			$energy->getFilledAtOff(),
			$energy->getOdo(),
			$energy->getSecondOdo(),
			$energy->getDeletedAt() !== null,
		);
	}

	/** @return array<string, mixed> */
	private static function wire(Vehicle $vehicle, Energy $energy): array {
		return $energy->jsonSerialize() + ['flags' => self::flags($vehicle, $energy)];
	}

	/**
	 * What the sheet prefills a fill-up with (docs/ui.md): the VAT rate of the vehicle's
	 * jurisdiction on the day of the fill-up, and the stations this vehicle has filled up at, each
	 * with the unit price it last charged per energy. Latest first, which is the order a driver
	 * who fills up where they did last time wants them in.
	 *
	 * @param array<string, mixed> $fields `at` and `off`: the moment the sheet is on
	 * @return array{vat_rate: ?int, stations: list<array{station: string, energy: string, unit_price: ?int}>}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not write this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the moment is not one
	 * @throws \OCP\DB\Exception
	 */
	public function prefill(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$at = Field::read('at', 'count', null, $fields['at'] ?? null);
		$off = Field::read('off', 'offset', null, $fields['off'] ?? null);
		if (!is_int($at) || !is_int($off)) {
			throw new \InvalidArgumentException('at and off are the moment a prefill is for');
		}

		$stations = [];
		foreach ($this->energies->findLatestAtStations((int)$vehicle->getId(), self::STATION_HISTORY) as $energy) {
			$key = $energy->getStation() . "\0" . $energy->getEnergy();
			if (!isset($stations[$key])) {
				$stations[$key] = [
					'station' => (string)$energy->getStation(),
					'energy' => $energy->getEnergy(),
					'unit_price' => $energy->getUnitPrice(),
				];
			}
		}

		return [
			'vat_rate' => $this->jurisdictions->vatRateAt($vehicle->getJurisdiction(), $at, $off),
			'stations' => array_values($stations),
		];
	}

	/**
	 * What the fill-up is flagged for: an energy the vehicle does not take
	 * (docs/architecture.md#data-model), no total, which leaves its cost unknown, or more than the
	 * tank or battery on file holds.
	 *
	 * @return list<string>
	 */
	public static function flags(Vehicle $vehicle, Energy $energy): array {
		$flags = [];
		if (!in_array($energy->getEnergy(), $vehicle->getEnergyTypes() ?? [], true)) {
			$flags[] = self::FOREIGN_ENERGY;
		}
		if ($energy->getTotal() === null) {
			$flags[] = self::NO_PRICE;
		}
		// Watt-hours go into the battery and millilitres into the tank; `amount` says which by
		// `energy` alone. A capacity nobody entered flags nothing.
		$capacity = $energy->getEnergy() === 'electric' ? $vehicle->getBatteryWh() : $vehicle->getTankMl();
		if ($capacity !== null && $energy->getAmount() > $capacity) {
			$flags[] = self::OVERFILLED;
		}

		return $flags;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 */
	private function apply(Vehicle $vehicle, Energy $energy, array $fields): void {
		foreach (self::WRITABLE as $column => [$setter, $kind, $limit]) {
			$value = Field::read($column, $kind, $limit, $fields[$column] ?? null);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				throw new \InvalidArgumentException($column . ' is a field every fill-up carries');
			}
			$energy->$setter($value);
		}
		foreach (self::SWITCHES as $column => $setter) {
			$energy->$setter(Field::read($column, 'flag', null, $fields[$column] ?? null) ?? false);
		}

		// A litre and a kilowatt-hour are both a thousand of what `amount` counts, so cents over
		// thousandths is tenths of a cent per unit either way (docs/architecture.md#data-model).
		if ($energy->getUnitPrice() === null && $energy->getTotal() !== null && $energy->getAmount() > 0) {
			$energy->setUnitPrice((int)round($energy->getTotal() * 10000 / $energy->getAmount()));
		}

		[$odo, $secondOdo] = OdometerService::entryCounters($vehicle, $fields);
		$energy->setOdo($odo);
		$energy->setSecondOdo($secondOdo);
	}
}
