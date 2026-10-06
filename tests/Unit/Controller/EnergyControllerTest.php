<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\EnergyController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\EnergyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * One vehicle's fill-ups. Every rule is EnergyService's, so this tests request to answer only, as
 * OdometerControllerTest does.
 */
class EnergyControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const FILL_UP = '0195e2f1-0000-4000-8000-000000000002';

	private EnergyService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(EnergyService::class);
	}

	private function controller(): EnergyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EnergyController(Application::APP_ID, $request, $this->service, $session);
	}

	/** The sheet learns the derived unit price and the raised flags: no client fills those in. */
	public function testARecordedFillUpComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'energy' => 'diesel', 'amount' => '42000'];
		$this->service->expects($this->once())
			->method('record')
			->with('alice', self::UUID, $this->params)
			->willReturn(['unit_price' => 1750, 'flags' => ['no_price']]);

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['unit_price' => 1750, 'flags' => ['no_price']], $response->getData());
	}

	public function testThePrefillIsWhatTheServiceStates(): void {
		$this->params = ['uuid' => self::UUID, 'at' => '1750000000', 'off' => '120'];
		$this->service->expects($this->once())
			->method('prefill')
			->with('alice', self::UUID, $this->params)
			->willReturn(['vat_rate' => 1900, 'stations' => []]);

		$response = $this->controller()->prefill(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['vat_rate' => 1900, 'stations' => []], $response->getData());
	}

	/** The token and conflict rules themselves are the trait's (MaintenanceControllerTest). */
	public function testTheCheckedWritesReachTheService(): void {
		$this->params = ['updated_at' => '1750000000', 'amount' => '41000'];
		$this->service->expects($this->once())->method('update')
			->with('alice', self::UUID, self::FILL_UP, 1750000000, $this->params)->willReturn(['amount' => 41000]);
		$this->service->expects($this->once())->method('delete')
			->with('alice', self::UUID, self::FILL_UP, 1750000000)->willReturn(['deleted_at' => 1750000001]);
		$this->service->expects($this->once())->method('restore')
			->with('alice', self::UUID, self::FILL_UP, 1750000000)->willReturn(['deleted_at' => null]);

		$this->assertSame(['amount' => 41000], $this->controller()->update(self::UUID, self::FILL_UP)->getData());
		$this->assertSame(['deleted_at' => 1750000001], $this->controller()->delete(self::UUID, self::FILL_UP)->getData());
		$this->assertSame(['deleted_at' => null], $this->controller()->restore(self::UUID, self::FILL_UP)->getData());
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
		$this->assertSame($status, $this->controller()->update(self::UUID, self::FILL_UP)->getStatus());
		$this->assertSame($status, $this->controller()->delete(self::UUID, self::FILL_UP)->getStatus());
		$this->assertSame($status, $this->controller()->restore(self::UUID, self::FILL_UP)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('amount is a whole number, never negative'), Http::STATUS_BAD_REQUEST];
	}
}
