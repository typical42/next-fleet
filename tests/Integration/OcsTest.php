<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\Ocs;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\InboxService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MaintenanceService;
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
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The OCS door against the real database: one answer and one refusal per resource, each in its
 * wire form (docs/api.md). VehicleIdorTest walks every route through it by role.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class OcsTest extends TestCase {
	/** Not Nextcloud accounts: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';
	private const NO_SUCH_VEHICLE = '0195e2f1-0000-4000-8000-00000000dead';

	private string $uuid;

	protected function setUp(): void {
		$this->forgetTestRows();
		$this->uuid = $this->service(VehicleService::class)->create(self::OWNER, ['plate' => 'B-XY 123'])->getUuid();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = ['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_trips' => 'created_by', 'fleet_audit' => 'created_by', 'fleet_energy' => 'created_by', 'fleet_maintenance' => 'created_by', 'fleet_expenses' => 'created_by',
			'fleet_reminders' => 'created_by', 'fleet_reminder_recipients' => 'created_by', 'fleet_access' => 'created_by', 'fleet_bookings' => 'created_by', 'fleet_documents' => 'created_by'];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function service(string $class): object {
		return (new Application())->getContainer()->get($class);
	}

	/**
	 * An OCS controller as its route reaches it, over the service its internal twin uses.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param class-string $service
	 * @param array<string, mixed> $params
	 * @return T
	 */
	private function ocs(string $class, string $service, array $params = [], string $userId = self::OWNER): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new $class(Application::APP_ID, $request, $this->service($service), $session);
	}

	/** @return array<array-key, mixed> */
	private static function data(DataResponse $response): array {
		$data = $response->getData();
		self::assertIsArray($data);

		return $data;
	}

	public function testVehicles(): void {
		$created = $this->ocs(Ocs\VehicleController::class, VehicleService::class, ['plate' => 'HH-AB 1'])->create();
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$vehicle = self::data($created);
		$this->assertSame('HH-AB 1', $vehicle['plate']);
		$this->assertContains('own', $vehicle['may']);
		$this->assertContains($vehicle['uuid'], array_column(self::data($this->ocs(Ocs\VehicleController::class, VehicleService::class)->index()), 'uuid'));

		$stale = $this->ocs(Ocs\VehicleController::class, VehicleService::class, ['updated_at' => $vehicle['updated_at'] - 1, 'plate' => 'HH-AB 2'])
			->update($vehicle['uuid']);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $stale->getStatus());
		$this->assertSame(['message' => 'Changed since you read it', 'conflict' => true], $stale->getData());
	}

	public function testReadings(): void {
		$created = $this->ocs(Ocs\OdometerController::class, OdometerService::class, ['read_at' => 1750000000, 'read_at_off' => 120, 'value' => 1000])
			->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$reading = self::data($created);
		$this->assertSame(1000, $reading['value']);
		$this->assertSame([$reading['uuid']], array_column(self::data($this->ocs(Ocs\OdometerController::class, OdometerService::class)->index($this->uuid)), 'uuid'));
		// An Odometer Entry is its own Entry: it names none.
		$this->assertArrayHasKey('source_uuid', $reading);
		$this->assertNull($reading['source_uuid']);

		$untokened = $this->ocs(Ocs\OdometerController::class, OdometerService::class, ['value' => 1100])->update($this->uuid, $reading['uuid']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $untokened->getStatus());
		$this->assertSame(['message' => 'updated_at is missing, so this write cannot be checked'], $untokened->getData());
	}

	public function testTrips(): void {
		$trip = ['started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120, 'end_odo' => 1000, 'category' => 'business', 'from_label' => 'Berlin'];
		$created = $this->ocs(Ocs\TripController::class, TripService::class, $trip)->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertFalse(self::data($created)['reconciled']);
		$this->assertContains('Berlin', self::data($this->ocs(Ocs\TripController::class, TripService::class)->prefill($this->uuid))['places']);

		// No Gap opens before the first trip, so there is none to close: the 412 of a Gap that moved.
		$moved = $this->ocs(Ocs\TripController::class, TripService::class, ['distance' => 200, 'from_at' => 1749990000, 'to_at' => 1750000000])
			->reconcile($this->uuid, self::data($created)['uuid']);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $moved->getStatus());
		$this->assertTrue(self::data($moved)['conflict']);
	}

	public function testEnergy(): void {
		$fill = ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000];
		$created = $this->ocs(Ocs\EnergyController::class, EnergyService::class, $fill)->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame(42000, self::data($created)['amount']);
		$this->assertIsArray(self::data($created)['flags']);

		$this->expectException(OCSForbiddenException::class);
		$this->ocs(Ocs\EnergyController::class, EnergyService::class, $fill, self::STRANGER)->create($this->uuid);
	}

	public function testMaintenance(): void {
		$work = ['done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Oil change'];
		$created = $this->ocs(Ocs\MaintenanceController::class, MaintenanceService::class, $work)->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame('Oil change', self::data($created)['title']);
		$this->assertArrayHasKey('closes', self::data($created));

		$this->expectException(OCSNotFoundException::class);
		$this->ocs(Ocs\MaintenanceController::class, MaintenanceService::class, $work)->create(self::NO_SUCH_VEHICLE);
	}

	/** A delete and its undo, the token moving with each (docs/architecture.md#concurrency). */
	public function testExpenses(): void {
		$spend = ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000];
		$written = self::data($this->ocs(Ocs\ExpenseController::class, ExpenseService::class, $spend)->create($this->uuid));
		$deleted = self::data($this->ocs(Ocs\ExpenseController::class, ExpenseService::class, ['updated_at' => $written['updated_at']])->delete($this->uuid, $written['uuid']));
		$this->assertNotNull($deleted['deleted_at']);
		$restored = $this->ocs(Ocs\ExpenseController::class, ExpenseService::class, ['updated_at' => $deleted['updated_at']])->restore($this->uuid, $written['uuid']);
		$this->assertSame(Http::STATUS_OK, $restored->getStatus());
		$this->assertNull(self::data($restored)['deleted_at']);
		$this->assertGreaterThan($deleted['updated_at'], self::data($restored)['updated_at']);

		$refused = $this->ocs(Ocs\ExpenseController::class, ExpenseService::class, $spend + ['category' => 'fuel'])->create($this->uuid);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertIsString(self::data($refused)['message']);
	}

	public function testTheTimeline(): void {
		$written = $this->service(ExpenseService::class)->record(self::OWNER, $this->uuid, ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 64000]);
		$timeline = fn (array $params = []): Ocs\TimelineController => $this->ocs(Ocs\TimelineController::class, TimelineService::class, $params);

		// The JSON a client reads: the Entry under its kind, in its wire form.
		$page = json_decode((string)json_encode(self::data($timeline()->index($this->uuid))), true);
		$this->assertSame($written['uuid'], $page['rows'][0]['expense']['uuid']);
		$this->assertNull($page['next']);
		$this->assertSame([], self::data($timeline()->gaps($this->uuid)));
		$one = json_decode((string)json_encode(self::data($timeline()->show($this->uuid, 'expense', $written['uuid']))), true);
		$this->assertSame(['edit', 'delete'], $one['may']);

		$refused = $timeline()->index($this->uuid, 'fuel');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());

		$this->expectException(OCSForbiddenException::class);
		$this->ocs(Ocs\TimelineController::class, TimelineService::class, [], self::STRANGER)->gaps($this->uuid);
	}

	public function testTheFigures(): void {
		$kpis = $this->ocs(Ocs\KpiController::class, KpiService::class, ['from' => 1749900000, 'to' => 1750200000])->index($this->uuid);
		$this->assertSame(Http::STATUS_OK, $kpis->getStatus());
		$this->assertArrayHasKey('cost', self::data($kpis));
		$year = $this->ocs(Ocs\KpiController::class, KpiService::class, ['tz' => 'Europe/Berlin'])->year($this->uuid, '2025');
		$this->assertCount(12, self::data($year)['months']);

		$refused = $this->ocs(Ocs\KpiController::class, KpiService::class, ['tz' => 'Europe/Berlin'])->year($this->uuid, '2025.5');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'A year is four digits'], $refused->getData());
	}

	public function testReminders(): void {
		$reminders = fn (array $params = []): Ocs\ReminderController => $this->ocs(Ocs\ReminderController::class, ReminderService::class, $params);
		$created = $reminders(['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 135000])->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$reminder = self::data($created);
		$this->assertSame('oil_change', $reminder['template_key']);
		$listed = self::data($reminders()->index($this->uuid));
		$this->assertSame([$reminder['uuid']], array_column($listed, 'uuid'));
		$this->assertArrayHasKey('estimate', $listed[0]);
		$this->assertContains($this->uuid, array_column(self::data($reminders()->fleet()), 'vehicle'));
		$this->assertContains('oil_change', array_column(self::data($reminders()->templates($this->uuid)), 'key'));

		$stale = $reminders(['updated_at' => $reminder['updated_at'] - 1, 'until' => '2036-05-01'])->snooze($this->uuid, $reminder['uuid']);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $stale->getStatus());
		$this->assertSame(['message' => 'Changed since you read it', 'conflict' => true], $stale->getData());
	}

	public function testRecipients(): void {
		$listed = $this->ocs(Ocs\RecipientController::class, RecipientService::class)->index($this->uuid);
		$this->assertSame([self::OWNER], array_column(self::data($listed), 'user_id'));

		$refused = $this->ocs(Ocs\RecipientController::class, RecipientService::class, ['user_id' => self::STRANGER])->create($this->uuid);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertIsString(self::data($refused)['message']);
	}

	/** The owner holds no grant, so has none to leave. */
	public function testGrants(): void {
		$this->assertSame([], self::data($this->ocs(Ocs\GrantController::class, GrantService::class)->index($this->uuid)));
		$this->assertSame(['role' => null, 'groups' => []], self::data($this->ocs(Ocs\GrantController::class, GrantService::class)->held($this->uuid)));

		$this->expectException(OCSNotFoundException::class);
		$this->ocs(Ocs\GrantController::class, GrantService::class)->leave($this->uuid);
	}

	public function testDocuments(): void {
		$this->assertSame([], self::data($this->ocs(Ocs\DocumentController::class, DocumentService::class)->index($this->uuid)));

		$refused = $this->ocs(Ocs\DocumentController::class, DocumentService::class, ['file_id' => 'the receipt', 'kind' => 'receipt'])->create($this->uuid);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'file_id is the id of a file in Files'], $refused->getData());
	}

	/** A booking over another is the 409 that names the one in the way. */
	public function testBookings(): void {
		$start = time() + 86400;
		$span = ['starts_at' => $start, 'starts_at_off' => 120, 'ends_at' => $start + 3 * 3600, 'ends_at_off' => 120];
		$created = $this->ocs(Ocs\BookingController::class, BookingService::class, $span)->create($this->uuid);
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$booking = self::data($created);
		$this->assertSame('booked', $booking['state']);
		$this->assertSame([$booking['uuid']], array_column(self::data($this->ocs(Ocs\BookingController::class, BookingService::class)->index($this->uuid)), 'uuid'));

		$clash = $this->ocs(Ocs\BookingController::class, BookingService::class, $span)->create($this->uuid);

		$this->assertSame(Http::STATUS_CONFLICT, $clash->getStatus());
		$this->assertSame($booking['uuid'], self::data($clash)['booking']['uuid']);
	}

	/** The owner is no account, so the only write here is one refused before anything is stored. */
	public function testPreferencesAndTheInbox(): void {
		$this->assertArrayHasKey('kpi_period', self::data($this->ocs(Ocs\PreferencesController::class, PreferencesService::class)->index())['preferences']);
		$this->assertSame(['folder' => null, 'files' => [], 'count' => 0], self::data($this->ocs(Ocs\InboxController::class, InboxService::class)->index()));

		$refused = $this->ocs(Ocs\PreferencesController::class, PreferencesService::class, ['kpi_period' => 'decade'])->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertStringStartsWith('kpi_period is one of', self::data($refused)['message']);
	}

	/** SyncTest walks the protocol; this is the door: the answer, and a cursor nobody handed out. */
	public function testSync(): void {
		$sync = $this->ocs(Ocs\SyncController::class, SyncService::class);

		$answer = self::data($sync->index());
		$this->assertSame([$this->uuid], array_column($answer['vehicles'], 'uuid'));
		$this->assertSame(Http::STATUS_OK, $sync->index($answer['cursor'], 1)->getStatus());

		$refused = $sync->index('not-a-cursor');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'cursor is not one this route hands out'], $refused->getData());
	}
}
