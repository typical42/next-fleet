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
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
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
	public const VEHICLE_TYPES = ['car', 'van', 'truck', 'trailer', 'tractor', 'generator'];
	private const ENGINES = ['petrol', 'diesel', 'lpg', 'cng', 'electric', 'hybrid'];
	public const ENERGIES = ['petrol', 'diesel', 'lpg', 'cng', 'electric'];
	/** Kilometres or engine hours - a tractor counts neither in km nor in miles. */
	private const ODO_UNITS = ['km', 'h'];
	/** A second counter is engine hours beside kilometres, and nothing else. */
	private const SECOND_UNITS = ['h'];
	private const LIFECYCLES = [Vehicle::ACTIVE, Vehicle::LAID_UP, Vehicle::DISPOSED];

	/**
	 * The columns a request may set, each with the setter it reaches and what it has to look
	 * like: `text` with the column's own length, `word` and `set` against a vocabulary,
	 * `number` and `count` (never negative) as integers, `date` as one calendar day.
	 *
	 * What is missing is the point. `uuid` is the identity and `user_id`/`created_by` the
	 * provenance, so a request cannot choose them; `odo_value` and `second_value` are caches recomputed from the
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
		'second_unit' => ['setSecondUnit', 'word', self::SECOND_UNITS],
		'purchase_price' => ['setPurchasePrice', 'number', null],
		'residual_est' => ['setResidualEst', 'number', null],
		'currency' => ['setCurrency', 'text', 3],
		'jurisdiction' => ['setJurisdiction', 'text', 8],
		'logbook_mode' => ['setLogbookMode', 'flag', null],
		'lifecycle' => ['setLifecycle', 'word', self::LIFECYCLES],
		'retention_months' => ['setRetentionMonths', 'count', null],
		'color' => ['setColor', 'text', 32],
		'notes' => ['setNotes', 'text', null],
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
	 * (docs/architecture.md#data-model). This service writes the one kind: the Logbook Mode
	 * switch being flipped.
	 */
	private const SWITCHED = 'switched';

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

		// The owner is where the reminders go until somebody edits the list, which is what the
		// migration gave every vehicle that existed before it.
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

		$written = $this->atomic(function () use ($userId, $vehicle, $expectedUpdatedAt, $was): Vehicle {
			$written = $this->mapper->updateChecked($vehicle, $expectedUpdatedAt);
			$this->trail($userId, $written, $was);

			return $written;
		}, $this->db);
		// The screen replaces the vehicle it holds with this answer (src/store/index.js).
		$this->pool($userId, [$written]);

		return $written;
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
	 * Takes back what the vehicle's reminders have sent: the job no longer reads a deleted
	 * vehicle, so nothing else ever would. Its grants' and cancelled bookings' notices go too, as
	 * litter of the same kind. After the delete stands, the reason NotificationService::sweep()
	 * gives. A restore sends nothing back; the next round tells
	 * whatever is still due.
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
		foreach ($this->reminders->findByVehicle((int)$deleted->getId()) as $reminder) {
			$this->notifications->withdraw($reminder);
		}
		$this->grantNotices->withdrawAll($deleted);
		$this->bookingNotices->withdrawAll($deleted);

		return $deleted;
	}

	/**
	 * Undo, and it takes the right the delete took - only the owner brings a vehicle back.
	 * The lookup ignores `deleted_at`, so a stranger gets the same refusal here as on every other
	 * route rather than a 404 that would tell them which uuids are in somebody's trash.
	 *
	 * @param int $expectedUpdatedAt the `updated_at` the delete answered with
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the user does not own this vehicle
	 * @throws StaleUpdateException if the row has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $uuid, int $expectedUpdatedAt): Vehicle {
		$restored = $this->mapper->restoreChecked(
			$this->permit($userId, VehicleAccess::OWN, $this->mapper->findAnyByUuid($uuid)),
			$expectedUpdatedAt,
		);
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
	 * Who has each car, and the reader's own next booking of it within a week (docs/ui.md), in
	 * one query for the whole list rather than one per vehicle. A booking is seen with `view`,
	 * which every vehicle handed out is.
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

			$value = $this->read($column, $kind, $limit, $fields[$column]);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				continue;
			}

			$vehicle->$setter($value);
		}

		// Hours beside hours would be one chain counted twice. Dropped rather than refused: the
		// sheet never blocks on validation, and switching the main counter to hours is the
		// answer that makes the second one moot. Its Readings stay (docs/architecture.md).
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
		if (is_string($value)) {
			$value = trim($value);
		}
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		return match ($kind) {
			'text' => Field::text($column, $value, is_int($limit) ? $limit : null),
			'word' => Field::word($column, $value, is_array($limit) ? $limit : []),
			'set' => $this->set($column, $value, is_array($limit) ? $limit : []),
			'number' => $this->number($column, $value, false),
			'count' => $this->number($column, $value, true),
			'flag' => Field::flag($column, $value),
			'date' => Field::day($column, $value),
			default => throw new \InvalidArgumentException($column . ' has no readable kind'),
		};
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
}
