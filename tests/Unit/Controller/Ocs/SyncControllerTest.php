<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller\Ocs;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\Ocs\SyncController;
use OCA\NextFleet\Service\SyncService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncControllerTest extends TestCase {
	private SyncService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(SyncService::class);
	}

	private function controller(): SyncController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SyncController(Application::APP_ID, $this->createMock(IRequest::class), $this->service, $session);
	}

	public function testTheCursorAndLimitReachTheServiceForTheSessionUser(): void {
		$this->service->expects($this->once())
			->method('sync')
			->with('alice', 'a cursor', 20)
			->willReturn(['cursor' => 'next']);

		$response = $this->controller()->index('a cursor', 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['cursor' => 'next'], $response->getData());
	}

	/**
	 * `?cursor[]=…` arrives as an array: the framework casts only int, float and bool, so a
	 * `string` parameter would make it a 500 rather than the 400 a forged cursor gets.
	 */
	public function testACursorThatIsNotEvenAWordIsRefusedRatherThanFatal(): void {
		$this->service->expects($this->never())->method('sync');

		// The docblock says string for openapi.json, which documents what a client should send.
		/** @psalm-suppress InvalidArgument */
		$response = $this->controller()->index(['a cursor']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'cursor is a word or nothing at all'], $response->getData());
	}
}
