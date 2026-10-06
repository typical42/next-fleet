<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\CurrencyInUseException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Everything a vehicle is written under, so the controller carries none of it: which columns a
 * request may decide, what they have to look like, and what the ones it leaves out become.
 */
class VehicleService {
	use TTransactional;

	/** CONTEXT.md's vocabulary, and the only words these columns take. */
	public const VEHICLE_TYPES = ['car', 'motorcycle', 'van', 'truck', 'trailer', 'tractor', 'generator'];
	private const ENGINES = ['petrol', 'diesel', 'lpg', 'cng', 'electric', 'hybrid'];
	public const ENERGIES = ['petrol', 'diesel', 'lpg', 'cng', 'electric'];
	/** What a new vehicle takes when the request names no energy: a gas car starts on petrol. */
	private const ENGINE_ENERGIES = [
		'petrol' => ['petrol'],
		'diesel' => ['diesel'],
		'lpg' => ['petrol', 'lpg'],
		'cng' => ['petrol', 'cng'],
		'electric' => ['electric'],
		'hybrid' => ['petrol', 'electric'],
	];
	/** Kilometres or engine hours - a tractor counts neither in km nor in miles. */
	private const ODO_UNITS = ['km', 'h'];
	/** A second counter is engine hours beside kilometres, and nothing else. */
	private const SECOND_UNITS = ['h'];
	private const LIFECYCLES = [Vehicle::ACTIVE, Vehicle::LAID_UP, Vehicle::DISPOSED];

	/**
	 * The columns a request may set, each with the setter it reaches and what it has to look
	 * like: `text` with the column's own length, `word` and `set` against a vocabulary,
	 * `number` and `count` (never negative) as integers, `date` as one calendar day, `currency`
	 * as an ISO 4217 code in capitals.
	 *
	 * What is missing is the point. `uuid` is the identity and `user_id`/`created_by` the
	 * provenance, so a request cannot choose them; `odo_value` and `second_value` are caches
	 * recomputed from the Readings (docs/architecture.md#odometer-rules); and `folder_file_id` is
	 * the app's to set (docs/architecture.md#nextcloud-integration).
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
		'tank_ml' => ['setTankMl', 'count', Field::CAPACITY],
		'battery_wh' => ['setBatteryWh', 'count', Field::CAPACITY],
		'first_reg' => ['setFirstReg', 'date', null],
		'disposed_at' => ['setDisposedAt', 'date', null],
		'vin' => ['setVin', 'text', 32],
		'odo_unit' => ['setOdoUnit', 'word', self::ODO_UNITS],
		'second_unit' => ['setSecondUnit', 'word', self::SECOND_UNITS],
		'purchase_price' => ['setPurchasePrice', 'number', Field::MONEY],
		'residual_est' => ['setResidualEst', 'number', Field::MONEY],
		'currency' => ['setCurrency', 'currency', null],
		'jurisdiction' => ['setJurisdiction', 'text', 8],
		'logbook_mode' => ['setLogbookMode', 'flag', null],
		'lifecycle' => ['setLifecycle', 'word', self::LIFECYCLES],
		'retention_months' => ['setRetentionMonths', 'count', Field::MONTHS],
		'color' => ['setColor', 'text', 32],
		'notes' => ['setNotes', 'text', Field::TEXT],
		'reminder_mail' => ['setReminderMail', 'word', self::MAIL_CADENCES],
	];

	/** How often the reminder digest goes out for this vehicle (docs/architecture.md#reminder-engine). */
	private const MAIL_CADENCES = [Vehicle::MAIL_OFF, Vehicle::MAIL_DAILY, Vehicle::MAIL_WEEKLY, Vehicle::MAIL_MONTHLY];

	/**
	 * The columns the database will not default. A request that empties one keeps what the row
	 * already has rather than being refused: the sheet never blocks on validation (docs/ui.md).
	 */
	private const REQUIRED = ['vehicle_type', 'odo_unit', 'jurisdiction', 'lifecycle', 'reminder_mail'];

	/**
	 * What the create sheet does not ask for (docs/ui.md) and no country decides: neither a car
	 * nor being in service is a German fact. Units and currency are the profile's
	 * (lib/Jurisdiction/IJurisdiction.php).
	 */
	private const VEHICLE_TYPE_FALLBACK = 'car';
	private const LIFECYCLE_FALLBACK = 'active';

	/**
	 * What kind of change the audit row records, a key in `diff_json` rather than a column
	 * (docs/architecture.md#data-model): the Logbook Mode switch being flipped, or one of the
	 * vehicle's `FACTS` changed without one.
	 */
	private const SWITCHED = 'switched';
	private const EDITED = 'edited';

	/**
	 * The columns a logbook or a cost is read under, each with its getter: their history is kept
	 * mode or not, because the Fahrtenbuch prints the plate valid in each period and an auditor
	 * asks when the country or the currency changed. In `WRITABLE`'s order, which is the order a row
	 * lists them in.
	 */
	private const FACTS = [
		'plate' => 'getPlate',
		'vehicle_type' => 'getVehicleType',
		'currency' => 'getCurrency',
		'jurisdiction' => 'getJurisdiction',
	];

	/** How far ahead the reader's own next booking is named (docs/ui.md). */
	private const WEEK = 7 * 86400;

	public function __construct(
		private VehicleMapper $mapper,
		private VehicleAccess $access,
		private IConfig $config,
		private Jurisdictions $jurisdictions,
		private AuditMapper $audit,
		private IDBConnection $db,
		private ReminderRecipientMapper $recipients,
		private ReminderMapper $reminders,
		private NotificationService $notifications,
		private GrantNotices $grantNotices,
		private BookingNotices $bookingNotices,
		private BookingMapper $bookings,
		private ITimeFactory $time,
		private IUserManager $users,
		private MoneyRows $money,
		private AccessMapper $grants,
	) {
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, array $fields): Vehicle {
		$once = Once::of(
			$fields,
			$this->mapper,
			// Answered to its owner only: a uuid is no way into somebody else's vehicle.
			static fn (Vehicle $row): bool => $row->getUserId() === $userId,
			function (Vehicle $row) use ($userId): Vehicle {
				$row->setMay($this->access->operations($userId, $row));

				return $row;
			},
		);

		return $once->run(fn (): Vehicle => $this->insert($userId, $fields, $once));
	}

	/**
	 * What create() writes once it knows the request is no retry.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Vehicle> $once
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, array $fields, Once $once): Vehicle {
		$vehicle = new Vehicle();
		$once->stamp($vehicle);
		$vehicle->setUserId($userId);
		$vehicle->setCreatedBy($userId);
		$jurisdiction = $this->jurisdictionOf($userId, $fields);
		$profile = $this->jurisdictions->get($jurisdiction);
		// Before the request, so a payload value wins; and set even where the property default
		// agrees, because QBMapper leaves a clean property out of the INSERT.
		$vehicle->setVehicleType(self::VEHICLE_TYPE_FALLBACK);
		$vehicle->setLifecycle(self::LIFECYCLE_FALLBACK);
		$vehicle->setJurisdiction($jurisdiction);
		// What the country decides, asked rather than assumed. The answers reach no validator:
		// a profile is code, and tests/Country/ holds it to what these columns take. A null
		// currency is an answer - the vehicle states its own (docs/contributing.md).
		$vehicle->setOdoUnit($profile->odoUnit());
		$vehicle->setCurrency($profile->currency());
		$this->apply($vehicle, $fields);
		// The create sheet asks for the engine only; without energies *Energy* has nothing to
		// offer. An edit derives nothing: there the owner chose, emptying included.
		$engine = $vehicle->getEngine();
		if (($vehicle->getEnergyTypes() ?? []) === [] && $engine !== null) {
			$vehicle->setEnergyTypes(self::ENGINE_ENERGIES[$engine] ?? null);
		}

		// The owner is where the reminders go until somebody edits the list.
		return $this->atomic(function () use ($userId, $vehicle): Vehicle {
			$written = $this->mapper->insert($vehicle);
			$owner = new ReminderRecipient();
			$owner->setVehicleId((int)$written->getId());
			$owner->setUserId($userId);
			$owner->setCreatedBy($userId);
			$this->recipients->insert($owner);
			$written->setMay($this->access->operations($userId, $written));

			return $written;
		}, $this->db);
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
	 * One candidate key, or null where it is no key at all. A stored setting reaches no validator
	 * on its way in, so one the column cannot hold would otherwise fail every create; a request
	 * stating it is refused by `apply()` a moment later, which is where a 400 belongs.
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
	 * @throws CurrencyInUseException if the currency changes on a vehicle with amounts in it
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $uuid, int $expectedUpdatedAt, array $fields): Vehicle {
		$vehicle = $this->reach($userId, VehicleAccess::EDIT, $uuid);
		$was = clone $vehicle;
		$this->apply($vehicle, $fields);

		$written = $this->atomic(function () use ($userId, $vehicle, $expectedUpdatedAt, $was): Vehicle {
			// docs/architecture.md#data-model. Only a code is frozen: amounts under none, or under a
			// sign from before the check, are labelled by naming one, which the import asks for.
			// Under the hold every money write takes, so one racing this edit is seen.
			$frozen = $was->getCurrency();
			if ($frozen !== null && Field::isCurrency($frozen) && $vehicle->getCurrency() !== $frozen) {
				$this->mapper->hold((int)$vehicle->getId());
				if ($this->money->exist((int)$vehicle->getId())) {
					throw new CurrencyInUseException('currency stays once the vehicle has costs recorded in it');
				}
			}
			$written = $this->mapper->updateChecked($vehicle, $expectedUpdatedAt);
			$this->trail($userId, $written, $was);

			return $written;
		}, $this->db);
		// The screen replaces the vehicle it holds with this answer (src/store/index.js).
		$this->pool($userId, [$written]);

		return $written;
	}

	/**
	 * The audit row a save leaves on the vehicle when it flipped the Logbook Mode or changed one of
	 * its `FACTS`. The export reads the mode's periods off these rows
	 * (docs/features.md#logbook-mode); an unrecorded flip leaves trips it cannot place.
	 *
	 * Only a change: a sheet sends every column on every save (docs/ui.md), and a recorded mode it
	 * already had makes an auditor count periods that never began. `null` and `false` are both off
	 * here - the column is three-valued (docs/architecture.md#data-model), but a vehicle nobody
	 * ever switched is off.
	 *
	 * A vehicle created with the mode on has been under it since `created_at`; that needs no row.
	 *
	 * Inside the caller's transaction on purpose, the reason TripService::trail() gives.
	 *
	 * @param Vehicle $was the row as it was before the request was applied
	 * @throws \OCP\DB\Exception
	 */
	private function trail(string $userId, Vehicle $vehicle, Vehicle $was): void {
		$fields = [];
		foreach (self::FACTS as $column => $getter) {
			if ($was->$getter() !== $vehicle->$getter()) {
				$fields[$column] = [$was->$getter(), $vehicle->$getter()];
			}
		}
		$before = $was->getLogbookMode() === true;
		$now = $vehicle->getLogbookMode() === true;
		if ($before !== $now) {
			$fields['logbook_mode'] = [$before, $now];
		}
		if ($fields === []) {
			return;
		}

		$row = new Audit();
		$row->setCreatedBy($userId);
		$row->setEntity(Audit::VEHICLE);
		$row->setEntityId((int)$vehicle->getId());
		$row->setDiffJson(['change' => $before !== $now ? self::SWITCHED : self::EDITED, 'fields' => $fields]);
		$this->audit->insert($row);
	}

	/**
	 * A restore sends nothing back that withdraw() took; the next round tells whatever is still
	 * due.
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user does not own this vehicle
	 * @throws StaleUpdateException if the row has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $uuid, int $expectedUpdatedAt): Vehicle {
		$deleted = $this->mapper->softDelete(
			$this->reach($userId, VehicleAccess::OWN, $uuid),
			$expectedUpdatedAt,
		);
		$this->withdraw($deleted);

		return $deleted;
	}

	/**
	 * Takes back what a deleted vehicle's reminders have sent: the job no longer reads a deleted
	 * vehicle, so nothing else ever would. Its live grants' and cancelled bookings' notices go
	 * too, as litter of the same kind. After the delete stands, the reason
	 * NotificationService::sweep() gives.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function withdraw(Vehicle $deleted): void {
		foreach ($this->reminders->findByVehicle((int)$deleted->getId()) as $reminder) {
			$this->notifications->withdraw($reminder);
		}
		$this->grantNotices->withdrawAll($deleted);
		$this->bookingNotices->withdrawAll($deleted);
	}

	/**
	 * Undo, and it takes the right the delete took: only the owner brings a vehicle back. The
	 * lookup ignores `deleted_at`, so a stranger gets the same refusal as on every other route, not
	 * a 404 that would tell which uuids are in somebody's trash.
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the delete answered with
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user does not own this vehicle
	 * @throws StaleUpdateException if the row has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $uuid, int $expectedUpdatedAt): Vehicle {
		$vehicleId = $this->permit($userId, VehicleAccess::OWN, $this->mapper->findAnyByUuid($uuid))->getId();
		$restored = $this->atomic(function () use ($userId, $vehicleId, $expectedUpdatedAt): Vehicle {
			// An erasure renames the owner and leaves `updated_at` alone, so the token cannot tell.
			// Read again under the hold: an erasure that committed meanwhile is seen and refused.
			$this->mapper->hold($vehicleId);

			return $this->mapper->restoreChecked(
				$this->permit($userId, VehicleAccess::OWN, $this->mapper->findAnyById($vehicleId)),
				$expectedUpdatedAt,
			);
		}, $this->db);
		$this->pool($userId, [$restored]);

		return $restored;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user holds nothing on this vehicle
	 * @throws \OCP\DB\Exception
	 */
	public function find(string $userId, string $uuid): Vehicle {
		$vehicle = $this->reach($userId, VehicleAccess::VIEW, $uuid);
		$this->pool($userId, [$vehicle]);

		return $vehicle;
	}

	/**
	 * The one gate every uuid-addressed route goes through (docs/adr/0001-own-access-table.md):
	 * the row, or the reason there is none for this user. Public because everything hanging off a
	 * vehicle passes through it too; a second copy is a second place to forget an operation.
	 *
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	public function reach(string $userId, string $operation, string $uuid): Vehicle {
		return $this->permit($userId, $operation, $this->mapper->findByUuid($uuid));
	}

	/**
	 * The second gate an Entry passes, once reach() let the vehicle through with `log` and the
	 * Entry is found on it (VehicleAccess::mayChange()). Here once, for the five Entry services.
	 *
	 * @throws AccessDeniedException
	 */
	public function change(string $userId, string $operation, Vehicle $vehicle, ?string $createdBy): void {
		if (!$this->access->mayChange($userId, $operation, $vehicle, $createdBy)) {
			throw new AccessDeniedException();
		}
	}

	/**
	 * What change() would let through on one Entry, for its timeline row to carry as `may`: the
	 * screen offers an edit or a delete by the same answer the second gate refuses by.
	 *
	 * @return list<string>
	 */
	public function changes(string $userId, Vehicle $vehicle, ?string $createdBy): array {
		return array_values(array_filter(
			[VehicleAccess::EDIT, VehicleAccess::DELETE],
			fn (string $operation): bool => $this->access->mayChange($userId, $operation, $vehicle, $createdBy),
		));
	}

	/**
	 * The gate itself, once a row is in hand. Separate from reach() only because a restore looks
	 * the row up differently and must still be refused by the same rule.
	 *
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	private function permit(string $userId, string $operation, Vehicle $vehicle): Vehicle {
		// The whole list rather than may(): the same one query, and the vehicle leaves carrying
		// the answer the screen hides by.
		$may = $this->access->operations($userId, $vehicle);
		if (!in_array($operation, $may, true)) {
			throw new AccessDeniedException();
		}
		$vehicle->setMay($may);
		$this->nameOwner($userId, $vehicle);

		return $vehicle;
	}

	/**
	 * @return list<Vehicle>
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId): array {
		$reachable = $this->access->reachable($userId);
		$vehicles = $this->mapper->findAllVisible($userId, array_keys($reachable));
		// One lookup per owner rather than per vehicle: core does not promise to cache them.
		$names = [];
		foreach ($vehicles as $vehicle) {
			$vehicle->setMay($this->access->listed($userId, $vehicle, $reachable));
			$this->nameOwner($userId, $vehicle, $names);
		}
		$this->pool($userId, $vehicles, $names);

		return $vehicles;
	}

	/**
	 * Who has each car, the reader's own next booking of it within a week, and whether anybody was
	 * ever given access, the rule its Bookings show by (docs/ui.md): one query each for the whole
	 * list rather than per vehicle. A booking is seen with `view`, which every vehicle handed out is.
	 *
	 * @param list<Vehicle> $vehicles
	 * @param array<string, string> $names the names already looked up, by uid
	 * @throws \OCP\DB\Exception
	 */
	private function pool(string $userId, array $vehicles, array &$names = []): void {
		$byId = [];
		foreach ($vehicles as $vehicle) {
			$byId[(int)$vehicle->getId()] = $vehicle;
		}
		foreach ($this->grants->everGrantedAmong(array_keys($byId)) as $id) {
			$byId[$id]->setEverGranted(true);
		}
		$now = $this->time->getTime();
		foreach ($this->bookings->findPooled(array_keys($byId), $userId, $now, $now + self::WEEK) as $booking) {
			$vehicle = $byId[$booking->getVehicleId()];
			if ($booking->getState() === Booking::OUT) {
				$booker = $booking->getUserId();
				$names[$booker] ??= $this->users->getDisplayName($booker) ?? $booker;
				$vehicle->setOutWith([
					'user_id' => $booker,
					'user_name' => $names[$booker],
					'ends_at' => $booking->getEndsAt(),
					'ends_at_off' => $booking->getEndsAtOff(),
				]);
			} elseif ($vehicle->getMyNextBooking() === null) {
				// By start, so the first is the next.
				$vehicle->setMyNextBooking([
					'uuid' => $booking->getUuid(),
					'starts_at' => $booking->getStartsAt(),
					'starts_at_off' => $booking->getStartsAtOff(),
					'ends_at' => $booking->getEndsAt(),
					'ends_at_off' => $booking->getEndsAtOff(),
				]);
			}
		}
	}

	/**
	 * Whose vehicle a grantee is looking at. An owner with no account - an erased one's pseudonym
	 * (docs/adr/0008-erasing-a-driver-pseudonymises.md) - reads as the uid the row carries.
	 *
	 * @param array<string, string> $names the names already looked up, by uid
	 */
	private function nameOwner(string $userId, Vehicle $vehicle, array &$names = []): void {
		$owner = $vehicle->getUserId();
		if ($owner === $userId) {
			return;
		}
		$names[$owner] ??= $this->users->getDisplayName($owner) ?? $owner;
		$vehicle->setOwnedBy($names[$owner]);
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
			// A sheet sends the whole vehicle back: a currency from before currency() checked it
			// stays until somebody changes it.
			if ($column === 'currency' && $fields[$column] !== null && $fields[$column] === $vehicle->getCurrency()) {
				continue;
			}

			$value = $this->read($column, $kind, $limit, $fields[$column]);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				continue;
			}

			$vehicle->$setter($value);
		}

		// Hours beside hours would be one chain counted twice. Dropped rather than refused, as the
		// sheet never blocks on validation. Its Readings stay (docs/architecture.md).
		if ($vehicle->getOdoUnit() !== 'km' && $vehicle->getSecondUnit() !== null) {
			$vehicle->setSecondUnit(null);
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
		// Before the empty check, as Field::read() does it.
		$bound = in_array($kind, ['text', 'number', 'count'], true) ? Field::bound($column, $limit) : 0;
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		return match ($kind) {
			'text' => Field::text($column, $value, $bound),
			'word' => Field::word($column, $value, is_array($limit) ? $limit : []),
			'set' => $this->set($column, $value, is_array($limit) ? $limit : []),
			'number' => $this->number($column, $value, false, $bound),
			'count' => $this->number($column, $value, true, $bound),
			'flag' => Field::flag($column, $value),
			'date' => Field::day($column, $value),
			'currency' => self::currency($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
	}

	/**
	 * A sign or a name would leave the costs without a currency to add up in and an import
	 * without one to check against (lib/Import/Cells.php). Rows written before this check keep
	 * theirs (apply()).
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function currency(string $column, mixed $value): string {
		$code = is_string($value) ? strtoupper($value) : '';
		if (!Field::isCurrency($code)) {
			throw new \InvalidArgumentException($column . ' is a three-letter ISO 4217 code, such as EUR');
		}

		return $code;
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
			$words[] = Field::word($column, $word, $vocabulary);
		}

		return array_values(array_unique($words));
	}

	/** @throws \InvalidArgumentException */
	private function number(string $column, mixed $value, bool $unsigned, int $max): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false) {
			throw new \InvalidArgumentException($column . ' is a whole number');
		}
		if ($unsigned && $number < 0) {
			throw new \InvalidArgumentException($column . ' is never negative');
		}
		if (abs($number) > $max) {
			throw new \InvalidArgumentException($column . ' is ' . ($unsigned ? '' : '-' . $max . ' at least and ') . $max . ' at most');
		}

		return $number;
	}
}
