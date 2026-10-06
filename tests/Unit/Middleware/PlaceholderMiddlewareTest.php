<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Middleware;

use OCA\NextFleet\Exception\PlaceholderException;
use OCA\NextFleet\Middleware\PlaceholderMiddleware;
use OCA\NextFleet\Tests\Stub\RoutedRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule alone, on the merged parameters Nextcloud hands over. Over HTTP, through both doors and
 * on both servers: Api\ClientTest::testABodyFieldNamedLikeAPlaceholderIsA400.
 */
class PlaceholderMiddlewareTest extends TestCase {
	private const URL = ['_route' => 'nextfleet.vehicle.update', 'uuid' => '0c5e2b1a-7a0e-4c39-9d43-3f6d2a4e8b11'];

	/** @return array<string, array{array<string, mixed>}> */
	public static function agreeing(): array {
		return [
			'the URL alone' => [[]],
			'the same value again' => [['uuid' => self::URL['uuid']]],
			'a number the URL spells' => [['_route' => self::URL['_route'], 'year' => 2026]],
		];
	}

	/** @param array<string, mixed> $body */
	#[DataProvider('agreeing')]
	public function testARequestWhoseBodyAgreesWithTheUrlPasses(array $body): void {
		$this->expectNotToPerformAssertions();

		$this->middleware($body, self::URL + ['year' => '2026'])->beforeController($this->createMock(Controller::class), 'update');
	}

	/** @return array<string, array{mixed}> */
	public static function disagreeing(): array {
		return [
			'another vehicle' => ['3f6d2a4e-7a0e-4c39-9d43-0c5e2b1a8b11'],
			'a list' => [[]],
			'null' => [null],
		];
	}

	#[DataProvider('disagreeing')]
	public function testABodyThatNamesAPlaceholderOtherwiseIsA400(mixed $uuid): void {
		$middleware = $this->middleware(['uuid' => $uuid], self::URL);
		$controller = $this->createMock(Controller::class);

		try {
			$middleware->beforeController($controller, 'update');
			$this->fail('passed');
		} catch (PlaceholderException $e) {
			$answer = $middleware->afterException($controller, 'update', $e);
		}

		$this->assertInstanceOf(DataResponse::class, $answer);
		$this->assertSame(400, $answer->getStatus());
		$this->assertSame(['message' => 'uuid is the one in the URL'], $answer->getData());
	}

	public function testAnyOtherExceptionGoesOn(): void {
		$thrown = new \RuntimeException('not ours');

		$this->expectExceptionObject($thrown);
		$this->middleware([], self::URL)->afterException($this->createMock(Controller::class), 'update', $thrown);
	}

	/**
	 * @param array<string, mixed> $body what the request's body says
	 * @param array<string, string> $url the route's placeholders
	 */
	private function middleware(array $body, array $url): PlaceholderMiddleware {
		$request = $this->createMock(RoutedRequest::class);
		$request->urlParams = $url;
		// Nextcloud's merge: the body last.
		$merged = array_merge($url, $body);
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => $merged[$name] ?? null);

		return new PlaceholderMiddleware($request);
	}
}
