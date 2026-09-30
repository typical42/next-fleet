<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\DocumentController;
use OCA\NextFleet\Controller\EnergyController;
use OCA\NextFleet\Controller\ExpenseController;
use OCA\NextFleet\Controller\GrantController;
use OCA\NextFleet\Controller\KpiController;
use OCA\NextFleet\Controller\MaintenanceController;
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
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
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

	/**
	 * The routes that reach no vehicle by identity, so a stranger gets an answer rather than a
	 * refusal - their own fleet, their own settings. Listed rather than inferred: a route added
	 * here is a claim that nothing in its answer belongs to anybody else.
	 */
	private const NAMES_NO_VEHICLE = ['vehicle#index', 'vehicle#create', 'reminder#fleet', 'preferences#index', 'preferences#update'];

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
	private TimelineService $history;
	private KpiService $figures;
	private LogbookExport $logbook;
	private MileageClaimExport $claim;
	private ExportService $csv;
	private PreferencesService $settings;
	private AccessMapper $grants;
	private Vehicle $vehicle;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->service = $container->get(VehicleService::class);
		$this->odometry = $container->get(OdometerService::class);
		$this->journeys = $container->get(TripService::class);
		$this->fillUps = $container->get(EnergyService::class);
		$this->workshop = $container->get(MaintenanceService::class);
		$this->spending = $container->get(ExpenseService::class);
		$this->reminders = $container->get(ReminderService::class);
		$this->recipients = $container->get(RecipientService::class);
		$this->access = $container->get(GrantService::class);
		$this->papers = $container->get(DocumentService::class);
		$this->history = $container->get(TimelineService::class);
		$this->figures = $container->get(KpiService::class);
		$this->logbook = $container->get(LogbookExport::class);
		$this->claim = $container->get(MileageClaimExport::class);
		$this->csv = $container->get(ExportService::class);
		$this->settings = $container->get(PreferencesService::class);
		$this->grants = $container->get(AccessMapper::class);

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
		foreach (['fleet_access', 'fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_reminders', 'fleet_reminder_recipients'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('created_by', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * The controller as a route reaches it: the real service, and a session that is whoever is
	 * asking.
	 *
	 * @param array<string, mixed> $params
	 */
	private function controller(string $userId, array $params): VehicleController {
		return new VehicleController(Application::APP_ID, $this->request($params), $this->service, $this->session($userId));
	}

	/**
	 * The same, for the routes that hang off a vehicle.
	 *
	 * @param array<string, mixed> $params
	 */
	private function odometer(string $userId, array $params): OdometerController {
		return new OdometerController(Application::APP_ID, $this->request($params), $this->odometry, $this->session($userId));
	}

	/**
	 * The same again, for the trips.
	 *
	 * @param array<string, mixed> $params
	 */
	private function trip(string $userId, array $params): TripController {
		return new TripController(Application::APP_ID, $this->request($params), $this->journeys, $this->session($userId));
	}

	/**
	 * And for the fill-ups.
	 *
	 * @param array<string, mixed> $params
	 */
	private function energy(string $userId, array $params): EnergyController {
		return new EnergyController(Application::APP_ID, $this->request($params), $this->fillUps, $this->session($userId));
	}

	/**
	 * And for the Maintenance Records.
	 *
	 * @param array<string, mixed> $params
	 */
	private function maintenance(string $userId, array $params): MaintenanceController {
		return new MaintenanceController(Application::APP_ID, $this->request($params), $this->workshop, $this->session($userId));
	}

	/**
	 * And for the Expenses.
	 *
	 * @param array<string, mixed> $params
	 */
	private function expense(string $userId, array $params): ExpenseController {
		return new ExpenseController(Application::APP_ID, $this->request($params), $this->spending, $this->session($userId));
	}

	/**
	 * And for the reminders.
	 *
	 * @param array<string, mixed> $params
	 */
	private function reminder(string $userId, array $params): ReminderController {
		return new ReminderController(Application::APP_ID, $this->request($params), $this->reminders, $this->session($userId));
	}

	/**
	 * And for who the reminders go to.
	 *
	 * @param array<string, mixed> $params
	 */
	private function recipient(string $userId, array $params): RecipientController {
		return new RecipientController(Application::APP_ID, $this->request($params), $this->recipients, $this->session($userId));
	}

	/**
	 * And for who else may use the vehicle. Not `grant()`: that name writes a row.
	 *
	 * @param array<string, mixed> $params
	 */
	private function grantRoute(string $userId, array $params): GrantController {
		return new GrantController(Application::APP_ID, $this->request($params), $this->access, $this->session($userId));
	}

	/**
	 * And for the vehicle's papers.
	 *
	 * @param array<string, mixed> $params
	 */
	private function document(string $userId, array $params): DocumentController {
		return new DocumentController(Application::APP_ID, $this->request($params), $this->papers, $this->session($userId));
	}

	/**
	 * And again, for the one read that shows everything at once.
	 *
	 * @param array<string, mixed> $params
	 */
	private function timeline(string $userId, array $params): TimelineController {
		return new TimelineController(Application::APP_ID, $this->request($params), $this->history, $this->session($userId));
	}

	/**
	 * And for the header's figures.
	 *
	 * @param array<string, mixed> $params
	 */
	private function kpis(string $userId, array $params): KpiController {
		return new KpiController(Application::APP_ID, $this->request($params), $this->figures, $this->session($userId));
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
	private function preferences(string $userId, array $params): PreferencesController {
		return new PreferencesController(Application::APP_ID, $this->request($params), $this->settings, $this->session($userId));
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
		foreach (is_array($data) ? $data : [$data] as $item) {
			if ($item instanceof Vehicle) {
				$uuids[] = $item->getUuid();
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
	public function testAStrangerReachesNothingThroughAnyRoute(string $route): void {
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

		$response = match ($route) {
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
			// A real account, so the refusal cannot be the unknown user's 400.
			'recipient#create' => $this->recipient(self::STRANGER, $params + ['user_id' => 'admin'])->create($uuid),
			'recipient#delete' => $this->recipient(self::STRANGER, $params)->delete($uuid, self::OWNER),
			'grant#index' => $this->grantRoute(self::STRANGER, $params)->index($uuid),
			// A real account again, for the same reason.
			'grant#create' => $this->grantRoute(self::STRANGER, $params + ['grantee' => 'admin', 'grantee_type' => 'user', 'role' => 'manager'])->create($uuid),
			// Walked against a grant that is not there, for the reason the trip's are.
			'grant#update' => $this->grantRoute(self::STRANGER, $params + ['role' => 'manager'])->update($uuid, self::NO_SUCH_ENTRY),
			'grant#delete' => $this->grantRoute(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			'document#index' => $this->document(self::STRANGER, $params)->index($uuid),
			// The access check runs before the file is looked up, so this is the 403 whatever file 1 is.
			'document#create' => $this->document(self::STRANGER, $params + ['file_id' => 1, 'kind' => 'receipt'])->create($uuid),
			// Walked against a document that is not there, for the reason the trip's are.
			'document#delete' => $this->document(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_ENTRY),
			// The same; DocumentTest walks a stranger against a real paper.
			'document#download' => $this->document(self::STRANGER, $params)->download($uuid, self::NO_SUCH_ENTRY),
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
			default => $this->fail($route . ' is a route the IDOR sweep has never been through'),
		};

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
	}

	/**
	 * Every route but the page itself, by its `controller#method` name.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function fleetRoutes(): iterable {
		/** @var array{routes: list<array{name: string, url: string}>} $routes */
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		foreach ($routes['routes'] as $route) {
			if (!str_starts_with($route['name'], 'page#')) {
				yield $route['name'] => [$route['name']];
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
	 * Nothing writes this table yet, so a word from an import or a later migration is how one
	 * arrives, and the column takes any. A role that covers nothing must widen nothing either:
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
	 * @dataProvider roleMatrix
	 */
	public function testEachRoleReachesWhatItCoversThroughEveryRoute(string $route, string $role, int $status, bool $deletedAfter): void {
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

		$response = match ($route) {
			'vehicle#delete' => $this->controller($who, ['updated_at' => $token])->delete($uuid),
			'vehicle#restore' => $this->controller($who, ['updated_at' => $token])->restore($uuid),
			'grant#index' => $this->grantRoute($who, [])->index($uuid),
			// A real account: a grantee has to exist, and the refusal must not be that 400.
			'grant#create' => $this->grantRoute($who, ['grantee' => 'admin', 'grantee_type' => 'user', 'role' => 'manager'])->create($uuid),
			// A manager must not promote anybody, themselves included.
			'grant#update' => $this->grantRoute($who, ['role' => 'manager'])->update($uuid, $viewers),
			'grant#delete' => $this->grantRoute($who, [])->delete($uuid, $viewers),
			default => $this->fail($route . ' has no arm in the role matrix'),
		};

		$this->assertSame($status, $response->getStatus());
		$after = \OCP\Server::get(VehicleMapper::class)->findAnyByUuid($uuid);
		$this->assertSame($deletedAfter, $after->getDeletedAt() !== null);
		if ($status === Http::STATUS_FORBIDDEN && !$deletedAfter) {
			$this->assertSame($grants, $this->access->list(self::OWNER, $uuid));
		}
	}

	/**
	 * @return iterable<string, array{string, string, int, bool}>
	 */
	public static function roleMatrix(): iterable {
		// The car is the owner's: deleting and restoring it is theirs alone, a manager included.
		foreach (array_keys(self::HOLDERS) as $role) {
			$owner = $role === 'owner';
			yield "vehicle#delete by the $role" => ['vehicle#delete', $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, $owner];
			yield "vehicle#restore by the $role" => ['vehicle#restore', $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, !$owner];
			// Access is the owner's alone, reading who holds it included.
			foreach (['grant#index', 'grant#create', 'grant#update', 'grant#delete'] as $route) {
				yield "$route by the $role" => [$route, $role, $owner ? Http::STATUS_OK : Http::STATUS_FORBIDDEN, false];
			}
		}
	}

	/**
	 * The Entry routes by role and by who entered the Entry: adding takes `log`, changing
	 * somebody else's takes `edit` or `delete`, and one you entered takes `log` alone. The Entry is
	 * real, so a refusal is the gate's and not a lookup that missed.
	 *
	 * @dataProvider entryMatrix
	 */
	public function testEachRoleChangesTheEntriesItMay(string $route, string $role, string $author, int $status): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$who = self::HOLDERS[$role];
		[$kind, $action] = explode('#', $route);
		[$entry, $token] = $action === 'create' || $action === 'prefill' || $action === 'reconcile'
			? [self::NO_SUCH_ENTRY, 0]
			: $this->entered($kind, self::HOLDERS[$author], $action === 'restore');
		$params = self::ENTRY_BODIES[$kind] + ['updated_at' => $token];

		$response = match ($route) {
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
		};

		$this->assertSame($status, $response->getStatus());
	}

	/**
	 * @return iterable<string, array{string, string, string, int}>
	 */
	public static function entryMatrix(): iterable {
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
	 * The vehicle JSON says what each role may, on the single route and the list alike, so the
	 * screen can hide by it; a stranger gets neither.
	 */
	public function testTheVehicleSaysWhatEachRoleMay(): void {
		$uuid = $this->vehicle->getUuid();
		$this->grantEveryRole();
		$expected = [
			'owner' => ['view', 'log', 'edit', 'delete', 'own'],
			'manager' => ['view', 'log', 'edit', 'delete'],
			'driver' => ['view', 'log'],
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
