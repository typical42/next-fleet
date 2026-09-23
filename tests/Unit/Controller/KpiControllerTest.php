<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\KpiController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\KpiService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The header's figures. Every rule is KpiService's, so what is tested here is the translation
 * between a request and an answer.
 */
class KpiControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

	private KpiService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(KpiService::class);
	}

	/** @param array<string, mixed> $params */
	private function controller(array $params = []): KpiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new KpiController(Application::APP_ID, $request, $this->service, $session);
	}

	public function testThePeriodReachesTheServiceAsAsked(): void {
		$params = ['from' => '1749900000', 'to' => '1750200000', 'net' => 'true'];
		$this->service->expects($this->once())
			->method('of')
			->with('alice', self::UUID, $params)
			->willReturn(['hours' => null]);

		$response = $this->controller($params)->index(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['hours' => null], $response->getData());
	}

	public function testTheYearAndTheZoneReachTheServiceAsAsked(): void {
		$params = ['tz' => 'Europe/Berlin', 'net' => 'false'];
		$this->service->expects($this->once())
			->method('year')
			->with('alice', self::UUID, '2025', $params)
			->willReturn(['months' => []]);

		$response = $this->controller($params)->year(self::UUID, '2025');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['months' => []], $response->getData());
	}

	/** @dataProvider refusals */
	public function testTheYearRefusesAsTheHeaderDoes(\Exception $refusal, int $status): void {
		$this->service->method('year')->willThrowException($refusal);

		$this->assertSame($status, $this->controller()->year(self::UUID, '2025')->getStatus());
	}

	/** @dataProvider refusals */
	public function testEachRefusalIsTheStatusTheScreenActsOn(\Exception $refusal, int $status): void {
		$this->service->method('of')->willThrowException($refusal);

		$this->assertSame($status, $this->controller()->index(self::UUID)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException(''), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'no period' => [new \InvalidArgumentException('from and to'), Http::STATUS_BAD_REQUEST];
	}
}
