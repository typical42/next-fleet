<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\StatisticsController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\StatisticsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Statistics screen's read. Every rule is StatisticsService's, so this tests request to
 * answer only.
 */
class StatisticsControllerTest extends TestCase {
	private StatisticsService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(StatisticsService::class);
	}

	/** @param array<string, mixed> $params */
	private function controller(array $params = []): StatisticsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new StatisticsController(Application::APP_ID, $request, $this->service, $session);
	}

	public function testTheRangeReachesTheServiceAsAsked(): void {
		$params = ['vehicle' => 'v-1', 'from' => '2025-01-01', 'to' => '2026-01-01', 'tz' => 'Europe/Berlin'];
		$this->service->expects($this->once())
			->method('of')
			->with('alice', $params)
			->willReturn(['bucket' => 'month']);

		$response = $this->controller($params)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['bucket' => 'month'], $response->getData());
	}

	/** @dataProvider refusals */
	public function testEachRefusalIsTheStatusTheScreenActsOn(\Exception $refusal, int $status): void {
		$this->service->method('of')->willThrowException($refusal);

		$this->assertSame($status, $this->controller()->index()->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException(''), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'no range' => [new \InvalidArgumentException('from and to'), Http::STATUS_BAD_REQUEST];
	}
}
