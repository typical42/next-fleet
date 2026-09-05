<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\VehicleController;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller carries no rules (docs/adr/0006-one-api-surface-in-v1.md), so what is worth
 * testing is the translation: who is asking, what the request says, and which status the answer
 * gets.
 */
class VehicleControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

	private VehicleService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(VehicleService::class);
	}

	private function controller(): VehicleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new VehicleController(Application::APP_ID, $request, $this->service, $session);
	}

	private function stored(): Vehicle {
		return Vehicle::fromRow([
			'id' => 7,
			'uuid' => self::UUID,
			'user_id' => 'alice',
			'plate' => 'B-XY 123',
			'updated_at' => 1750000000,
		]);
	}

	/** The overview asks for the vehicles of whoever is logged in, and nobody else's. */
	public function testTheListIsTheSessionUsers(): void {
		$this->service->expects($this->once())
			->method('list')
			->with('alice')
			->willReturn([$this->stored()]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	/** A vehicle nobody has is a 404, not an empty answer with a 200 on it. */
	public function testAMissingVehicleIsNotFound(): void {
		$this->service->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show(self::UUID)->getStatus());
	}

	/**
	 * A create answers 201 with the vehicle, so the client learns the identity the server chose
	 * and the token its next write has to carry.
	 */
	public function testACreatedVehicleComesBackWithItsIdentity(): void {
		$this->params = ['plate' => 'B-XY 123'];
		$this->service->expects($this->once())
			->method('create')
			->with('alice', ['plate' => 'B-XY 123'])
			->willReturn($this->stored());

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(self::UUID, $response->getData()->getUuid());
	}

	/** The identity is the route's, and the token comes with the body. */
	public function testAnUpdateCarriesTheRouteIdentityAndTheClientsToken(): void {
		$this->params = ['uuid' => self::UUID, 'updated_at' => 1750000000, 'plate' => 'B-ZZ 9'];
		$this->service->expects($this->once())
			->method('update')
			->with(self::UUID, 1750000000, $this->params)
			->willReturn($this->stored());

		$this->assertSame(Http::STATUS_OK, $this->controller()->update(self::UUID)->getStatus());
	}

	/** A delete takes a token too - it is a write like any other. */
	public function testADeleteCarriesTheClientsToken(): void {
		$this->params = ['uuid' => self::UUID, 'updated_at' => '1750000000'];
		$this->service->expects($this->once())
			->method('delete')
			->with(self::UUID, 1750000000)
			->willReturn($this->stored());

		$this->assertSame(Http::STATUS_OK, $this->controller()->delete(self::UUID)->getStatus());
	}

	/**
	 * Without a token there is nothing to check the write against, and a write that skips the
	 * check is the lost update the whole mechanism exists to prevent.
	 */
	public function testAWriteWithoutATokenIsRefused(): void {
		$this->params = ['uuid' => self::UUID, 'plate' => 'B-ZZ 9'];
		$this->service->expects($this->never())->method('update');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update(self::UUID)->getStatus());
	}

	/** A field the column cannot hold is the request's fault, and it says which field. */
	public function testAValueTheColumnCannotHoldIsABadRequest(): void {
		$this->params = ['vehicle_type' => 'spaceship'];
		$this->service->method('create')
			->willThrowException(new \InvalidArgumentException('vehicle_type is one of car, van'));

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'vehicle_type is one of car, van'], $response->getData());
	}
}
