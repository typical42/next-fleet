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
			'uuid' => '0195e2f1-1111-4000-8000-000000000001',
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
}
