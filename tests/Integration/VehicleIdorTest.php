<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\OdometerController;
use OCA\NextFleet\Controller\PreferencesController;
use OCA\NextFleet\Controller\TimelineController;
use OCA\NextFleet\Controller\TripController;
use OCA\NextFleet\Controller\VehicleController;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
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
	private const PLATE = 'B-XY 123';
	/** A trip uuid nothing wrote: the refusal must come before the lookup that would miss it. */
	private const NO_SUCH_TRIP = '0195e2f1-1111-4000-8000-00000000dead';

	/**
	 * The routes that reach no vehicle by identity, so a stranger gets an answer rather than a
	 * refusal - their own fleet, their own settings. Listed rather than inferred: a route added
	 * here is a claim that nothing in its answer belongs to anybody else.
	 */
	private const NAMES_NO_VEHICLE = ['vehicle#index', 'vehicle#create', 'preferences#index', 'preferences#update'];

	private VehicleService $service;
	private OdometerService $odometry;
	private TripService $journeys;
	private TimelineService $history;
	private PreferencesService $settings;
	private AccessMapper $grants;
	private Vehicle $vehicle;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->service = $container->get(VehicleService::class);
		$this->odometry = $container->get(OdometerService::class);
		$this->journeys = $container->get(TripService::class);
		$this->history = $container->get(TimelineService::class);
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
		$people = [self::OWNER, self::STRANGER, self::CODRIVER];

		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();

		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_access')
			->where($qb->expr()->in('grantee', $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
		$qb->executeStatement();

		foreach (['fleet_odo_readings', 'fleet_trips'] as $table) {
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
	 * And again, for the one read that shows everything at once.
	 *
	 * @param array<string, mixed> $params
	 */
	private function timeline(string $userId, array $params): TimelineController {
		return new TimelineController(Application::APP_ID, $this->request($params), $this->history, $this->session($userId));
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
	private function uuidsIn(DataResponse $response): array {
		$data = $response->getData();
		$uuids = [];
		foreach (is_array($data) ? $data : [$data] as $item) {
			if ($item instanceof Vehicle) {
				$uuids[] = $item->getUuid();
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
			'trip#create' => $this->trip(self::STRANGER, $params)->create($uuid),
			// Walked against a trip that is not there: the gate is the vehicle's, so a stranger
			// is refused before any uuid of a trip is looked up, and a 404 here would be the
			// answer telling them so.
			'trip#delete' => $this->trip(self::STRANGER, $params)->delete($uuid, self::NO_SUCH_TRIP),
			'trip#restore' => $this->trip(self::STRANGER, $params)->restore($uuid, self::NO_SUCH_TRIP),
			'timeline#index' => $this->timeline(self::STRANGER, $params)->index($uuid),
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
