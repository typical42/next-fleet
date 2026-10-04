<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\BookingController;
use OCA\NextFleet\Controller\DocumentController;
use OCA\NextFleet\Controller\EnergyController;
use OCA\NextFleet\Controller\ExpenseController;
use OCA\NextFleet\Controller\GrantController;
use OCA\NextFleet\Controller\ImportController;
use OCA\NextFleet\Controller\InboxController;
use OCA\NextFleet\Controller\KpiController;
use OCA\NextFleet\Controller\MaintenanceController;
use OCA\NextFleet\Controller\Ocs;
use OCA\NextFleet\Controller\OdometerController;
use OCA\NextFleet\Controller\PreferencesController;
use OCA\NextFleet\Controller\RecipientController;
use OCA\NextFleet\Controller\ReminderController;
use OCA\NextFleet\Controller\ReportController;
use OCA\NextFleet\Controller\TimelineController;
use OCA\NextFleet\Controller\TripController;
use OCA\NextFleet\Controller\VehicleController;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\InboxService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\SyncService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The stranger case on every vehicle route, against the real database and through the controller
 * a route actually reaches (docs/security.md). The API is addressed by uuid and sharing makes
 * guessing one worth the effort, so this is the realistic bug.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class VehicleIdorTest extends TestCase {
	use Accounts;

	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';
	private const CODRIVER = 'nextfleet-test-carol';
	private const MANAGER = 'nextfleet-test-dave';
	private const VIEWER = 'nextfleet-test-erin';
	/** Who walks the role matrix, by the role they hold on the vehicle under test. */
	private const HOLDERS = [
		'owner' => self::OWNER,
		'manager' => self::MANAGER,
		'driver' => self::CODRIVER,
		'viewer' => self::VIEWER,
		'stranger' => self::STRANGER,
	];
	/** A real account, which a grantee and a recipient have to be, so no refusal is that 400. */
	private const ACCOUNT = 'nextfleet-test-idor-real';
	private const PLATE = 'B-XY 123';
	/** An Entry uuid nothing wrote: the refusal must come before the lookup that would miss it. */
	private const NO_SUCH_ENTRY = '0195e2f1-1111-4000-8000-00000000dead';
	/**
	 * Each kind of Entry as the sheet sends it, for an add and an edit alike.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ENTRY_BODIES = [
		'odometer' => ['read_at' => 1750000000, 'read_at_off' => 120, 'value' => 1000],
		'trip' => ['started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120, 'end_odo' => 1000, 'category' => 'business'],
		'energy' => ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000],
		'maintenance' => ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change'],
		'expense' => ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000],
	];

	/** A preview or an import as the screen sends it, of a file nobody here has. */
	private const IMPORT_BODY = ['file_id' => 1, 'importer' => 'lubelogger', 'record_type' => 'fuel', 'units' => ['distance' => 'km', 'volume' => 'l'], 'tz' => 'Europe/Berlin', 'etag' => 'e'];
	/** An undo of an import whose one entry is not there. */
	private const UNDO_BODY = ['created' => [['type' => 'energy', 'uuid' => self::NO_SUCH_ENTRY]]];

	/**
	 * The routes that reach no vehicle by identity, so a stranger gets an answer rather than a
	 * refusal - their own fleet, their own settings. Listed rather than inferred: a route added
	 * here is a claim that nothing in its answer belongs to anybody else.
	 */
	private const NAMES_NO_VEHICLE = ['vehicle#index', 'vehicle#create', 'reminder#fleet', 'preferences#index', 'preferences#update', 'inbox#index', 'sync#index'];

	private VehicleService $service;
	private OdometerService $odometry;
	private TripService $journeys;
	private EnergyService $fillUps;
	private MaintenanceService $workshop;
	private ExpenseService $spending;
	private ReminderService $reminders;
	private RecipientService $recipients;
	private GrantService $access;
	private DocumentService $papers;
	private ImportService $imports;
	private TimelineService $history;
	private KpiService $figures;
	private LogbookExport $logbook;
	private MileageClaimExport $claim;
	private ExportService $csv;
	private PreferencesService $settings;
	private InboxService $inbox;
	private BookingService $pool;
	private SyncService $syncing;
	private AccessMapper $grants;
	private Vehicle $vehicle;
	/** Which door a route is walked through: the internal routes, or their OCS twins. */
	private bool $ocs = false;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::ACCOUNT]);
		\OCP\Server::get(IUserManager::class)->createUser(self::ACCOUNT, bin2hex(random_bytes(16)));
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::ACCOUNT]);
	}

	protected function setUp(): void {
		$this->service = \OCP\Server::get(VehicleService::class);
		$this->odometry = \OCP\Server::get(OdometerService::class);
		$this->journeys = \OCP\Server::get(TripService::class);
		$this->fillUps = \OCP\Server::get(EnergyService::class);
		$this->workshop = \OCP\Server::get(MaintenanceService::class);
		$this->spending = \OCP\Server::get(ExpenseService::class);
		$this->reminders = \OCP\Server::get(ReminderService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
		$this->access = \OCP\Server::get(GrantService::class);
		$this->papers = \OCP\Server::get(DocumentService::class);
		$this->imports = \OCP\Server::get(ImportService::class);
		$this->history = \OCP\Server::get(TimelineService::class);
		$this->figures = \OCP\Server::get(KpiService::class);
		$this->logbook = \OCP\Server::get(LogbookExport::class);
		$this->claim = \OCP\Server::get(MileageClaimExport::class);
		$this->csv = \OCP\Server::get(ExportService::class);
		$this->settings = \OCP\Server::get(PreferencesService::class);
		$this->inbox = \OCP\Server::get(InboxService::class);
		$this->pool = \OCP\Server::get(BookingService::class);
		$this->syncing = \OCP\Server::get(SyncService::class);
		$this->grants = \OCP\Server::get(AccessMapper::class);

		$this->forgetTestRows();
		$this->vehicle = $this->service->create(self::OWNER, ['plate' => self::PLATE]);
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = array_values(self::HOLDERS);

		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();

		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_access')
			->where($qb->expr()->in('grantee', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();

		// `fleet_access` again: a grant the owner gave to a real account names nobody above.
		foreach (['fleet_access', 'fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_reminders', 'fleet_reminder_recipients', 'fleet_bookings', 'fleet_documents'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('created_by', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * The controller as a route reaches it through the door under test: the real service, and a
	 * session that is whoever is asking. Through the OCS door it is the twin of the same name
	 * under `Ocs\`, built alike (tests/Unit/OcsRoutesTest.php).
	 *
	 * @param class-string $class the internal controller
	 * @param array<string, mixed> $params
	 */
	private function door(string $class, object $service, string $userId, array $params): object {
		$class = $this->ocs ? str_replace('\\Controller\\', '\\Controller\\Ocs\\', $class) : $class;

		return new $class(Application::APP_ID, $this->request($params), $service, $this->session($userId));
	}

	/**
	 * An answer from either door as one Response: an OCS refusal is an exception the framework
	 * turns into the envelope's status, so it stands here as that status.
	 *
	 * @param \Closure(): Response $call
	 */
	private function through(\Closure $call): Response {
		try {
			return $call();
		} catch (OCSException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getCode());
		}
	}

	/** @param array<string, mixed> $params */
	private function controller(string $userId, array $params): VehicleController|Ocs\VehicleController {
		/** @var VehicleController|Ocs\VehicleController */
		return $this->door(VehicleController::class, $this->service, $userId, $params);
	}

	/**
	 * The same, for the routes that hang off a vehicle.
	 *
	 * @param array<string, mixed> $params
	 */
	private function odometer(string $userId, array $params): OdometerController|Ocs\OdometerController {
		/** @var OdometerController|Ocs\OdometerController */
		return $this->door(OdometerController::class, $this->odometry, $userId, $params);
	}

	/**
	 * The same again, for the trips.
	 *
	 * @param array<string, mixed> $params
	 */
	private function trip(string $userId, array $params): TripController|Ocs\TripController {
		/** @var TripController|Ocs\TripController */
		return $this->door(TripController::class, $this->journeys, $userId, $params);
	}

	/**
	 * And for the fill-ups.
	 *
	 * @param array<string, mixed> $params
	 */
	private function energy(string $userId, array $params): EnergyController|Ocs\EnergyController {
		/** @var EnergyController|Ocs\EnergyController */
		return $this->door(EnergyController::class, $this->fillUps, $userId, $params);
	}

	/**
	 * And for the Maintenance Records.
	 *
	 * @param array<string, mixed> $params
	 */
	private function maintenance(string $userId, array $params): MaintenanceController|Ocs\MaintenanceController {
		/** @var MaintenanceController|Ocs\MaintenanceController */
		return $this->door(MaintenanceController::class, $this->workshop, $userId, $params);
	}

	/**
	 * And for the Expenses.
	 *
	 * @param array<string, mixed> $params
	 */
	private function expense(string $userId, array $params): ExpenseController|Ocs\ExpenseController {
		/** @var ExpenseController|Ocs\ExpenseController */
		return $this->door(ExpenseController::class, $this->spending, $userId, $params);
	}

	/**
	 * And for the reminders.
	 *
	 * @param array<string, mixed> $params
	 */
	private function reminder(string $userId, array $params): ReminderController|Ocs\ReminderController {
		/** @var ReminderController|Ocs\ReminderController */
		return $this->door(ReminderController::class, $this->reminders, $userId, $params);
	}

	/**
	 * And for who the reminders go to.
	 *
	 * @param array<string, mixed> $params
	 */
	private function recipient(string $userId, array $params): RecipientController|Ocs\RecipientController {
		/** @var RecipientController|Ocs\RecipientController */
		return $this->door(RecipientController::class, $this->recipients, $userId, $params);
	}

	/**
	 * And for who else may use the vehicle. Not `grant()`: that name writes a row.
	 *
	 * @param array<string, mixed> $params
	 */
	private function grantRoute(string $userId, array $params): GrantController|Ocs\GrantController {
		/** @var GrantController|Ocs\GrantController */
		return $this->door(GrantController::class, $this->access, $userId, $params);
	}

	/**
	 * And for the vehicle's papers.
	 *
	 * @param array<string, mixed> $params
	 */
	private function document(string $userId, array $params): DocumentController|Ocs\DocumentController {
		/** @var DocumentController|Ocs\DocumentController */
		return $this->door(DocumentController::class, $this->papers, $userId, $params);
	}

	/**
	 * And for an import.
	 *
	 * @param array<string, mixed> $params
	 */
	private function importRoute(string $userId, array $params): ImportController|Ocs\ImportController {
		/** @var ImportController|Ocs\ImportController */
		return $this->door(ImportController::class, $this->imports, $userId, $params);
	}

	/**
	 * And for the bookings.
	 *
	 * @param array<string, mixed> $params
	 */
	private function booking(string $userId, array $params): BookingController|Ocs\BookingController {
		/** @var BookingController|Ocs\BookingController */
		return $this->door(BookingController::class, $this->pool, $userId, $params);
	}

	/**
	 * A span a day from now, or `days` from now, which a booking may take.
	 *
	 * @return array<string, int>
	 */
	private static function tomorrow(int $days = 1): array {
		$start = time() + $days * 86400;

		return ['starts_at' => $start, 'starts_at_off' => 120, 'ends_at' => $start + 3 * 3600, 'ends_at_off' => 120];
	}

	/**
	 * And again, for the one read that shows everything at once.
	 *
	 * @param array<string, mixed> $params
	 */
	private function timeline(string $userId, array $params): TimelineController|Ocs\TimelineController {
		/** @var TimelineController|Ocs\TimelineController */
		return $this->door(TimelineController::class, $this->history, $userId, $params);
	}

	/**
	 * And for the header's figures.
	 *
	 * @param array<string, mixed> $params
	 */
	private function kpis(string $userId, array $params): KpiController|Ocs\KpiController {
		/** @var KpiController|Ocs\KpiController */
		return $this->door(KpiController::class, $this->figures, $userId, $params);
	}

	/**
	 * And for the printable pages, which show what the timeline shows.
	 *
	 * @param array<string, mixed> $params
	 */
	private function report(string $userId, array $params): ReportController {
		return new ReportController(Application::APP_ID, $this->request($params), $this->logbook, $this->claim, $this->csv, $this->session($userId));
	}

	/**
	 * The same, for the routes that name no vehicle at all. They are in the sweep because every
	 * route is: what they must not do is answer for somebody else.
	 *
	 * @param array<string, mixed> $params
	 */
	private function preferences(string $userId, array $params): PreferencesController|Ocs\PreferencesController {
		/** @var PreferencesController|Ocs\PreferencesController */
		return $this->door(PreferencesController::class, $this->settings, $userId, $params);
	}

	/**
	 * And for the inbox.
	 *
	 * @param array<string, mixed> $params
	 */
	private function inboxRoute(string $userId, array $params): InboxController|Ocs\InboxController {
		/** @var InboxController|Ocs\InboxController */
		return $this->door(InboxController::class, $this->inbox, $userId, $params);
	}

	/** @param array<string, mixed> $params */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	/** Whoever is asking, as the framework hands them to a controller. */
	private function session(string $userId): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * The vehicles an answer carries, whether it carries one, a list of them or a refusal - so
	 * one assertion covers routes of every shape.
	 *
	 * @return list<string>
	 */
	private function uuidsIn(Response $response): array {
		$data = $response instanceof DataResponse ? $response->getData() : null;
		$uuids = [];
		// A sync names vehicles in each of its parts.
		if (is_array($data) && is_array($data['vehicles'] ?? null) && is_array($data['changes'] ?? null) && is_array($data['unreachable'] ?? null)) {
			$uuids = [...array_column($data['vehicles'], 'uuid'), ...array_values($data['unreachable'])];
			foreach ($data['changes'] as $items) {
				$uuids = [...$uuids, ...array_column((array)$items, 'vehicle_uuid')];
			}

			return array_values(array_filter($uuids, 'is_string'));
		}
		foreach (is_array($data) ? $data : [$data] as $item) {
			if ($item instanceof Vehicle) {
				$uuids[] = $item->getUuid();
			}
			// The OCS door answers a vehicle in its wire form.
			if (is_array($item) && array_key_exists('plate', $item) && is_string($item['uuid'] ?? null)) {
				$uuids[] = $item['uuid'];
			}
			// A fleet-wide row names the vehicle it hangs off.
			if (is_array($item) && is_string($item['vehicle'] ?? null)) {
				$uuids[] = $item['vehicle'];
			}
		}

		return $uuids;
	}

	/**
	 * Every route that reaches a vehicle or something hanging off one, walked with someone who was
	 * granted nothing. The `default` arm is what makes it a sweep: a route added later has never
	 * been through it, and says so.
	 *
	 * @dataProvider fleetRoutes
	 */
	public function testAStrangerReachesNothingThroughAnyRoute(string $route, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$params = [
			'uuid' => $uuid,
			'updated_at' => $this->vehicle->getUpdatedAt(),
			'plate' => 'HH-ZZ 9',
			'value' => 999999,
			'read_at' => 1750000000,
			'read_at_off' => 120,
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 999999,
			'category' => 'business',
		];

		$response = $this->through(fn (): Response => match ($route) {
			'vehicle#index' => $this->controller(self::STRANGER, $params)->index(),
			'vehicle#create' => $this->controller(self::STRANGER, $params)->create(),
			'vehicle#show' => $this->controller(self::STRANGER, $params)->show($uuid),
			'vehicle#update' => $this->controller(self::STRANGER, $params)->update($uuid),
			'vehicle#delete' => $this->controller(self::STRANGER, $params)->delete($uuid),
			// Walked against a live vehicle on purpose: a restore that answered 404 for one would
			// be telling a stranger which uuids exist and which are in somebody's trash.
			'vehicle#restore' => $this->controller(self::STRANGER, $params)->restore($uuid),
			'odometer#index' => $this->odometer(self::STRANGER, $params)->index($uuid),
			'odometer#create' => $this->odometer(self::STRANGER, $params)->create($uuid),
			// Walked against Readings that are not there, for the reason the trip's are below.
			'odometer#update' => $this->odometer(self::STRANGER, $params)->update($uuid, self::NO_SUCH_ENTRY),
			'odometer#delete' => $this->odometer(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'odometer#restore' => $this->odometer(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'odometer#reset' => $this->odometer(self::STRANGER, $params)->reset($uuid, self::NO_SUCH_ENTRY),
			'trip#create' => $this->trip(self::STRANGER, $params)->create($uuid),
			'trip#prefill' => $this->trip(self::STRANGER, $params)->prefill($uuid),
			// Walked against a trip that is not there: the gate is the vehicle's, so a stranger
			// is refused before any uuid of a trip is looked up, and a 404 here would be the
			// answer telling them so.
			'trip#update' => $this->trip(self::STRANGER, $params)->update($uuid, self::NO_SUCH_ENTRY),
			'trip#delete' => $this->trip(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'trip#restore' => $this->trip(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'trip#reconcile' => $this->trip(self::STRANGER, $params + ['distance' => 200, 'from_at' => 1749990000, 'to_at' => 1750000000])
				->reconcile($uuid, self::NO_SUCH_ENTRY),
			'energy#create' => $this->energy(self::STRANGER, $params + ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'odo' => 999999])
				->create($uuid),
			'energy#prefill' => $this->energy(self::STRANGER, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			'maintenance#create' => $this->maintenance(self::STRANGER, $params + ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change', 'odo' => 999999])
				->create($uuid),
			'maintenance#prefill' => $this->maintenance(self::STRANGER, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			'expense#create' => $this->expense(self::STRANGER, $params + ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000])
				->create($uuid),
			'expense#prefill' => $this->expense(self::STRANGER, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			// Walked against rows that are not there, for the reason the trip's are.
			'energy#update' => $this->energy(self::STRANGER, $params + ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'odo' => 999999])
				->update($uuid, self::NO_SUCH_ENTRY),
			'energy#delete' => $this->energy(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'energy#restore' => $this->energy(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'maintenance#update' => $this->maintenance(self::STRANGER, $params + ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change', 'odo' => 999999])
				->update($uuid, self::NO_SUCH_ENTRY),
			'maintenance#delete' => $this->maintenance(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'maintenance#restore' => $this->maintenance(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'expense#update' => $this->expense(self::STRANGER, $params + ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000])
				->update($uuid, self::NO_SUCH_ENTRY),
			'expense#delete' => $this->expense(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'expense#restore' => $this->expense(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'reminder#index' => $this->reminder(self::STRANGER, $params)->index($uuid),
			'reminder#fleet' => $this->reminder(self::STRANGER, $params)->fleet(),
			'reminder#templates' => $this->reminder(self::STRANGER, $params)->templates($uuid),
			'reminder#create' => $this->reminder(self::STRANGER, $params + ['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 999999])
				->create($uuid),
			// Walked against a reminder that is not there, for the reason the trip's are.
			'reminder#update' => $this->reminder(self::STRANGER, $params + ['mode' => 'date', 'due_date' => '2027-03-31'])
				->update($uuid, self::NO_SUCH_ENTRY),
			'reminder#delete' => $this->reminder(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'reminder#restore' => $this->reminder(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			'reminder#snooze' => $this->reminder(self::STRANGER, $params + ['until' => '2036-05-01'])->snooze($uuid, self::NO_SUCH_ENTRY),
			'reminder#dismiss' => $this->reminder(self::STRANGER, $params)->dismiss($uuid, self::NO_SUCH_ENTRY),
			'recipient#index' => $this->recipient(self::STRANGER, $params)->index($uuid),
			'recipient#create' => $this->recipient(self::STRANGER, $params + ['user_id' => self::ACCOUNT])->create($uuid),
			'recipient#delete' => $this->recipient(self::STRANGER, $params)->delete($uuid, self::OWNER),
			'grant#index' => $this->grantRoute(self::STRANGER, $params)->index($uuid),
			'grant#create' => $this->grantRoute(self::STRANGER, $params + ['grantee' => self::ACCOUNT, 'grantee_type' => 'user', 'role' => 'manager'])->create($uuid),
			// Walked against a grant that is not there, for the reason the trip's are.
			'grant#update' => $this->grantRoute(self::STRANGER, $params + ['role' => 'manager'])->update($uuid, self::NO_SUCH_ENTRY),
			'grant#delete' => $this->grantRoute(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'grant#held' => $this->grantRoute(self::STRANGER, $params)->held($uuid),
			'grant#leave' => $this->grantRoute(self::STRANGER, $params)->leave($uuid),
			'document#index' => $this->document(self::STRANGER, $params)->index($uuid),
			// The access check runs before the file is looked up, so this is the 403 whatever file 1 is.
			'document#create' => $this->document(self::STRANGER, $params + ['file_id' => 1, 'kind' => 'receipt'])->create($uuid),
			// Walked against a document that is not there, for the reason the trip's are.
			'document#delete' => $this->document(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'document#restore' => $this->document(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_ENTRY),
			// The same; DocumentTest walks a stranger against a real paper.
			// The download has no OCS twin (docs/api.md#downloads), so this is the internal door.
			'document#download' => (new DocumentController(Application::APP_ID, $this->request($params), $this->papers, $this->session(self::STRANGER)))
				->download($uuid, self::NO_SUCH_ENTRY),
			// The access check runs before the file is looked up, as a paper's does.
			'import#preview' => $this->importRoute(self::STRANGER, $params + self::IMPORT_BODY)->preview($uuid),
			'import#import' => $this->importRoute(self::STRANGER, $params + self::IMPORT_BODY)->import($uuid),
			// Walked against an entry that is not there, for the reason the trip's are.
			'import#undo' => $this->importRoute(self::STRANGER, $params + self::UNDO_BODY)->undo($uuid),
			'booking#index' => $this->booking(self::STRANGER, $params)->index($uuid),
			'booking#create' => $this->booking(self::STRANGER, self::tomorrow() + $params)->create($uuid),
			// Walked against a booking that is not there, for the reason the trip's are.
			'booking#update' => $this->booking(self::STRANGER, self::tomorrow() + $params)->update($uuid, self::NO_SUCH_ENTRY),
			'booking#delete' => $this->booking(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'booking#check_out' => $this->booking(self::STRANGER, $params + ['odo' => 999999, 'at_off' => 120])->checkOut($uuid, self::NO_SUCH_ENTRY),
			'booking#check_in' => $this->booking(self::STRANGER, $params + ['odo' => 999999, 'at_off' => 120])->checkIn($uuid, self::NO_SUCH_ENTRY),
			'timeline#index' => $this->timeline(self::STRANGER, $params)->index($uuid),
			'timeline#gaps' => $this->timeline(self::STRANGER, $params)->gaps($uuid),
			'timeline#show' => $this->timeline(self::STRANGER, $params)->show($uuid, 'trip', self::NO_SUCH_ENTRY),
			'kpi#index' => $this->kpis(self::STRANGER, $params + ['from' => 1749900000, 'to' => 1750200000])->index($uuid),
			'kpi#year' => $this->kpis(self::STRANGER, $params + ['tz' => 'Europe/Berlin'])->year($uuid, '2025'),
			'report#logbook' => $this->report(self::STRANGER, $params)->logbook($uuid, '2026'),
			'report#mileage' => $this->report(self::STRANGER, $params)->mileage($uuid, '2026'),
			'report#csv' => $this->report(self::STRANGER, $params)->csv($uuid, '2026', 'trips'),
			'preferences#index' => $this->preferences(self::STRANGER, $params)->index(),
			'preferences#update' => $this->preferences(self::STRANGER, $params)->update(),
			// The stranger is no account and has no inbox; InboxTest walks real ones.
			'inbox#index' => $this->inboxRoute(self::STRANGER, $params)->index(),
			// The client's alone, so only through OCS (docs/api.md#sync).
			'sync#index' => (new Ocs\SyncController(Application::APP_ID, $this->request($params), $this->syncing, $this->session(self::STRANGER)))->index(),
			default => $this->fail($route . ' is a route the IDOR sweep has never been through'),
		});

		// A route that names a vehicle refuses. The ones that name none still answer - a stranger
		// has their own fleet and their own settings - so what they must not do is hand this
		// vehicle over anyway.
		if (in_array($route, self::NAMES_NO_VEHICLE, true)) {
			$this->assertContains($response->getStatus(), [Http::STATUS_OK, Http::STATUS_CREATED]);
		} else {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}
		$this->assertNotContains($uuid, $this->uuidsIn($response));

		$untouched = $this->service->find(self::OWNER, $uuid);
		$this->assertSame(self::PLATE, $untouched->getPlate());
		$this->assertNull($untouched->getDeletedAt());
		// A refused write must not have moved the odometer either, cache included.
		$this->assertNull($untouched->getOdoValue());
		$this->assertSame([], $this->reminders->list(self::OWNER, $uuid));
		$this->assertSame([self::OWNER], array_column($this->recipients->list(self::OWNER, $uuid), 'user_id'));
		$this->assertSame([], $this->papers->list(self::OWNER, $uuid));
		$this->assertSame([], $this->access->list(self::OWNER, $uuid));
		$this->assertSame([], $this->pool->list(self::OWNER, $uuid, []));
	}

	/**
	 * Every route but the page itself, by its `controller#method` name, and every OCS route as the
	 * internal twin it answers for, walked through the OCS door.
	 *
	 * @return iterable<string, array{string, bool}>
	 */
	public static function fleetRoutes(): iterable {
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		foreach ($routes['routes'] as $route) {
			if (!str_starts_with($route['name'], 'page#')) {
				yield $route['name'] => [$route['name'], false];
			}
		}
		foreach ($routes['ocs'] as $route) {
			yield $route['name'] => [self::twinOf($route['name']), true];
		}
	}

	/** `Ocs\Vehicle#index` answers for `vehicle#index`. */
	private static function twinOf(string $ocsName): string {
		[$controller, $method] = explode('#', $ocsName);

		return lcfirst(substr($controller, strlen('Ocs\\'))) . '#' . $method;
	}

	/**
	 * Each case as given, and again through the OCS door wherever its route, the case's first
	 * value, has a twin there. The OCS arms are these cases, not a copy of them, so a twin is held
	 * to its internal route's answer by role.
	 *
	 * @param iterable<string, list<mixed>> $cases
	 * @return iterable<string, list<mixed>>
	 */
	private static function throughBothDoors(iterable $cases): iterable {
		$twinned = array_map(self::twinOf(...), array_column((require __DIR__ . '/../../appinfo/routes.php')['ocs'], 'name'));
		foreach ($cases as $label => $case) {
			yield $label => [...$case, false];
			if (in_array($case[0], $twinned, true)) {
				yield "$label, through OCS" => [...$case, true];
			}
		}
	}

	/**
	 * The other half of the same rule: a grant is what widens the overview, and it widens it by
	 * exactly the role it carries (docs/adr/0001-own-access-table.md).
	 */
	public function testAGrantReachesWhatItsRoleCoversAndNoMore(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grant(self::CODRIVER, 'viewer');

		$codriver = $this->controller(self::CODRIVER, [
			'uuid' => $uuid,
			'updated_at' => $this->vehicle->getUpdatedAt(),
			'plate' => 'HH-ZZ 9',
		]);

		$this->assertContains($uuid, $this->uuidsIn($codriver->index()));
		$this->assertSame(Http::STATUS_OK, $codriver->show($uuid)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $codriver->update($uuid)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $codriver->delete($uuid)->getStatus());
		// Undo takes the right the delete took: a viewer who cannot delete cannot un-delete.
		$this->assertSame(Http::STATUS_FORBIDDEN, $codriver->restore($uuid)->getStatus());
	}

	/**
	 * Granting takes only the roles we have, so a later migration that drops one is how another
	 * word arrives, and the column takes any. A role that covers nothing must widen nothing either:
	 * the overview carries the plate, the VIN and the price of every row in it, so listing a
	 * vehicle `show` then refuses would hand over most of it anyway.
	 */
	public function testARoleTheDomainDoesNotHaveWidensNothing(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grant(self::STRANGER, 'admin');

		$stranger = $this->controller(self::STRANGER, []);

		$this->assertNotContains($uuid, $this->uuidsIn($stranger->index()));
		$this->assertSame(Http::STATUS_FORBIDDEN, $stranger->show($uuid)->getStatus());
	}

	/**
	 * The role matrix: each route walked by the owner, a manager, a driver, a viewer and a
	 * stranger, all at once on one vehicle, so a route is judged by the role and not by being
	 * the only grant there.
	 *
	 * @param array<string, mixed> $body what an update sends
	 * @dataProvider roleMatrix
	 */
	public function testEachRoleReachesWhatItCoversThroughEveryRoute(string $route, string $role, int $status, bool $deletedAfter, array $body, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$who = self::HOLDERS[$role];
		$token = $this->vehicle->getUpdatedAt();
		$grants = $this->access->list(self::OWNER, $uuid);
		// The viewer's grant, which a write under test changes or revokes.
		$viewers = array_column($grants, 'uuid', 'grantee')[self::VIEWER];
		if ($route === 'vehicle#restore') {
			// Against a deleted vehicle, so the owner's arm is the undo that works.
			$token = $this->service->delete(self::OWNER, $uuid, (int)$token)->getUpdatedAt();
		}
		if (($body['logbook_mode'] ?? null) === false) {
			// Under the mode, so switching it off is a change and not a save of what is there.
			$token = $this->service->update(self::OWNER, $uuid, (int)$token, ['logbook_mode' => true])->getUpdatedAt();
		}
		$rows = \OCP\Server::get(VehicleMapper::class);
		$was = $rows->findAnyByUuid($uuid)->jsonSerialize();

		$response = $this->through(fn (): Response => match ($route) {
			'vehicle#update' => $this->controller($who, $body + ['updated_at' => $token])->update($uuid),
			'vehicle#delete' => $this->controller($who, ['updated_at' => $token])->delete($uuid),
			'vehicle#restore' => $this->controller($who, ['updated_at' => $token])->restore($uuid),
			'grant#index' => $this->grantRoute($who, [])->index($uuid),
			'grant#create' => $this->grantRoute($who, ['grantee' => self::ACCOUNT, 'grantee_type' => 'user', 'role' => 'manager'])->create($uuid),
			// A manager must not promote anybody, themselves included.
			'grant#update' => $this->grantRoute($who, ['role' => 'manager'])->update($uuid, $viewers),
			'grant#delete' => $this->grantRoute($who, [])->delete($uuid, $viewers),
			'grant#held' => $this->grantRoute($who, [])->held($uuid),
			'grant#leave' => $this->grantRoute($who, [])->leave($uuid),
			default => $this->fail($route . ' has no arm in the role matrix'),
		});

		$this->assertSame($status, $response->getStatus());
		$after = $rows->findAnyByUuid($uuid);
		$this->assertSame($deletedAfter, $after->getDeletedAt() !== null);
		if ($status !== Http::STATUS_OK && !$deletedAfter) {
			$this->assertSame($grants, $this->access->list(self::OWNER, $uuid));
		}
		if ($status === Http::STATUS_FORBIDDEN) {
			$this->assertSame($was, $after->jsonSerialize(), 'a refused write changed the vehicle');
		}
	}

	/** @return iterable<string, list<mixed>> */
	public static function roleMatrix(): iterable {
		return self::throughBothDoors(self::roleCases());
	}

	/**
	 * @return iterable<string, array{string, string, int, bool, array<string, mixed>}>
	 */
	private static function roleCases(): iterable {
		foreach (array_keys(self::HOLDERS) as $role) {
			$owner = $role === 'owner';
			// Editing the car is `edit`. Switching the Logbook Mode off is an edit too, and the one
			// a driver would most want: it ends the period their trips are kept under.
			$edits = $owner || $role === 'manager';
			yield "vehicle#update by the $role" => ['vehicle#update', $role, $edits ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, false, ['plate' => 'HH-ZZ 9']];
			yield "vehicle#update switching the Logbook Mode off by the $role" => ['vehicle#update', $role, $edits ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, false, ['logbook_mode' => false]];
			// The car is the owner's: deleting and restoring it is theirs alone, a manager included.
			yield "vehicle#delete by the $role" => ['vehicle#delete', $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, $owner, []];
			yield "vehicle#restore by the $role" => ['vehicle#restore', $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, !$owner, []];
			// Access is the owner's alone, reading who holds it included.
			foreach (['grant#index', 'grant#create', 'grant#update', 'grant#delete'] as $route) {
				yield "$route by the $role" => [$route, $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, false, []];
			}
			// What you hold yourself is yours to read and to leave; the owner holds no grant.
			$grantee = $role !== 'owner' && $role !== 'stranger';
			yield "grant#held by the $role" => ['grant#held', $role, $role === 'stranger' ? Http::STATUS_FORBIDDEN : Http::STATUS_OK, false, []];
			yield "grant#leave by the $role" => ['grant#leave', $role, $grantee ? Http::STATUS_OK : ($owner ? Http::STATUS_NOT_FOUND : Http::STATUS_FORBIDDEN), false, []];
		}
	}

	/**
	 * The Entry routes by role and by who entered the Entry: adding takes `log`, changing
	 * somebody else's takes `edit` or `delete`, and one you entered takes `log` alone. The Entry is
	 * real, so a refusal is the gate's and not a lookup that missed.
	 *
	 * @dataProvider entryMatrix
	 */
	public function testEachRoleChangesTheEntriesItMay(string $route, string $role, string $author, int $status, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$who = self::HOLDERS[$role];
		[$kind, $action] = explode('#', $route);
		[$entry, $token] = $action === 'create' || $action === 'prefill' || $action === 'reconcile'
			? [self::NO_SUCH_ENTRY, 0]
			: $this->entered($kind, self::HOLDERS[$author], $action === 'restore');
		$params = self::ENTRY_BODIES[$kind] + ['updated_at' => $token];

		$response = $this->through(fn (): Response => match ($route) {
			'odometer#create' => $this->odometer($who, $params)->create($uuid),
			'odometer#update' => $this->odometer($who, $params)->update($uuid, $entry),
			'odometer#delete' => $this->odometer($who, $params)->delete($uuid, $entry),
			'odometer#restore' => $this->odometer($who, $params)->restore($uuid, $entry),
			'trip#create' => $this->trip($who, $params)->create($uuid),
			'trip#prefill' => $this->trip($who, $params)->prefill($uuid),
			'trip#update' => $this->trip($who, $params)->update($uuid, $entry),
			'trip#delete' => $this->trip($who, $params)->delete($uuid, $entry),
			'trip#restore' => $this->trip($who, $params)->restore($uuid, $entry),
			// No such Gap, so whoever passes the gate gets the 412 of a Gap that moved on.
			'trip#reconcile' => $this->trip($who, $params + ['distance' => 200, 'from_at' => 1749990000, 'to_at' => 1750000000])
				->reconcile($uuid, $entry),
			'energy#create' => $this->energy($who, $params)->create($uuid),
			'energy#prefill' => $this->energy($who, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			'energy#update' => $this->energy($who, $params)->update($uuid, $entry),
			'energy#delete' => $this->energy($who, $params)->delete($uuid, $entry),
			'energy#restore' => $this->energy($who, $params)->restore($uuid, $entry),
			'maintenance#create' => $this->maintenance($who, $params)->create($uuid),
			'maintenance#prefill' => $this->maintenance($who, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			'maintenance#update' => $this->maintenance($who, $params)->update($uuid, $entry),
			'maintenance#delete' => $this->maintenance($who, $params)->delete($uuid, $entry),
			'maintenance#restore' => $this->maintenance($who, $params)->restore($uuid, $entry),
			'expense#create' => $this->expense($who, $params)->create($uuid),
			'expense#prefill' => $this->expense($who, $params + ['at' => 1750000000, 'off' => 120])->prefill($uuid),
			'expense#update' => $this->expense($who, $params)->update($uuid, $entry),
			'expense#delete' => $this->expense($who, $params)->delete($uuid, $entry),
			'expense#restore' => $this->expense($who, $params)->restore($uuid, $entry),
			default => $this->fail($route . ' has no arm in the Entry matrix'),
		});

		$this->assertSame($status, $response->getStatus());
	}

	/** @return iterable<string, list<mixed>> */
	public static function entryMatrix(): iterable {
		return self::throughBothDoors(self::entryCases());
	}

	/**
	 * @return iterable<string, array{string, string, string, int}>
	 */
	private static function entryCases(): iterable {
		$writers = ['owner', 'manager', 'driver'];
		foreach (array_keys(self::ENTRY_BODIES) as $kind) {
			$adds = ["$kind#create" => Http::STATUS_CREATED];
			if ($kind !== 'odometer') {
				$adds["$kind#prefill"] = Http::STATUS_OK;
			}
			if ($kind === 'trip') {
				$adds['trip#reconcile'] = Http::STATUS_PRECONDITION_FAILED;
			}
			foreach ($adds as $route => $allowed) {
				foreach (array_keys(self::HOLDERS) as $role) {
					yield "$route by the $role" => [$route, $role, 'owner', in_array($role, $writers, true) ? $allowed : Http::STATUS_FORBIDDEN];
				}
			}

			foreach (['update', 'delete', 'restore'] as $action) {
				foreach (['owner', 'driver'] as $author) {
					foreach (array_keys(self::HOLDERS) as $role) {
						$may = $role === 'owner' || $role === 'manager' || $role === $author;
						yield "$kind#$action by the $role on the {$author}'s" => ["$kind#$action", $role, $author, $may ? Http::STATUS_OK : Http::STATUS_FORBIDDEN];
					}
				}
			}
		}
	}

	/**
	 * Answering "the counter was replaced" changes the Reading, so it takes what changing the
	 * Entry that wrote it takes: `edit`, or `log` on one you entered. The Reading is really in
	 * question, so a refusal is the gate's.
	 *
	 * @dataProvider resetMatrix
	 */
	public function testEachRoleAnswersTheQuestionsItMay(string $route, string $role, string $author, int $status, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$this->odometry->record(self::OWNER, $uuid, self::ENTRY_BODIES['odometer']);
		$lower = $this->odometry->record(self::HOLDERS[$author], $uuid, ['value' => 30] + self::ENTRY_BODIES['odometer']);

		$response = $this->through(fn (): Response => $this->odometer(self::HOLDERS[$role], ['updated_at' => $lower->getUpdatedAt()])
			->reset($uuid, $lower->getUuid()));

		$this->assertSame($status, $response->getStatus());
		$after = \OCP\Server::get(OdoReadingMapper::class)->findAnyByUuid($lower->getUuid());
		$this->assertSame($status === Http::STATUS_OK ? OdoReading::RESET : OdoReading::READING, $after->getKind());
	}

	/** @return iterable<string, list<mixed>> */
	public static function resetMatrix(): iterable {
		$cases = [];
		foreach (['owner', 'driver'] as $author) {
			foreach (array_keys(self::HOLDERS) as $role) {
				$may = $role === 'owner' || $role === 'manager' || $role === $author;
				$cases["odometer#reset by the $role on the {$author}'s"] = ['odometer#reset', $role, $author, $may ? Http::STATUS_OK : Http::STATUS_FORBIDDEN];
			}
		}

		return self::throughBothDoors($cases);
	}

	/**
	 * One Entry of the kind as `$author` entered it, and voided by the owner when the route under
	 * test is the undo.
	 *
	 * @return array{string, int} its uuid and the token the route is checked against
	 */
	private function entered(string $kind, string $author, bool $voided): array {
		$uuid = $this->vehicle->getUuid();
		$body = self::ENTRY_BODIES[$kind];
		$row = match ($kind) {
			'odometer' => $this->odometry->record($author, $uuid, $body)->jsonSerialize(),
			'trip' => $this->journeys->record($author, $uuid, $body)->jsonSerialize(),
			'energy' => $this->fillUps->record($author, $uuid, $body),
			'maintenance' => $this->workshop->record($author, $uuid, $body),
			'expense' => $this->spending->record($author, $uuid, $body),
			default => $this->fail($kind . ' is no Entry'),
		};
		if ($voided) {
			[$entry, $token] = [(string)$row['uuid'], (int)$row['updated_at']];
			$row = match ($kind) {
				'odometer' => $this->odometry->delete(self::OWNER, $uuid, $entry, $token)->jsonSerialize(),
				'trip' => $this->journeys->delete(self::OWNER, $uuid, $entry, $token)->jsonSerialize(),
				'energy' => $this->fillUps->delete(self::OWNER, $uuid, $entry, $token),
				'maintenance' => $this->workshop->delete(self::OWNER, $uuid, $entry, $token),
				'expense' => $this->spending->delete(self::OWNER, $uuid, $entry, $token),
				default => $this->fail($kind . ' is no Entry'),
			};
		}

		return [(string)$row['uuid'], (int)$row['updated_at']];
	}

	/**
	 * The booking routes by role and by whose booking it is: seeing takes `view`, booking `log`,
	 * changing, cancelling or handing over your own `log` and anybody's `edit` (CONTEXT.md, Pool).
	 * The booking is real, so a refusal is the gate's and not a lookup that missed.
	 *
	 * @dataProvider bookingMatrix
	 */
	public function testEachRoleActsOnTheBookingsItMay(string $route, string $role, string $booker, int $status, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$who = self::HOLDERS[$role];
		$booking = $this->pool->book(self::HOLDERS[$booker], $uuid, self::tomorrow());
		$handover = ['odo' => 52000, 'at_off' => 120];
		if ($route === 'booking#check_in' || $route === 'trip#create') {
			$booking = $this->pool->checkOut(self::HOLDERS[$booker], $uuid, $booking['uuid'], $handover);
		}
		if ($route === 'trip#create') {
			$booking = $this->pool->checkIn(self::HOLDERS[$booker], $uuid, $booking['uuid'], $handover);
		}
		$params = ['updated_at' => $booking['updated_at']];
		// After the one booked above, so a create cannot be the 409 of a span taken.
		$later = self::tomorrow(2);

		$response = $this->through(fn (): Response => match ($route) {
			'booking#index' => $this->booking($who, [])->index($uuid),
			'booking#create' => $this->booking($who, $later)->create($uuid),
			'booking#update' => $this->booking($who, $later + $params)->update($uuid, $booking['uuid']),
			'booking#delete' => $this->booking($who, $params)->delete($uuid, $booking['uuid']),
			'booking#check_out' => $this->booking($who, $handover)->checkOut($uuid, $booking['uuid']),
			'booking#check_in' => $this->booking($who, $handover)->checkIn($uuid, $booking['uuid']),
			// The trip logged from the booking, which takes the booking's rule on top of `log`.
			'trip#create' => $this->trip($who, self::ENTRY_BODIES['trip'] + ['booking_uuid' => $booking['uuid']])->create($uuid),
			default => $this->fail($route . ' has no arm in the booking matrix'),
		});

		$this->assertSame($status, $response->getStatus());
		if ($status === Http::STATUS_FORBIDDEN) {
			$this->assertSame([$booking], $this->pool->list(self::HOLDERS[$booker], $uuid, []));
		}
	}

	/** @return iterable<string, list<mixed>> */
	public static function bookingMatrix(): iterable {
		return self::throughBothDoors(self::bookingCases());
	}

	/**
	 * @return iterable<string, array{string, string, string, int}>
	 */
	private static function bookingCases(): iterable {
		foreach (array_keys(self::HOLDERS) as $role) {
			yield "booking#index by the $role" => ['booking#index', $role, 'owner', $role === 'stranger' ? Http::STATUS_FORBIDDEN : Http::STATUS_OK];
			$logs = in_array($role, ['owner', 'manager', 'driver'], true);
			yield "booking#create by the $role" => ['booking#create', $role, 'owner', $logs ? Http::STATUS_CREATED : Http::STATUS_FORBIDDEN];
			foreach (['booking#update', 'booking#delete', 'booking#check_out', 'booking#check_in'] as $route) {
				foreach (['owner', 'driver'] as $booker) {
					$may = $role === 'owner' || $role === 'manager' || $role === $booker;
					yield "$route by the $role on the {$booker}'s" => [$route, $role, $booker, $may ? Http::STATUS_OK : Http::STATUS_FORBIDDEN];
				}
			}
			foreach (['owner', 'driver'] as $booker) {
				$may = $role === 'owner' || $role === 'manager' || $role === $booker;
				yield "trip#create from the {$booker}'s booking by the $role" => ['trip#create', $role, $booker, $may ? Http::STATUS_CREATED : Http::STATUS_FORBIDDEN];
			}
		}
	}

	/**
	 * The paper routes by role and by the row the paper hangs on: the vehicle's own papers take
	 * `edit`, a paper on an entry or a booking that row's rule (DocumentService::keeps()). Nobody
	 * here has Files, so an attach the rule lets through fails at the file, where a real account
	 * would get a 404: the refusal comes first. The detached paper is a real row.
	 *
	 * @dataProvider documentMatrix
	 */
	public function testEachRoleKeepsThePapersItMay(string $route, string $role, string $on, int $status, bool $ocs): void {
		$this->ocs = $ocs;
		$uuid = $this->vehicle->getUuid();
		$vehicleId = (int)$this->vehicle->getId();
		$this->grantEveryRole();
		$who = self::HOLDERS[$role];
		[$type, $author] = $on === 'vehicle' ? [null, null] : explode(' of the ', $on);
		$linked = match ($type) {
			null => null,
			'booking' => $this->pool->book(self::HOLDERS[$author], $uuid, self::tomorrow())['uuid'],
			default => $this->entered($type, self::HOLDERS[$author], false)[0],
		};
		$link = $type === null ? [] : ['linked_type' => $type, 'linked_uuid' => $linked];
		$paper = new Document();
		$paper->setVehicleId($vehicleId);
		$paper->setFileId(1);
		$paper->setKind('receipt');
		$paper->setLinkedType($type);
		$rows = $type === 'booking' ? \OCP\Server::get(BookingMapper::class) : \OCP\Server::get(EnergyMapper::class);
		$paper->setLinkedId($type === null ? null : (int)$rows->findOnVehicle($vehicleId, (string)$linked)->getId());
		$paper->setCreatedBy(self::OWNER);
		// A restore needs a detached paper to bring back.
		$paper->setDeletedAt($route === 'document#restore' ? time() : null);
		$paper = \OCP\Server::get(DocumentMapper::class)->insert($paper);

		try {
			$response = $this->through(fn (): Response => match ($route) {
				'document#create' => $this->document($who, ['file_id' => 1, 'kind' => 'receipt'] + $link)->create($uuid),
				'document#delete' => $this->document($who, [])->delete($uuid, $paper->getUuid()),
				'document#restore' => $this->document($who, [])->restore($uuid, $paper->getUuid()),
				default => $this->fail($route . ' has no arm in the paper matrix'),
			});
		} catch (\Exception $e) {
			// A private class, so by name: the server has no Files for a holder who is no account.
			$this->assertSame([Http::STATUS_NOT_FOUND, 'OC\User\NoUserException'], [$status, $e::class]);
			return;
		}

		$this->assertSame($status, $response->getStatus());
		// A refused write leaves the paper as it was: live for a detach, detached for a restore.
		$live = $route === 'document#restore' ? $status === Http::STATUS_OK : !($route === 'document#delete' && $status === Http::STATUS_OK);
		$this->assertCount($live ? 1 : 0, $this->papers->list(self::OWNER, $uuid));
	}

	/**
	 * Importing is `edit`: it writes many entries at once, other people's history among them, so a
	 * driver's `log` does not cover it. Nobody here has Files, so whoever passes the gate fails at
	 * the file, as an attach does in the paper matrix; ImportTest walks real accounts.
	 *
	 * @dataProvider importMatrix
	 */
	public function testOnlyAnEditorMayImport(string $route, string $role, int $status, bool $ocs): void {
		$this->ocs = $ocs;
		$this->grantEveryRole();

		try {
			$response = $this->through(fn (): Response => match ($route) {
				'import#preview' => $this->importRoute(self::HOLDERS[$role], self::IMPORT_BODY)->preview($this->vehicle->getUuid()),
				'import#import' => $this->importRoute(self::HOLDERS[$role], self::IMPORT_BODY)->import($this->vehicle->getUuid()),
				'import#undo' => $this->importRoute(self::HOLDERS[$role], self::UNDO_BODY)->undo($this->vehicle->getUuid()),
				default => $this->fail($route . ' has no arm in the import matrix'),
			});
		} catch (\Exception $e) {
			$this->assertSame([Http::STATUS_NOT_FOUND, 'OC\User\NoUserException'], [$status, $e::class]);
			return;
		}

		$this->assertSame($status, $response->getStatus());
	}

	/**
	 * An undo reads no file: whoever passes the gate meets the entry that is not there, a 409.
	 *
	 * @return iterable<string, list<mixed>>
	 */
	public static function importMatrix(): iterable {
		return self::throughBothDoors((static function (): iterable {
			foreach (['import#preview' => Http::STATUS_NOT_FOUND, 'import#import' => Http::STATUS_NOT_FOUND, 'import#undo' => Http::STATUS_CONFLICT] as $route => $passed) {
				foreach (array_keys(self::HOLDERS) as $role) {
					$edits = $role === 'owner' || $role === 'manager';
					yield "$route by the $role" => [$route, $role, $edits ? $passed : Http::STATUS_FORBIDDEN];
				}
			}
		})());
	}

	/** @return iterable<string, list<mixed>> */
	public static function documentMatrix(): iterable {
		return self::throughBothDoors(self::documentCases());
	}

	/**
	 * @return iterable<string, array{string, string, string, int}>
	 */
	private static function documentCases(): iterable {
		foreach (array_keys(self::HOLDERS) as $role) {
			$manages = $role === 'owner' || $role === 'manager';
			foreach (['vehicle', 'energy of the owner', 'energy of the driver', 'booking of the owner', 'booking of the driver'] as $on) {
				$may = $manages || str_ends_with($on, "of the $role");
				yield "document#create on the $on by the $role" => ['document#create', $role, $on, $may ? Http::STATUS_NOT_FOUND : Http::STATUS_FORBIDDEN];
				yield "document#delete on the $on by the $role" => ['document#delete', $role, $on, $may ? Http::STATUS_OK : Http::STATUS_FORBIDDEN];
				yield "document#restore on the $on by the $role" => ['document#restore', $role, $on, $may ? Http::STATUS_OK : Http::STATUS_FORBIDDEN];
			}
		}
	}

	/**
	 * A booking logged twice is a 409 naming it; one on another vehicle is not found, the way a
	 * trip's uuid on another vehicle is, so the answer tells nobody which bookings exist.
	 */
	public function testATripFromABookingIsTiedOnceAndOnlyOnItsVehicle(): void {
		$uuid = $this->vehicle->getUuid();
		$handover = ['odo' => 52000, 'at_off' => 120];
		$booking = $this->pool->book(self::OWNER, $uuid, self::tomorrow());
		$this->pool->checkOut(self::OWNER, $uuid, $booking['uuid'], $handover);
		$this->pool->checkIn(self::OWNER, $uuid, $booking['uuid'], $handover);
		$body = self::ENTRY_BODIES['trip'] + ['booking_uuid' => $booking['uuid']];
		$this->assertSame(Http::STATUS_CREATED, $this->trip(self::OWNER, $body)->create($uuid)->getStatus());

		$again = $this->trip(self::OWNER, $body)->create($uuid);

		$this->assertSame(Http::STATUS_CONFLICT, $again->getStatus());
		$this->assertSame($booking['uuid'], $again->getData()['booking']['uuid']);
		$other = $this->service->create(self::OWNER, ['plate' => 'HH-ZZ 10']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->trip(self::OWNER, $body)->create($other->getUuid())->getStatus());
	}

	/**
	 * A booking over another is a 409 that names who has the car and when, so the sheet can say so
	 * without a second read.
	 */
	public function testABookingOverAnotherIsAConflictNamingIt(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$held = $this->pool->book(self::CODRIVER, $uuid, self::tomorrow());

		$response = $this->booking(self::OWNER, self::tomorrow())->create($uuid);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame($held['uuid'], $response->getData()['booking']['uuid']);
		$this->assertSame(self::CODRIVER, $response->getData()['booking']['user_name']);
		$this->assertSame('booked', $response->getData()['booking']['state']);
	}

	/** Taking a car that is still out is a 409 naming who has it: "still with …". */
	public function testACheckOutWhileTheCarIsOutIsAConflictNamingWhoHasIt(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$handover = ['odo' => 52000, 'at_off' => 120];
		$theirs = $this->pool->book(self::CODRIVER, $uuid, self::tomorrow());
		$this->pool->checkOut(self::CODRIVER, $uuid, $theirs['uuid'], $handover);
		$mine = $this->pool->book(self::OWNER, $uuid, self::tomorrow(2));

		$response = $this->booking(self::OWNER, $handover)->checkOut($uuid, $mine['uuid']);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame($theirs['uuid'], $response->getData()['booking']['uuid']);
		$this->assertSame(self::CODRIVER, $response->getData()['booking']['user_name']);
		$this->assertSame('out', $response->getData()['booking']['state']);
	}

	/**
	 * The vehicle JSON says what each role may, on the single route and the list alike, so the
	 * screen can hide by it; a stranger gets neither.
	 */
	public function testTheVehicleSaysWhatEachRoleMay(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$expected = [
			'owner' => ['view', 'log', 'edit', 'delete', 'own', 'book'],
			'manager' => ['view', 'log', 'edit', 'delete', 'book'],
			'driver' => ['view', 'log', 'book'],
			'viewer' => ['view'],
		];

		foreach ($expected as $role => $may) {
			$controller = $this->controller(self::HOLDERS[$role], []);
			$shown = $controller->show($uuid)->getData();
			$this->assertInstanceOf(Vehicle::class, $shown);
			$this->assertSame($may, $shown->jsonSerialize()['may'], "show, $role");

			$listed = array_values(array_filter(
				$controller->index()->getData(),
				static fn (Vehicle $vehicle): bool => $vehicle->getUuid() === $uuid,
			));
			$this->assertCount(1, $listed, "index, $role");
			$this->assertSame($may, $listed[0]->jsonSerialize()['may'], "index, $role");
		}

		$stranger = $this->controller(self::STRANGER, []);
		$this->assertSame(Http::STATUS_FORBIDDEN, $stranger->show($uuid)->getStatus());
		$this->assertNotContains($uuid, $this->uuidsIn($stranger->index()));
	}

	/** A laid-up car takes no booking, so nobody is offered one; the rest of `may` stands. */
	public function testALaidUpVehicleSaysNobodyBooksIt(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$laidUp = $this->service->update(self::OWNER, $uuid, $this->vehicle->getUpdatedAt(), ['lifecycle' => 'laid_up']);
		$this->assertSame(['view', 'log', 'edit', 'delete', 'own'], $laidUp->jsonSerialize()['may']);

		$controller = $this->controller(self::HOLDERS['driver'], []);
		$shown = $controller->show($uuid)->getData();
		$this->assertInstanceOf(Vehicle::class, $shown);
		$this->assertSame(['view', 'log'], $shown->jsonSerialize()['may']);
	}

	/**
	 * Each timeline row says what the reader may do to that Entry, on the page and on the single
	 * read alike, so the screen offers edit and delete only where the server would take them.
	 */
	public function testEachTimelineRowSaysWhatTheReaderMayDoToIt(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$entries = [
			'owner' => $this->entered('expense', self::OWNER, false)[0],
			'driver' => $this->entered('expense', self::CODRIVER, false)[0],
		];
		$both = ['edit', 'delete'];
		$expected = [
			'owner' => ['owner' => $both, 'driver' => $both],
			'manager' => ['owner' => $both, 'driver' => $both],
			'driver' => ['owner' => [], 'driver' => $both],
			'viewer' => ['owner' => [], 'driver' => []],
		];

		foreach ($expected as $role => $byAuthor) {
			$timeline = $this->timeline(self::HOLDERS[$role], []);
			$rows = json_decode((string)json_encode($timeline->index($uuid)->getData()['rows']), true);
			$mayOf = array_column(array_map(static fn (array $row): array => [$row['expense']['uuid'], $row['may']], $rows), 1, 0);
			foreach ($byAuthor as $author => $may) {
				$this->assertSame($may, $mayOf[$entries[$author]] ?? null, "page, $role on the {$author}'s");
				$one = json_decode((string)json_encode($timeline->show($uuid, 'expense', $entries[$author])->getData()), true);
				$this->assertSame($may, $one['may'], "show, $role on the {$author}'s");
			}
		}
	}

	private function grantEveryRole(): void {
		$this->grant(self::MANAGER, 'manager');
		$this->grant(self::CODRIVER, 'driver');
		$this->grant(self::VIEWER, 'viewer');
	}

	/** One grant on the vehicle under test, as the sharing UI will write it (M6). */
	private function grant(string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$this->vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);

		$this->grants->insert($grant);
	}
}
