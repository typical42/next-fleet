<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller\Ocs;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\Ocs\KpiController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\KpiService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle's year, a read only v1 serves: the web UI makes none, so it has no internal twin for
 * TwinAnswersTest to hold it to. Every rule is KpiService's, so this tests request to answer only.
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

	public function testAYearThatIsNoneIsABadRequest(): void {
		$this->service->method('year')->willThrowException(new \InvalidArgumentException('year is four digits'));

		$response = $this->controller()->year(self::UUID, '25');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'year is four digits'], $response->getData());
	}

	public function testAVehicleNotTheCallersIsForbidden(): void {
		$this->service->method('year')->willThrowException(new AccessDeniedException('no'));

		$this->expectException(OCSForbiddenException::class);
		$this->controller()->year(self::UUID, '2025');
	}

	public function testNoSuchVehicleIsNotFound(): void {
		$this->service->method('year')->willThrowException(new DoesNotExistException(''));

		$this->expectException(OCSNotFoundException::class);
		$this->controller()->year(self::UUID, '2025');
	}
}
