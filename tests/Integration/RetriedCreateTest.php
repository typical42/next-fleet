<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller;
use OCA\NextFleet\Controller\Ocs;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A create sent twice under one client uuid writes one row, through either door: the first is
 * answered 201, the retry 200 with the same body (docs/api.md#retried-creates). Papers and grants
 * need real accounts and files, so DocumentTest and GrantTest hold theirs.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class RetriedCreateTest extends TestCase {
	/** Not Nextcloud accounts: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';
	private const TABLES = ['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_trips' => 'created_by', 'fleet_audit' => 'created_by',
		'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_expenses' => 'created_by', 'fleet_reminders' => 'created_by',
		'fleet_reminder_recipients' => 'created_by', 'fleet_bookings' => 'created_by'];

	private string $vehicle;

	protected function setUp(): void {
		$this->forgetTestRows();
		$this->vehicle = $this->service(VehicleService::class)->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (self::TABLES as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter([self::OWNER, self::STRANGER], IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function service(string $class): object {
		return \OCP\Server::get($class);
	}

	/**
	 * A controller of either door as its route reaches it.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param class-string $service
	 * @param array<string, mixed> $params
	 * @return T
	 */
	private function door(string $class, string $service, array $params, string $userId = self::OWNER): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new $class(Application::APP_ID, $request, $this->service($service), $session);
	}

	/** One client uuid per door and test, so the two doors' rows never meet. */
	private static function uuid(int $door): string {
		return sprintf('0195e2f1-%04x-4000-8000-%012x', $door, random_int(0, 0xffffffffffff));
	}

	/** @return array<array-key, mixed> the body as a client decodes it */
	private static function body(DataResponse $response): array {
		return json_decode((string)json_encode($response->getData()), true);
	}

	/**
	 * Sends the create, then again with `$retry` merged over the same request: what the retry says
	 * differently is not written either.
	 *
	 * @param list<class-string> $doors the internal controller and its OCS twin
	 * @param class-string $service
	 * @param array<string, mixed> $fields
	 * @param \Closure(object): DataResponse $create
	 * @param array<string, mixed> $retry
	 * @return list<array{string, array<array-key, mixed>}> per door, the uuid and the first body
	 */
	private function twice(array $doors, string $service, array $fields, \Closure $create, array $retry = [], int $first = Http::STATUS_CREATED): array {
		$sent = [];
		foreach ($doors as $i => $class) {
			$uuid = self::uuid($i);
			$created = $create($this->door($class, $service, ['client_uuid' => $uuid] + $fields));
			$again = $create($this->door($class, $service, ['client_uuid' => $uuid] + $retry + $fields));

			$this->assertSame($first, $created->getStatus(), $class);
			$this->assertSame(Http::STATUS_OK, $again->getStatus(), $class);
			$this->assertSame(self::body($created), self::body($again), $class);
			$sent[] = [$uuid, self::body($created)];
		}

		return $sent;
	}

	private function rows(string $table, string $uuid): int {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function testAnExpenseSentAgainIsOneExpense(): void {
		$sent = $this->twice(
			[Controller\ExpenseController::class, Ocs\ExpenseController::class],
			ExpenseService::class,
			['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000],
			fn (object $door): DataResponse => $door->create($this->vehicle),
			['amount' => 65000],
		);

		foreach ($sent as [$uuid, $body]) {
			$this->assertSame($uuid, $body['uuid']);
			$this->assertSame(64000, $body['amount']);
			$this->assertSame(1, $this->rows('fleet_expenses', $uuid));
		}
	}

	public function testAFillUpSentAgainIsOneFillUpWithOneReading(): void {
		$sent = $this->twice(
			[Controller\EnergyController::class, Ocs\EnergyController::class],
			EnergyService::class,
			['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'odo' => 1000],
			fn (object $door): DataResponse => $door->create($this->vehicle),
		);

		foreach ($sent as [$uuid]) {
			$this->assertSame(1, $this->rows('fleet_energy', $uuid));
		}
		// One Reading per fill-up, not one per send.
		$this->assertCount(2, $this->service(OdometerService::class)->list(self::OWNER, $this->vehicle));
	}

	public function testAMaintenanceRecordSentAgainIsOneRecord(): void {
		$sent = $this->twice(
			[Controller\MaintenanceController::class, Ocs\MaintenanceController::class],
			MaintenanceService::class,
			['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change'],
			fn (object $door): DataResponse => $door->create($this->vehicle),
		);

		foreach ($sent as [$uuid, $body]) {
			$this->assertArrayHasKey('closes', $body);
			$this->assertSame(1, $this->rows('fleet_maintenance', $uuid));
		}
	}

	public function testAReadingSentAgainIsOneReading(): void {
		$sent = $this->twice(
			[Controller\OdometerController::class, Ocs\OdometerController::class],
			OdometerService::class,
			['read_at' => 1750000000, 'read_at_off' => 120, 'value' => 1000],
			fn (object $door): DataResponse => $door->create($this->vehicle),
			['value' => 1100],
		);

		foreach ($sent as [$uuid, $body]) {
			$this->assertSame(1000, $body['value']);
			$this->assertSame(1, $this->rows('fleet_odo_readings', $uuid));
		}
	}

	/** The retry would overlap the first trip; it is the same trip instead. */
	public function testATripSentAgainIsOneTrip(): void {
		$sent = $this->twice(
			[Controller\TripController::class, Ocs\TripController::class],
			TripService::class,
			['started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120, 'start_odo' => 900, 'end_odo' => 1000, 'category' => 'private'],
			fn (object $door): DataResponse => $door->create($this->vehicle),
			['end_odo' => 1010],
		);

		foreach ($sent as [$uuid, $body]) {
			$this->assertSame(1000, $body['end_odo']);
			$this->assertSame(1, $this->rows('fleet_trips', $uuid));
		}
	}

	public function testAReminderSentAgainIsOneReminder(): void {
		$sent = $this->twice(
			[Controller\ReminderController::class, Ocs\ReminderController::class],
			ReminderService::class,
			['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 135000],
			fn (object $door): DataResponse => $door->create($this->vehicle),
		);

		foreach ($sent as [$uuid]) {
			$this->assertSame(1, $this->rows('fleet_reminders', $uuid));
		}
	}

	/** The retry's span is the first one's own, which would be a 409 against itself. */
	public function testABookingSentAgainIsOneBooking(): void {
		// A day apart, so the two doors' bookings do not meet.
		foreach ([Controller\BookingController::class, Ocs\BookingController::class] as $i => $door) {
			$start = time() + 86400 * ($i + 1);
			[[$uuid]] = $this->twice(
				[$door],
				BookingService::class,
				['starts_at' => $start, 'starts_at_off' => 120, 'ends_at' => $start + 3600, 'ends_at_off' => 120],
				fn (object $door): DataResponse => $door->create($this->vehicle),
			);

			$this->assertSame(1, $this->rows('fleet_bookings', $uuid));
		}
	}

	public function testAVehicleSentAgainIsOneVehicle(): void {
		$sent = $this->twice(
			[Controller\VehicleController::class, Ocs\VehicleController::class],
			VehicleService::class,
			['plate' => 'HH-AB 1'],
			static fn (object $door): DataResponse => $door->create(),
			['plate' => 'HH-AB 2'],
		);

		foreach ($sent as [$uuid, $body]) {
			$this->assertSame($uuid, $body['uuid']);
			$this->assertSame('HH-AB 1', $body['plate']);
			$this->assertContains('own', $body['may']);
			$this->assertSame(1, $this->rows('fleet_vehicles', $uuid));
		}
	}

	/** Another owner's vehicle under the uuid is no retry of this create, and is not answered. */
	public function testAVehicleUuidSomebodyElseHoldsIsRefused(): void {
		$uuid = self::uuid(0);
		$this->door(Ocs\VehicleController::class, VehicleService::class, ['client_uuid' => $uuid, 'plate' => 'HH-AB 1'])->create();

		$refused = $this->door(Ocs\VehicleController::class, VehicleService::class, ['client_uuid' => $uuid, 'plate' => 'M-XY 9'], self::STRANGER)->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'client_uuid is taken'], $refused->getData());
	}

	/** A uuid one vehicle's row holds is taken on every other, and nothing is written there. */
	public function testAUuidAnotherVehiclesRowHoldsIsRefused(): void {
		$other = $this->service(VehicleService::class)->create(self::OWNER, ['plate' => 'B-XY 124'])->getUuid();
		$uuid = self::uuid(0);
		$spend = ['client_uuid' => $uuid, 'spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000];
		$this->door(Controller\ExpenseController::class, ExpenseService::class, $spend)->create($this->vehicle);

		$refused = $this->door(Controller\ExpenseController::class, ExpenseService::class, $spend)->create($other);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'client_uuid is taken'], $refused->getData());
		$this->assertSame(1, $this->rows('fleet_expenses', $uuid));
	}

	/** The vehicle's rules come first: a stranger learns nothing from a uuid the owner used. */
	public function testAStrangerIsRefusedBeforeTheUuidIsLookedAt(): void {
		$uuid = self::uuid(0);
		$spend = ['client_uuid' => $uuid, 'spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000];
		$this->door(Controller\ExpenseController::class, ExpenseService::class, $spend)->create($this->vehicle);

		$refused = $this->door(Controller\ExpenseController::class, ExpenseService::class, $spend, self::STRANGER)->create($this->vehicle);

		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
	}

	public function testAClientUuidIsAUuid(): void {
		$spend = ['client_uuid' => 'retry-1', 'spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000];

		$refused = $this->door(Ocs\ExpenseController::class, ExpenseService::class, $spend)->create($this->vehicle);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'client_uuid is a uuid'], $refused->getData());
	}
}
