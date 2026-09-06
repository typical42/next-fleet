<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Everything a vehicle is written under, so the controller carries none of it: which columns a
 * request may decide, what they have to look like, and what the ones it leaves out become.
 */
class VehicleService {
	/** CONTEXT.md's vocabulary, and the only words these columns take. */
	private const VEHICLE_TYPES = ['car', 'van', 'trailer', 'tractor', 'generator'];
	private const ENGINES = ['petrol', 'diesel', 'lpg', 'cng', 'electric', 'hybrid'];
	private const ENERGIES = ['petrol', 'diesel', 'lpg', 'cng', 'electric'];
	/** Kilometres or engine hours - a tractor counts neither in km nor in miles. */
	private const ODO_UNITS = ['km', 'h'];
	private const LIFECYCLES = ['active', 'laid_up', 'disposed'];

	/**
	 * The columns a request may set, each with the setter it reaches and what it has to look
	 * like: `text` with the column's own length, `word` and `set` against a vocabulary,
	 * `number` and `count` (never negative) as integers, `date` as one calendar day.
	 *
	 * What is missing is the point. `uuid` is the identity and `user_id`/`created_by` the
	 * provenance, so a request cannot choose them; `odo_value` is a cache recomputed from the
	 * Readings (docs/architecture.md#odometer-rules); `logbook_mode` waits for M2 and
	 * `folder_file_id` for the documents that fill the folder.
	 *
	 * @var array<string, array{string, string, int|list<string>|null}>
	 */
	private const WRITABLE = [
		'plate' => ['setPlate', 'text', 32],
		'manufacturer' => ['setManufacturer', 'text', 64],
		'model' => ['setModel', 'text', 64],
		'vehicle_type' => ['setVehicleType', 'word', self::VEHICLE_TYPES],
		'engine' => ['setEngine', 'word', self::ENGINES],
		'energy_types' => ['setEnergyTypes', 'set', self::ENERGIES],
		'tank_ml' => ['setTankMl', 'count', null],
		'battery_wh' => ['setBatteryWh', 'count', null],
		'first_reg' => ['setFirstReg', 'date', null],
		'disposed_at' => ['setDisposedAt', 'date', null],
		'vin' => ['setVin', 'text', 32],
		'odo_unit' => ['setOdoUnit', 'word', self::ODO_UNITS],
		'purchase_price' => ['setPurchasePrice', 'number', null],
		'residual_est' => ['setResidualEst', 'number', null],
		'currency' => ['setCurrency', 'text', 3],
		'jurisdiction' => ['setJurisdiction', 'text', 8],
		'lifecycle' => ['setLifecycle', 'word', self::LIFECYCLES],
		'retention_months' => ['setRetentionMonths', 'count', null],
		'color' => ['setColor', 'text', 32],
		'notes' => ['setNotes', 'text', null],
	];

	/**
	 * The columns the database will not default. A request that empties one keeps what the row
	 * already has rather than being refused: the sheet never blocks on validation (docs/ui.md).
	 */
	private const REQUIRED = ['vehicle_type', 'odo_unit', 'jurisdiction', 'lifecycle'];

	/**
	 * What the create sheet does not ask for (docs/ui.md). Germany is the first jurisdiction
	 * (plan.md); metric is what a vehicle counts in until someone says otherwise.
	 */
	private const JURISDICTION_FALLBACK = 'de';
	private const VEHICLE_TYPE_FALLBACK = 'car';
	private const ODO_UNIT_FALLBACK = 'km';
	private const LIFECYCLE_FALLBACK = 'active';

	public function __construct(
		private VehicleMapper $mapper,
		private VehicleAccess $access,
		private IConfig $config,
	) {
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, array $fields): Vehicle {
		$vehicle = new Vehicle();
		$vehicle->setUserId($userId);
		$vehicle->setCreatedBy($userId);
		// Written before the request is applied, so a payload value wins - and written at all,
		// however well a property default already agrees, because a clean property is not
		// dirty and QBMapper leaves it out of the INSERT.
		$vehicle->setVehicleType(self::VEHICLE_TYPE_FALLBACK);
		$vehicle->setOdoUnit(self::ODO_UNIT_FALLBACK);
		$vehicle->setLifecycle(self::LIFECYCLE_FALLBACK);
		$vehicle->setJurisdiction($this->jurisdictionOf($userId));
		$this->apply($vehicle, $fields);

		return $this->mapper->insert($vehicle);
	}

	/**
	 * The user's own default (docs/ui.md), held to the same shape a request would be: a stored
	 * setting reaches no validator on its way in, and one the column cannot hold would fail
	 * every create rather than the one screen that wrote it.
	 */
	private function jurisdictionOf(string $userId): string {
		$stored = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			'jurisdiction',
			self::JURISDICTION_FALLBACK,
		);

		[, $kind, $limit] = self::WRITABLE['jurisdiction'];
		try {
			$jurisdiction = $this->read('jurisdiction', $kind, $limit, $stored);
		} catch (\InvalidArgumentException) {
			$jurisdiction = null;
		}

		return is_string($jurisdiction) ? $jurisdiction : self::JURISDICTION_FALLBACK;
	}

	/**
	 * What the request sends is written; what it leaves out stays as the row has it, including
	 * the jurisdiction - it belongs to the vehicle, not to whoever is editing it.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user may not edit this vehicle
	 * @throws StaleUpdateException if the row has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $uuid, int $expectedUpdatedAt, array $fields): Vehicle {
		$vehicle = $this->reach($userId, VehicleAccess::EDIT, $uuid);
		$this->apply($vehicle, $fields);

		return $this->mapper->updateChecked($vehicle, $expectedUpdatedAt);
	}

	/**
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user may not delete this vehicle
	 * @throws StaleUpdateException if the row has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $uuid, int $expectedUpdatedAt): Vehicle {
		return $this->mapper->softDelete(
			$this->reach($userId, VehicleAccess::DELETE, $uuid),
			$expectedUpdatedAt,
		);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user holds nothing on this vehicle
	 * @throws \OCP\DB\Exception
	 */
	public function find(string $userId, string $uuid): Vehicle {
		return $this->reach($userId, VehicleAccess::VIEW, $uuid);
	}

	/**
	 * The one gate every uuid-addressed route goes through
	 * (docs/adr/0001-own-access-table.md): the row, or the reason there is none for this user.
	 * Public because everything hanging off a vehicle passes through it too - the odometer
	 * first - and a second copy of it is a second place to forget an operation.
	 *
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	public function reach(string $userId, string $operation, string $uuid): Vehicle {
		$vehicle = $this->mapper->findByUuid($uuid);
		if (!$this->access->may($userId, $operation, $vehicle)) {
			throw new AccessDeniedException();
		}

		return $vehicle;
	}

	/**
	 * @return list<Vehicle>
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId): array {
		return $this->mapper->findAllVisible($userId, $this->access->reachableVehicleIds($userId));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 */
	private function apply(Vehicle $vehicle, array $fields): void {
		foreach (self::WRITABLE as $column => [$setter, $kind, $limit]) {
			if (!array_key_exists($column, $fields)) {
				continue;
			}

			$value = $this->read($column, $kind, $limit, $fields[$column]);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				continue;
			}

			$vehicle->$setter($value);
		}
	}

	/**
	 * One field, as its column holds it. An absent value and an empty one are the same fact,
	 * so both arrive here as null.
	 *
	 * @param int|list<string>|null $limit
	 * @throws \InvalidArgumentException
	 */
	private function read(string $column, string $kind, int|array|null $limit, mixed $value): string|int|array|\DateTime|null {
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		return match ($kind) {
			'text' => $this->text($column, $value, is_int($limit) ? $limit : null),
			'word' => $this->word($column, $value, is_array($limit) ? $limit : []),
			'set' => $this->set($column, $value, is_array($limit) ? $limit : []),
			'number' => $this->number($column, $value, false),
			'count' => $this->number($column, $value, true),
			'date' => $this->date($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
	}

	/** @throws \InvalidArgumentException */
	private function text(string $column, mixed $value, ?int $length): string {
		if (!is_string($value)) {
			throw new \InvalidArgumentException($column . ' is text');
		}
		// Refused rather than truncated: the database would refuse it too, and a 500 tells the
		// user nothing about which field was too long.
		if ($length !== null && mb_strlen($value) > $length) {
			throw new \InvalidArgumentException($column . ' is longer than ' . $length . ' characters');
		}

		return $value;
	}

	/**
	 * @param list<string> $vocabulary
	 * @throws \InvalidArgumentException
	 */
	private function word(string $column, mixed $value, array $vocabulary): string {
		if (!is_string($value) || !in_array($value, $vocabulary, true)) {
			throw new \InvalidArgumentException($column . ' is one of ' . implode(', ', $vocabulary));
		}

		return $value;
	}

	/**
	 * @param list<string> $vocabulary
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	private function set(string $column, mixed $value, array $vocabulary): array {
		if (!is_array($value)) {
			throw new \InvalidArgumentException($column . ' is a set of ' . implode(', ', $vocabulary));
		}

		$words = [];
		foreach ($value as $word) {
			$words[] = $this->word($column, $word, $vocabulary);
		}

		return array_values(array_unique($words));
	}

	/** @throws \InvalidArgumentException */
	private function number(string $column, mixed $value, bool $unsigned): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false) {
			throw new \InvalidArgumentException($column . ' is a whole number');
		}
		if ($unsigned && $number < 0) {
			throw new \InvalidArgumentException($column . ' is never negative');
		}

		return $number;
	}

	/**
	 * A calendar day, one fact, so it carries no offset and no time of day
	 * (docs/architecture.md#time). `!` zeroes what the format does not name, which is also what
	 * keeps the clock out of it.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function date(string $column, mixed $value): \DateTime {
		$day = is_string($value) ? \DateTime::createFromFormat('!Y-m-d', $value) : false;
		if ($day === false || $day->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException($column . ' is a day, as YYYY-MM-DD');
		}

		return $day;
	}
}
