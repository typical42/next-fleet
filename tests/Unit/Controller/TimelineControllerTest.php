<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\TimelineController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\TimelineService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The vehicle's one timeline. Every rule is TimelineService's, so what is tested here is the
 * translation between a request and an answer, the way OdometerControllerTest tests the readings'.
 */
class TimelineControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

	private TimelineService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(TimelineService::class);
	}

	private function controller(): TimelineController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TimelineController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$this->service,
			$session,
		);
	}

	/**
	 * The chip and the scroll position are the query string's, and both reach the service as the
	 * client sent them - which of them mean anything is not the controller's to decide.
	 */
	public function testTheChipAndTheCursorReachTheServiceAsAsked(): void {
		$this->service->expects($this->once())
			->method('page')
			->with('alice', self::UUID, 'trip', '1750000000:trip:4')
			->willReturn(['rows' => [], 'next' => null]);

		$response = $this->controller()->index(self::UUID, 'trip', '1750000000:trip:4');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['rows' => [], 'next' => null], $response->getData());
	}

	/** An unfiltered timeline from the top: neither chip nor cursor is a parameter a client must send. */
	public function testAnUnfilteredTimelineAsksForNeither(): void {
		$this->service->expects($this->once())
			->method('page')
			->with('alice', self::UUID, null, null)
			->willReturn(['rows' => [], 'next' => null]);

		$this->controller()->index(self::UUID);
	}

	/**
	 * A chip or a cursor that arrives as an array - `?type[]=trip` - is as much a request this
	 * route never handed out as a forged word, and gets the same 400. The framework casts int,
	 * float and bool for a controller and nothing else, so a `?string` parameter would have made
	 * this a 500 and a logged exception instead.
	 *
	 * @dataProvider notEvenWords
	 * @param array<int, string> $sent
	 */
	public function testAChipOrACursorThatIsNotEvenAWordIsRefusedRatherThanFatal(array $sent, bool $asChip): void {
		$this->service->expects($this->never())->method('page');

		$response = $asChip
			? $this->controller()->index(self::UUID, $sent)
			: $this->controller()->index(self::UUID, null, $sent);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * @return iterable<string, array{array<int, string>, bool}>
	 */
	public static function notEvenWords(): iterable {
		yield 'the chip' => [['trip'], true];
		yield 'the cursor' => [['1750000000:trip:4'], false];
	}

	/**
	 * The three refusals a read can meet, each as the status the screen acts on: a uuid that is
	 * nobody's, a vehicle that is not this user's, and a chip or a cursor this route never handed
	 * out.
	 *
	 * @dataProvider refusals
	 */
	public function testARefusedTimelineAnswersWithItsOwnStatus(\Throwable $thrown, int $status): void {
		$this->service->method('page')->willThrowException($thrown);

		$this->assertSame($status, $this->controller()->index(self::UUID)->getStatus());
	}

	/**
	 * @return iterable<string, array{\Throwable, int}>
	 */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException(), Http::STATUS_FORBIDDEN];
		yield 'a chip nobody serves' => [
			new \InvalidArgumentException('type is one of odometer, trip'),
			Http::STATUS_BAD_REQUEST,
		];
	}
}
