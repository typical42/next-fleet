<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\ReminderController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\ReminderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reminders hanging off one vehicle: the translation between a request and an answer, as
 * MaintenanceControllerTest tests it.
 */
class ReminderControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const REMINDER = '0195e2f1-0000-4000-8000-000000000003';

	private ReminderService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(ReminderService::class);
	}

	private function controller(): ReminderController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReminderController(Application::APP_ID, $request, $this->service, $session);
	}

	/** The list answers 200 with what the service returns for the session user. */
	public function testTheListIsTheVehiclesReminders(): void {
		$this->service->expects($this->once())
			->method('list')
			->with('alice', self::UUID)
			->willReturn([['template_key' => 'hu_au']]);

		$response = $this->controller()->index(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['template_key' => 'hu_au']], $response->getData());
	}

	/** The fleet's list answers 200 with every reminder the session user may see. */
	public function testTheFleetListIsTheSessionUsers(): void {
		$this->service->expects($this->once())
			->method('fleet')
			->with('alice')
			->willReturn([['vehicle' => self::UUID]]);

		$response = $this->controller()->fleet();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['vehicle' => self::UUID]], $response->getData());
	}

	/** The templates answer 200 with what the service offers the session user on this vehicle. */
	public function testTheTemplatesAreTheVehiclesOwn(): void {
		$this->service->expects($this->once())
			->method('templates')
			->with('alice', self::UUID)
			->willReturn([['key' => 'hu_au']]);

		$response = $this->controller()->templates(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['key' => 'hu_au']], $response->getData());
	}

	/** A new reminder answers 201 with the row the server wrote. */
	public function testAReminderComesBackAsTheServerWroteIt(): void {
		$this->params = ['uuid' => self::UUID, 'template_key' => 'hu_au', 'due_date' => '2027-05-31'];
		$this->service->expects($this->once())
			->method('create')
			->with('alice', self::UUID, $this->params)
			->willReturn(['template_key' => 'hu_au']);

		$response = $this->controller()->create(self::UUID);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['template_key' => 'hu_au'], $response->getData());
	}

	/** An edit, a delete and an undo each hand the service the token the client read. */
	public function testTheWritesAreCheckedAgainstTheToken(): void {
		$this->params = ['updated_at' => '1750000000', 'mode' => 'date'];
		$this->service->expects($this->once())->method('update')
			->with('alice', self::UUID, self::REMINDER, 1750000000, $this->params)->willReturn(['mode' => 'date']);
		$this->service->expects($this->once())->method('delete')
			->with('alice', self::UUID, self::REMINDER, 1750000000)->willReturn(['deleted_at' => 1750000001]);
		$this->service->expects($this->once())->method('restore')
			->with('alice', self::UUID, self::REMINDER, 1750000000)->willReturn(['deleted_at' => null]);

		$this->assertSame(['mode' => 'date'], $this->controller()->update(self::UUID, self::REMINDER)->getData());
		$this->assertSame(['deleted_at' => 1750000001], $this->controller()->delete(self::UUID, self::REMINDER)->getData());
		$this->assertSame(['deleted_at' => null], $this->controller()->restore(self::UUID, self::REMINDER)->getData());
	}

	/** A snooze hands on its day, a dismissal nothing but the token. */
	public function testSnoozeAndDismissAreCheckedAgainstTheToken(): void {
		$this->params = ['updated_at' => '1750000000', 'until' => '2036-05-01'];
		$this->service->expects($this->once())->method('snooze')
			->with('alice', self::UUID, self::REMINDER, 1750000000, '2036-05-01')->willReturn(['state' => 'snoozed']);
		$this->service->expects($this->once())->method('dismiss')
			->with('alice', self::UUID, self::REMINDER, 1750000000)->willReturn(['state' => 'dismissed']);

		$this->assertSame(['state' => 'snoozed'], $this->controller()->snooze(self::UUID, self::REMINDER)->getData());
		$this->assertSame(['state' => 'dismissed'], $this->controller()->dismiss(self::UUID, self::REMINDER)->getData());
	}

	/** Without a token there is nothing to check the write against, so it is not attempted. */
	public function testAWriteWithoutATokenIsRefused(): void {
		$this->service->expects($this->never())->method('update');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update(self::UUID, self::REMINDER)->getStatus());
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalIsTheStatusItMeans(\Exception $refusal, int $status): void {
		$this->params = ['updated_at' => '1750000000'];
		foreach (['list', 'create', 'update', 'delete', 'restore', 'snooze', 'dismiss'] as $method) {
			$this->service->method($method)->willThrowException($refusal);
		}

		$this->assertSame($status, $this->controller()->index(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->create(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->update(self::UUID, self::REMINDER)->getStatus());
		$this->assertSame($status, $this->controller()->delete(self::UUID, self::REMINDER)->getStatus());
		$this->assertSame($status, $this->controller()->restore(self::UUID, self::REMINDER)->getStatus());
		$this->assertSame($status, $this->controller()->snooze(self::UUID, self::REMINDER)->getStatus());
		$this->assertSame($status, $this->controller()->dismiss(self::UUID, self::REMINDER)->getStatus());
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'lost the race' => [new StaleUpdateException('moved'), Http::STATUS_PRECONDITION_FAILED];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('mode is a field every reminder carries'), Http::STATUS_BAD_REQUEST];
	}
}
