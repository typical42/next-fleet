<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\MaintenanceController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\MaintenanceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Maintenance Records hanging off one vehicle: the translation between a request and an
 * answer, as EnergyControllerTest tests it.
 */
class MaintenanceControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const RECORD = '0195e2f1-0000-4000-8000-000000000002';

	private MaintenanceService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(MaintenanceService::class);
	}

	private function controller(): MaintenanceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new MaintenanceController(Application::APP_ID, $request, $this->service, $session);
	}

	/** A recorded record answers 201 with the row the server wrote. */
	public function testARecordComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'title' => 'Oil change'];
		$this->service->expects($this->once())
			->method('record')
			->with('alice', self::UUID, $this->params)
			->willReturn(['title' => 'Oil change', 'cost' => null]);

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['title' => 'Oil change', 'cost' => null], $response->getData());
	}

	/** The prefill answers 200 with what the service states for the moment the sheet asked about. */
	public function testThePrefillIsWhatTheServiceStates(): void {
		$this->params = ['uuid' => self::UUID, 'at' => '1750000000', 'off' => '120'];
		$this->service->expects($this->once())
			->method('prefill')
			->with('alice', self::UUID, $this->params)
			->willReturn(['vat_rate' => 1900, 'vendors' => ['ATU Nord']]);

		$response = $this->controller()->prefill(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['vat_rate' => 1900, 'vendors' => ['ATU Nord']], $response->getData());
	}

	/** An edit hands the service the token the client read, and answers with the row as it stands. */
	public function testAnEditIsCheckedAgainstTheTokenTheClientRead(): void {
		$this->params = ['uuid' => self::UUID, 'record' => self::RECORD, 'updated_at' => '1750000000', 'title' => 'Wipers'];
		$this->service->expects($this->once())
			->method('update')
			->with('alice', self::UUID, self::RECORD, 1750000000, $this->params)
			->willReturn(['title' => 'Wipers']);

		$response = $this->controller()->update(self::UUID, self::RECORD);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['title' => 'Wipers'], $response->getData());
	}

	/** A delete and its undo each carry the token too, and answer with the row they left. */
	public function testADeleteAndItsUndoAreCheckedToo(): void {
		$this->params = ['updated_at' => '1750000000'];
		$this->service->expects($this->once())->method('delete')
			->with('alice', self::UUID, self::RECORD, 1750000000)->willReturn(['deleted_at' => 1750000001]);
		$this->service->expects($this->once())->method('restore')
			->with('alice', self::UUID, self::RECORD, 1750000000)->willReturn(['deleted_at' => null]);

		$this->assertSame(['deleted_at' => 1750000001], $this->controller()->delete(self::UUID, self::RECORD)->getData());
		$this->assertSame(['deleted_at' => null], $this->controller()->restore(self::UUID, self::RECORD)->getData());
	}

	/** Without a token there is nothing to check the write against, so it is not attempted. */
	public function testAWriteWithoutATokenIsRefused(): void {
		$this->service->expects($this->never())->method('update');
		$this->service->expects($this->never())->method('delete');
		$this->service->expects($this->never())->method('restore');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update(self::UUID, self::RECORD)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->delete(self::UUID, self::RECORD)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->restore(self::UUID, self::RECORD)->getStatus());
	}

	/** A write that lost the race says so in a way a failed CSRF check does not. */
	public function testAStaleWriteIsAConflict(): void {
		$this->params = ['updated_at' => '1750000000'];
		$this->service->method('update')->willThrowException(new StaleUpdateException('moved'));

		$response = $this->controller()->update(self::UUID, self::RECORD);

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertTrue($response->getData()['conflict']);
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalIsTheStatusItMeans(\Exception $refusal, int $status): void {
		$this->params = ['updated_at' => '1750000000'];
		foreach (['record', 'prefill', 'update', 'delete', 'restore'] as $method) {
			$this->service->method($method)->willThrowException($refusal);
		}

		$this->assertSame($status, $this->controller()->create(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->prefill(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->update(self::UUID, self::RECORD)->getStatus());
		$this->assertSame($status, $this->controller()->delete(self::UUID, self::RECORD)->getStatus());
		$this->assertSame($status, $this->controller()->restore(self::UUID, self::RECORD)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('title is a field every maintenance record carries'), Http::STATUS_BAD_REQUEST];
	}
}
