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
 * The fill-ups hanging off one vehicle. Every rule is EnergyService's, so what is tested here is
 * the translation between a request and an answer, as OdometerControllerTest does.
 */
class EnergyControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

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

	/**
	 * A recorded fill-up answers 201 with the row the server wrote, so the sheet learns the unit
	 * price it derived and the flags it raised - neither is a field a client fills in.
	 */
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

	/** The prefill answers 200 with what the service states for the moment the sheet asked about. */
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

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalIsTheStatusItMeans(\Exception $refusal, int $status): void {
		$this->service->method('record')->willThrowException($refusal);
		$this->service->method('prefill')->willThrowException($refusal);

		$this->assertSame($status, $this->controller()->create(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->prefill(self::UUID)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('amount is a whole number, never negative'), Http::STATUS_BAD_REQUEST];
	}
}
