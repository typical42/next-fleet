<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\TripController;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\TripService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The trips hanging off one vehicle. Every rule is TripService's, so what is tested here is the
 * translation between a request and an answer, the way OdometerControllerTest tests the odometer's.
 */
class TripControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const TRIP = '0195e2f1-1111-4000-8000-000000000001';

	private TripService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(TripService::class);
	}

	private function controller(): TripController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TripController(Application::APP_ID, $request, $this->service, $session);
	}

	private function stored(): Trip {
		return Trip::fromRow([
			'id' => 3,
			'uuid' => self::TRIP,
			'vehicle_id' => 7,
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 148320,
			'category' => Trip::BUSINESS,
		]);
	}

	/**
	 * A recorded trip answers 201 with the row the server wrote, so the sheet learns what it was
	 * given - `reconciled` among it, which is not a field a client fills in.
	 */
	public function testARecordedTripComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'end_odo' => '148320', 'category' => Trip::BUSINESS];
		$this->service->expects($this->once())
			->method('record')
			->with('alice', self::UUID, $this->params)
			->willReturn($this->stored());

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(148320, $response->getData()->jsonSerialize()['end_odo']);
		$this->assertFalse($response->getData()->jsonSerialize()['reconciled']);
	}

	/**
	 * The three refusals a trip can meet, each as the status the sheet acts on: a uuid that is
	 * nobody's, a vehicle that is not this user's, and a field the columns cannot hold.
	 *
	 * @dataProvider refusals
	 */
	public function testARefusedTripAnswersWithItsOwnStatus(\Throwable $thrown, int $status): void {
		$this->service->method('record')->willThrowException($thrown);

		$this->assertSame($status, $this->controller()->create(self::UUID)->getStatus());
	}

	/**
	 * @return iterable<string, array{\Throwable, int}>
	 */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException(), Http::STATUS_FORBIDDEN];
		yield 'a field the column cannot hold' => [
			new \InvalidArgumentException('category is one of business, private, commute'),
			Http::STATUS_BAD_REQUEST,
		];
	}

	/** The prefill answers 200 with the words the service found, and is refused like a write. */
	public function testThePrefillIsWhatTheServiceStates(): void {
		$words = ['places' => ['Office'], 'purposes' => ['Client visit'], 'partners' => []];
		$this->service->expects($this->once())
			->method('prefill')
			->with('alice', self::UUID)
			->willReturn($words);

		$response = $this->controller()->prefill(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($words, $response->getData());
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusedPrefillAnswersWithItsOwnStatus(\Throwable $thrown, int $status): void {
		$this->service->method('prefill')->willThrowException($thrown);

		$this->assertSame($status, $this->controller()->prefill(self::UUID)->getStatus());
	}

	/**
	 * A void answers with the row it left behind, so the toast that offers the undo holds the
	 * token the next write is checked against (docs/architecture.md#concurrency). A DELETE carries
	 * no body, so the token arrives in the query string and reaches the controller as a parameter
	 * like any other.
	 */
	public function testAVoidedTripComesBackWithTheTokenTheUndoNeeds(): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'updated_at' => '1750000009'];
		$this->service->expects($this->once())
			->method('delete')
			->with('alice', self::UUID, self::TRIP, 1750000009)
			->willReturn($this->voided());

		$response = $this->controller()->delete(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1750000010, $response->getData()->jsonSerialize()['deleted_at']);
	}

	/** Undo, the same way round. */
	public function testAnUndoneTripComesBackLive(): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'updated_at' => '1750000010'];
		$this->service->expects($this->once())
			->method('restore')
			->with('alice', self::UUID, self::TRIP, 1750000010)
			->willReturn($this->stored());

		$response = $this->controller()->restore(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()->jsonSerialize()['deleted_at']);
	}

	/**
	 * An edit hands the service the whole request as the trip's fields and the token it carries as
	 * the check, and answers with the row as it now stands - the next token among it.
	 */
	public function testAnEditedTripComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'updated_at' => '1750000009', 'end_odo' => '148320'];
		$this->service->expects($this->once())
			->method('update')
			->with('alice', self::UUID, self::TRIP, 1750000009, $this->params)
			->willReturn($this->stored());

		$response = $this->controller()->update(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(148320, $response->getData()->jsonSerialize()['end_odo']);
	}

	/**
	 * A write that arrives without the token cannot be checked at all, so it is refused before it
	 * reaches the service - the answer a vehicle's own delete gives.
	 *
	 * @dataProvider checkedWrites
	 */
	public function testAWriteWithoutTheTokenIsRefused(string $method): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP];
		$this->service->expects($this->never())->method($method);

		$response = $this->controller()->$method(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * The row moved on between the read and the write, which is a 412 carrying `conflict` - what
	 * tells it apart from Nextcloud's own failed CSRF check, a 412 as well.
	 *
	 * @dataProvider checkedWrites
	 */
	public function testAWriteThatLostTheRaceAnswersConflict(string $method): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'updated_at' => '1750000009'];
		$this->service->method($method)->willThrowException(new StaleUpdateException('moved on'));

		$response = $this->controller()->$method(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertTrue($response->getData()['conflict']);
	}

	/**
	 * Every write to a trip that exists is checked against the token the client read, so all three
	 * answer a missing or a stale one alike.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function checkedWrites(): iterable {
		yield 'update' => ['update'];
		yield 'delete' => ['delete'];
		yield 'restore' => ['restore'];
	}

	/**
	 * Closing a Gap creates a trip, so it answers 201 with the trip it created. What the driver
	 * confirmed travels as three numbers and reaches the service as three integers.
	 */
	public function testAClosedGapComesBackAsTheTripThatClosedIt(): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'distance' => '200', 'from_at' => '1749990000', 'to_at' => 1750000000];
		$this->service->expects($this->once())
			->method('reconcile')
			->with('alice', self::UUID, self::TRIP, 200, 1749990000, 1750000000)
			->willReturn($this->stored());

		$response = $this->controller()->reconcile(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(self::TRIP, $response->getData()->jsonSerialize()['uuid']);
	}

	/**
	 * A confirmation without all three is not a confirmation of anything, and is refused before it
	 * reaches the service.
	 *
	 * @dataProvider unconfirmed
	 * @param array<string, mixed> $confirmed
	 */
	public function testAConfirmationMissingWhatWasConfirmedIsRefused(array $confirmed): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP] + $confirmed;
		$this->service->expects($this->never())->method('reconcile');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->reconcile(self::UUID, self::TRIP)->getStatus());
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function unconfirmed(): iterable {
		yield 'no kilometres' => [['from_at' => '1749990000', 'to_at' => '1750000000']];
		yield 'no start' => [['distance' => '200', 'to_at' => '1750000000']];
		yield 'an end that is no number' => [['distance' => '200', 'from_at' => '1749990000', 'to_at' => 'yesterday']];
	}

	/** A Gap that moved since the driver read it is the conflict a stale token is. */
	public function testAGapThatMovedAnswersConflict(): void {
		$this->params = ['uuid' => self::UUID, 'trip' => self::TRIP, 'distance' => '200', 'from_at' => '1749990000', 'to_at' => '1750000000'];
		$this->service->method('reconcile')->willThrowException(new StaleUpdateException('no such gap'));

		$response = $this->controller()->reconcile(self::UUID, self::TRIP);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertTrue($response->getData()['conflict']);
	}

	/** The same trip after a void: the row survives, stamped and re-tokened. */
	private function voided(): Trip {
		$trip = $this->stored();
		$trip->setDeletedAt(1750000010);
		$trip->setUpdatedAt(1750000010);

		return $trip;
	}
}
