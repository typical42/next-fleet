<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\OdometerController;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Service\OdometerService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The readings hanging off one vehicle. Every rule is OdometerService's, so what is tested here
 * is the translation between a request and an answer, the way VehicleControllerTest tests the
 * vehicle's.
 */
class OdometerControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

	private OdometerService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(OdometerService::class);
	}

	private function controller(): OdometerController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OdometerController(Application::APP_ID, $request, $this->service, $session);
	}

	private function stored(): OdoReading {
		return OdoReading::fromRow([
			'id' => 3,
			'uuid' => self::UUID,
			'vehicle_id' => 7,
			'read_at' => 1750000000,
			'read_at_off' => 120,
			'value' => 148320,
			'kind' => 'reading',
			'origin' => 'observed',
			'source_type' => 'manual',
		]);
	}

	/** The timeline asks for one vehicle's readings, and the vehicle is the route's. */
	public function testTheTimelineIsOneVehiclesReadings(): void {
		$this->service->expects($this->once())
			->method('list')
			->with('alice', self::UUID)
			->willReturn([$this->stored()]);

		$response = $this->controller()->index(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	/**
	 * A recorded reading answers 201 with the row the server wrote, so the sheet learns the
	 * `origin` and the `flagged` it was given - neither is a field a client fills in.
	 */
	public function testARecordedReadingComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'value' => '148320', 'read_at_off' => 120];
		$this->service->expects($this->once())
			->method('record')
			->with('alice', self::UUID, $this->params)
			->willReturn($this->stored());

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('observed', $response->getData()->jsonSerialize()['origin']);
	}
}
