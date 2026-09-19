<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\ExpenseController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\ExpenseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Expenses hanging off one vehicle: the translation between a request and an answer, as
 * EnergyControllerTest tests it.
 */
class ExpenseControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const EXPENSE = '0195e2f1-0000-4000-8000-000000000002';

	private ExpenseService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(ExpenseService::class);
	}

	private function controller(): ExpenseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ExpenseController(Application::APP_ID, $request, $this->service, $session);
	}

	/** A recorded expense answers 201 with the row the server wrote. */
	public function testAnExpenseComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'amount' => '64000'];
		$this->service->expects($this->once())
			->method('record')
			->with('alice', self::UUID, $this->params)
			->willReturn(['amount' => 64000, 'category' => null]);

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['amount' => 64000, 'category' => null], $response->getData());
	}

	/** The prefill answers 200 with what the service states for the moment the sheet asked about. */
	public function testThePrefillIsWhatTheServiceStates(): void {
		$this->params = ['uuid' => self::UUID, 'at' => '1750000000', 'off' => '120'];
		$this->service->expects($this->once())
			->method('prefill')
			->with('alice', self::UUID, $this->params)
			->willReturn(['vat_rate' => 1900]);

		$response = $this->controller()->prefill(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['vat_rate' => 1900], $response->getData());
	}

	/**
	 * An edit, a delete and its undo each reach the service with the token the client read. The
	 * token and conflict rules themselves are the trait's (MaintenanceControllerTest).
	 */
	public function testTheCheckedWritesReachTheService(): void {
		$this->params = ['updated_at' => '1750000000', 'amount' => '1250'];
		$this->service->expects($this->once())->method('update')
			->with('alice', self::UUID, self::EXPENSE, 1750000000, $this->params)->willReturn(['amount' => 1250]);
		$this->service->expects($this->once())->method('delete')
			->with('alice', self::UUID, self::EXPENSE, 1750000000)->willReturn(['deleted_at' => 1750000001]);
		$this->service->expects($this->once())->method('restore')
			->with('alice', self::UUID, self::EXPENSE, 1750000000)->willReturn(['deleted_at' => null]);

		$this->assertSame(['amount' => 1250], $this->controller()->update(self::UUID, self::EXPENSE)->getData());
		$this->assertSame(['deleted_at' => 1750000001], $this->controller()->delete(self::UUID, self::EXPENSE)->getData());
		$this->assertSame(['deleted_at' => null], $this->controller()->restore(self::UUID, self::EXPENSE)->getData());
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
		$this->assertSame($status, $this->controller()->update(self::UUID, self::EXPENSE)->getStatus());
		$this->assertSame($status, $this->controller()->delete(self::UUID, self::EXPENSE)->getStatus());
		$this->assertSame($status, $this->controller()->restore(self::UUID, self::EXPENSE)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('amount is a field every expense carries'), Http::STATUS_BAD_REQUEST];
	}
}
