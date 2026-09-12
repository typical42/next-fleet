<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Everything a vehicle is written under, so the controller carries none of it: which columns a
 * request may decide, what they have to look like, and what the ones it leaves out become.
 */
class VehicleService {
	use TTransactional;

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
	 * Readings (docs/architecture.md#odometer-rules); and `folder_file_id` waits for the documents
	 * that fill the folder.
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
		'logbook_mode' => ['setLogbookMode', 'flag', null],
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
	 * What the create sheet does not ask for (docs/ui.md) and no country decides: neither a car
	 * nor being in service is a German fact. Units and currency are the profile's
	 * (lib/Jurisdiction/IJurisdiction.php).
	 */
	private const VEHICLE_TYPE_FALLBACK = 'car';
	private const LIFECYCLE_FALLBACK = 'active';

	/**
	 * What kind of change the audit row records, a key in `diff_json` rather than a column
	 * (docs/architecture.md#data-model). This service writes the one kind: the Logbook Mode
	 * switch being flipped.
	 */
	private const SWITCHED = 'switched';

	public function __construct(
		private VehicleMapper $mapper,
		private VehicleAccess $access,
		private IConfig $config,
		private Jurisdictions $jurisdictions,
		private AuditMapper $audit,
		private IDBConnection $db,
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
		$jurisdiction = $this->jurisdictionOf($userId, $fields);
		$profile = $this->jurisdictions->get($jurisdiction);
		// Written before the request is applied, so a payload value wins - and written at all,
		// however well a property default already agrees, because a clean property is not
		// dirty and QBMapper leaves it out of the INSERT.
		$vehicle->setVehicleType(self::VEHICLE_TYPE_FALLBACK);
		$vehicle->setLifecycle(self::LIFECYCLE_FALLBACK);
		$vehicle->setJurisdiction($jurisdiction);
		// What the country decides, asked rather than assumed. The answers reach no validator:
		// a profile is code, and tests/Country/ holds it to what these columns take. A null
		// currency is an answer - the vehicle states its own (docs/contributing.md).
		$vehicle->setOdoUnit($profile->odoUnit());
		$vehicle->setCurrency($profile->currency());
		$this->apply($vehicle, $fields);

		return $this->mapper->insert($vehicle);
	}

	/**
	 * Which country's rules the new vehicle is written under: what the request states, else the
	 * user's own default (docs/ui.md), else this release's. An unknown key is kept rather than
	 * corrected - the registration list answers for it.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function jurisdictionOf(string $userId, array $fields): string {
		$stored = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			'jurisdiction',
			Jurisdictions::DEFAULT,
		);

		return $this->jurisdictionIn($fields['jurisdiction'] ?? null)
			?? $this->jurisdictionIn($stored)
			?? Jurisdictions::DEFAULT;
	}

	/**
	 * One candidate key, or null where it is no key at all. A stored setting reaches no
	 * validator on its way in, so one the column cannot hold would otherwise fail every create
	 * rather than the one screen that wrote it; a request stating the same thing is refused by
	 * `apply()` a moment later, which is where a 400 belongs.
	 */
	private function jurisdictionIn(mixed $value): ?string {
		[, $kind, $limit] = self::WRITABLE['jurisdiction'];
		try {
			$jurisdiction = $this->read('jurisdiction', $kind, $limit, $value);
		} catch (\InvalidArgumentException) {
			return null;
		}

		return is_string($jurisdiction) ? $jurisdiction : null;
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
		$was = $vehicle->getLogbookMode() === true;
		$this->apply($vehicle, $fields);

		return $this->atomic(function () use ($userId, $vehicle, $expectedUpdatedAt, $was): Vehicle {
			$written = $this->mapper->updateChecked($vehicle, $expectedUpdatedAt);
			$this->trail($userId, $written, $was);

			return $written;
		}, $this->db);
	}

	/**
	 * The audit row a flip of the Logbook Mode leaves on the vehicle, and the only row this
	 * service writes: the mode is what the export reads the periods it was on off
	 * (docs/features.md#logbook-mode), and a flip nobody recorded would leave the export with
	 * trips it cannot place on either side of it.
	 *
	 * Only a flip. An update that states the mode it already had changed nothing, and a trail
	 * that says otherwise makes an auditor count periods that never began. `null` and `false`
	 * are the same answer here - the column is three-valued (docs/architecture.md#data-model),
	 * but a vehicle nobody ever switched is off, not in a third state.
	 *
	 * Where the first period begins is not a row: a vehicle created with the mode already on
	 * has been under it since it was created, which is `created_at` and nothing this has to
	 * state.
	 *
	 * Inside the caller's transaction on purpose, the reason TripService::trail() gives.
	 *
	 * @param bool $was the mode as the row carried it before the request was applied
	 * @throws \OCP\DB\Exception
	 */
	private function trail(string $userId, Vehicle $vehicle, bool $was): void {
		$now = $vehicle->getLogbookMode() === true;
		if ($now === $was) {
			return;
		}

		$row = new Audit();
		$row->setCreatedBy($userId);
		$row->setEntity(Audit::VEHICLE);
		$row->setEntityId((int)$vehicle->getId());
		$row->setDiffJson(['change' => self::SWITCHED, 'fields' => ['logbook_mode' => [$was, $now]]]);
		$this->audit->insert($row);
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
	 * Undo, and it takes the right the delete took - a viewer who cannot delete cannot un-delete.
	 * The lookup ignores `deleted_at`, so a stranger gets the same refusal here as on every other
	 * route rather than a 404 that would tell them which uuids are in somebody's trash.
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the delete answered with
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user may not delete this vehicle
	 * @throws StaleUpdateException if the row has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $uuid, int $expectedUpdatedAt): Vehicle {
		return $this->mapper->restoreChecked(
			$this->permit($userId, VehicleAccess::DELETE, $this->mapper->findAnyByUuid($uuid)),
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
		return $this->permit($userId, $operation, $this->mapper->findByUuid($uuid));
	}

	/**
	 * The gate itself, once a row is in hand. Separate from reach() only because a restore looks
	 * the row up differently and must still be refused by the same rule.
	 *
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	private function permit(string $userId, string $operation, Vehicle $vehicle): Vehicle {
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
	private function read(string $column, string $kind, int|array|null $limit, mixed $value): string|int|bool|array|\DateTime|null {
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
			'flag' => $this->flag($column, $value),
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
	 * A boolean column, which a form posts as a word and JSON as itself. `false` never reaches
	 * here as an empty value - only `''` does, and that is a field nobody answered, which for a
	 * three-valued boolean column is its own state (docs/architecture.md#data-model).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function flag(string $column, mixed $value): bool {
		$flag = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		if ($flag === null) {
			throw new \InvalidArgumentException($column . ' is true or false');
		}

		return $flag;
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
