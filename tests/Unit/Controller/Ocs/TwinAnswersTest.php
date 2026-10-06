<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller\Ocs;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\BookingConflictException;
use OCA\NextFleet\Exception\CurrencyInUseException;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * One rule, two doors (docs/api.md): the OCS twin hands the same request to the same service call
 * and answers the same refusal its internal twin does - 403 and 404 as Nextcloud's OCS
 * exceptions, the rest with the internal answer's own body.
 */
class TwinAnswersTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const TRIP = '0195e2f1-1111-4000-8000-000000000001';
	/** A reminder, a paper or a booking: the service is a mock, so any row will do. */
	private const ROW = '0195e2f1-2222-4000-8000-000000000001';
	private const BOOKING = ['uuid' => 'b', 'user_id' => 'bob', 'user_name' => 'Bob', 'starts_at' => 1, 'starts_at_off' => 0, 'ends_at' => 2, 'ends_at_off' => 0, 'state' => 'out'];

	/** @param array<string, mixed> $params */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * A status and a body, from either door: an OCS exception is what the framework turns into the
	 * envelope's status and message.
	 *
	 * @param \Closure(): DataResponse $call
	 * @return array{int, mixed}
	 */
	private static function answered(\Closure $call): array {
		try {
			$response = $call();
		} catch (OCSException $e) {
			return [$e->getCode(), ['message' => $e->getMessage()]];
		}

		return [$response->getStatus(), json_decode((string)json_encode($response->getData()), true)];
	}

	/**
	 * @param class-string $internal
	 * @param class-string $service
	 * @param array<string, mixed> $params
	 * @param \Closure(object): DataResponse $call
	 * @param ?\Closure(object): void $behave
	 * @return array{array{int, mixed}, array{int, mixed}}
	 */
	private function bothDoors(string $internal, string $service, array $params, \Closure $call, ?\Closure $behave): array {
		$answers = [];
		foreach ([$internal, str_replace('\\Controller\\', '\\Controller\\Ocs\\', $internal)] as $class) {
			$mock = $this->createMock($service);
			if ($behave !== null) {
				$behave($mock);
			}
			$controller = new $class(Application::APP_ID, $this->request($params), $mock, $this->session());
			$answers[] = self::answered(static fn (): DataResponse => $call($controller));
		}

		return [$answers[0], $answers[1]];
	}

	/** @dataProvider refusals */
	public function testARefusalIsTheSameRefusalThroughEitherDoor(\Throwable $thrown, int $status): void {
		[$internal, $ocs] = $this->bothDoors(
			Controller\VehicleController::class,
			VehicleService::class,
			['updated_at' => 1750000000],
			static fn (object $c): DataResponse => $c->update(self::UUID),
			static fn (object $mock) => $mock->method('update')->willThrowException($thrown),
		);

		$this->assertSame($status, $internal[0]);
		$this->assertSame($internal, $ocs);
	}

	/** @return iterable<string, array{\Throwable, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'lost a race' => [new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'a bad field' => [new \InvalidArgumentException('plate is too long'), Http::STATUS_BAD_REQUEST];
		yield 'a currency in use' => [new CurrencyInUseException('currency stays'), Http::STATUS_BAD_REQUEST];
	}

	/**
	 * Each family's own refusal, through every OcsAnswers wrapper a twin uses.
	 *
	 * @dataProvider familyRefusals
	 * @param class-string $internal
	 * @param class-string $service
	 * @param \Closure(object): DataResponse $call
	 */
	public function testEachFamilyRefusesAlikeThroughEitherDoor(string $internal, string $service, string $method, \Closure $call, \Throwable $thrown, int $status): void {
		[$internalAnswer, $ocs] = $this->bothDoors(
			$internal,
			$service,
			['updated_at' => 1750000000],
			$call,
			static fn (object $mock) => $mock->method($method)->willThrowException($thrown),
		);

		$this->assertSame($status, $internalAnswer[0]);
		$this->assertSame($internalAnswer, $ocs);
	}

	/** @return iterable<string, array{class-string, class-string, string, \Closure(object): DataResponse, \Throwable, int}> */
	public static function familyRefusals(): iterable {
		yield 'a snooze that lost a race' => [Controller\ReminderController::class, ReminderService::class, 'snooze',
			static fn (object $c): DataResponse => $c->snooze(self::UUID, self::ROW), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'a recipient who is no account' => [Controller\RecipientController::class, RecipientService::class, 'add',
			static fn (object $c): DataResponse => $c->create(self::UUID), new \InvalidArgumentException('no such account'), Http::STATUS_BAD_REQUEST];
		yield 'a grant by a non-owner' => [Controller\GrantController::class, GrantService::class, 'grant',
			static fn (object $c): DataResponse => $c->create(self::UUID), new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a paper that is not there' => [Controller\DocumentController::class, DocumentService::class, 'detach',
			static fn (object $c): DataResponse => $c->delete(self::UUID, self::ROW), new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'a check-out while the car is out' => [Controller\BookingController::class, BookingService::class, 'checkOut',
			static fn (object $c): DataResponse => $c->checkOut(self::UUID, self::ROW), new BookingConflictException('the vehicle is still out', self::BOOKING), Http::STATUS_CONFLICT];
		yield 'a cancel that lost a race' => [Controller\BookingController::class, BookingService::class, 'cancel',
			static fn (object $c): DataResponse => $c->delete(self::UUID, self::ROW), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'a check-in that lost a race' => [Controller\BookingController::class, BookingService::class, 'checkIn',
			static fn (object $c): DataResponse => $c->checkIn(self::UUID, self::ROW), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'a revoke that lost a race' => [Controller\GrantController::class, GrantService::class, 'revoke',
			static fn (object $c): DataResponse => $c->delete(self::UUID, self::ROW), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'an import by a driver' => [Controller\ImportController::class, ImportService::class, 'preview',
			static fn (object $c): DataResponse => $c->preview(self::UUID), new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a file the import will not read' => [Controller\ImportController::class, ImportService::class, 'preview',
			static fn (object $c): DataResponse => $c->preview(self::UUID), new ImportRefusedException('line_too_long', 7), Http::STATUS_UNPROCESSABLE_ENTITY];
		yield 'a file being written' => [Controller\ImportController::class, ImportService::class, 'preview',
			static fn (object $c): DataResponse => $c->preview(self::UUID), new LockedException('import.csv'), Http::STATUS_LOCKED];
		yield 'an import of a file changed since its preview' => [Controller\ImportController::class, ImportService::class, 'import',
			static fn (object $c): DataResponse => $c->import(self::UUID), new FileChangedException('changed'), Http::STATUS_CONFLICT];
		yield 'an import while a question is open' => [Controller\ImportController::class, ImportService::class, 'import',
			static fn (object $c): DataResponse => $c->import(self::UUID), new \InvalidArgumentException('Still to be answered: energy'), Http::STATUS_BAD_REQUEST];
		yield 'an import of a file being written' => [Controller\ImportController::class, ImportService::class, 'import',
			static fn (object $c): DataResponse => $c->import(self::UUID), new LockedException('import.csv'), Http::STATUS_LOCKED];
		yield 'an undo naming an entry the import did not leave' => [Controller\ImportController::class, ImportService::class, 'undo',
			static fn (object $c): DataResponse => $c->undo(self::UUID), new ImportChangedException('energy e is not a live entry of the caller\'s on this vehicle'), Http::STATUS_CONFLICT];
		yield 'an import that lost a race' => [Controller\ImportController::class, ImportService::class, 'import',
			static fn (object $c): DataResponse => $c->import(self::UUID), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'an undo that lost a race' => [Controller\ImportController::class, ImportService::class, 'undo',
			static fn (object $c): DataResponse => $c->undo(self::UUID), new StaleUpdateException('stale'), Http::STATUS_PRECONDITION_FAILED];
		yield 'an undo of no import\'s list' => [Controller\ImportController::class, ImportService::class, 'undo',
			static fn (object $c): DataResponse => $c->undo(self::UUID), new \InvalidArgumentException('created is the list an import answered'), Http::STATUS_BAD_REQUEST];
		yield 'a trip that drove backwards' => [Controller\TripController::class, TripService::class, 'update',
			static fn (object $c): DataResponse => $c->update(self::UUID, self::ROW), new RefusedException('end_odo is below start_odo', TripService::END_BELOW_START), Http::STATUS_BAD_REQUEST];
		yield 'a reset of a reading nobody questioned' => [Controller\OdometerController::class, OdometerService::class, 'reset',
			static fn (object $c): DataResponse => $c->reset(self::UUID, self::ROW), new RefusedException('not in question', OdometerService::NOT_IN_QUESTION), Http::STATUS_BAD_REQUEST];
		yield 'a preference it may not take' => [Controller\PreferencesController::class, PreferencesService::class, 'write',
			static fn (object $c): DataResponse => $c->update(), new \InvalidArgumentException('kpi_period is one of …'), Http::STATUS_BAD_REQUEST];
	}

	public function testTheOcsDoorRefusesWithNextcloudsOwnExceptionsForForbiddenAndNotFound(): void {
		foreach ([[new AccessDeniedException('no'), OCSForbiddenException::class], [new DoesNotExistException('gone'), OCSNotFoundException::class]] as [$thrown, $expected]) {
			$service = $this->createMock(VehicleService::class);
			$service->method('find')->willThrowException($thrown);
			$controller = new Controller\Ocs\VehicleController(Application::APP_ID, $this->request([]), $service, $this->session());

			try {
				$controller->show(self::UUID);
				$this->fail('no refusal');
			} catch (OCSException $e) {
				$this->assertInstanceOf($expected, $e);
			}
		}
	}

	public function testAWriteWithoutItsTokenIsTheSame400(): void {
		[$internal, $ocs] = $this->bothDoors(
			Controller\VehicleController::class,
			VehicleService::class,
			[],
			static fn (object $c): DataResponse => $c->delete(self::UUID),
			null,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $internal[0]);
		$this->assertSame($internal, $ocs);
	}

	public function testABookingInTheWayIsTheSame409NamingIt(): void {
		[$internal, $ocs] = $this->bothDoors(
			Controller\TripController::class,
			TripService::class,
			[],
			static fn (object $c): DataResponse => $c->create(self::UUID),
			static fn (object $mock) => $mock->method('record')->willThrowException(new BookingConflictException('Still out', self::BOOKING)),
		);

		$this->assertSame([Http::STATUS_CONFLICT, ['message' => 'Still out', 'booking' => self::BOOKING]], $internal);
		$this->assertSame($internal, $ocs);
	}

	/** The checks that sit in the controller - a reconcile's numbers, a query word - are shared too. */
	public function testTheControllersOwnChecksAreTheSameThroughEitherDoor(): void {
		[$internal, $ocs] = $this->bothDoors(
			Controller\TripController::class,
			TripService::class,
			['distance' => 'far'],
			static fn (object $c): DataResponse => $c->reconcile(self::UUID, self::TRIP),
			null,
		);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $internal[0]);
		$this->assertSame($internal, $ocs);

		[$internal, $ocs] = $this->bothDoors(
			Controller\TimelineController::class,
			TimelineService::class,
			[],
			static fn (object $c): DataResponse => $c->index(self::UUID, ['trip']),
			null,
		);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $internal[0]);
		$this->assertSame($internal, $ocs);
	}

	/** A success is the same JSON too, entity or array, with the same status. */
	public function testAnAnswerIsTheSameJsonThroughEitherDoor(): void {
		$vehicle = Vehicle::fromRow(['id' => 7, 'uuid' => self::UUID, 'user_id' => 'alice', 'plate' => 'B-XY 123', 'updated_at' => 1750000000]);
		$vehicle->setMay(['view', 'log']);
		[$internal, $ocs] = $this->bothDoors(
			Controller\VehicleController::class,
			VehicleService::class,
			['plate' => 'B-XY 123'],
			static fn (object $c): DataResponse => $c->create(),
			static fn (object $mock) => $mock->method('create')->with('alice', ['plate' => 'B-XY 123'])->willReturn($vehicle),
		);
		$this->assertSame(Http::STATUS_CREATED, $internal[0]);
		$this->assertSame($internal, $ocs);

		$trip = Trip::fromRow(['id' => 3, 'uuid' => self::TRIP, 'vehicle_id' => 7, 'category' => Trip::BUSINESS]);
		[$internal, $ocs] = $this->bothDoors(
			Controller\TripController::class,
			TripService::class,
			['distance' => 20, 'from_at' => 1, 'to_at' => 2],
			static fn (object $c): DataResponse => $c->reconcile(self::UUID, self::TRIP),
			static fn (object $mock) => $mock->method('reconcile')->with('alice', self::UUID, self::TRIP, 20, 1, 2)->willReturn($trip),
		);
		$this->assertSame(Http::STATUS_CREATED, $internal[0]);
		$this->assertSame($internal, $ocs);
	}
}
