<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\PreferencesController;
use OCA\NextFleet\Service\PreferencesService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The settings screen's two calls. Every rule is PreferencesService's, so what is tested here is
 * the translation: whose preferences these are, and what a refusal becomes.
 */
class PreferencesControllerTest extends TestCase {
	private PreferencesService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(PreferencesService::class);
	}

	private function controller(): PreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PreferencesController(Application::APP_ID, $request, $this->service, $session);
	}

	/** @return array{preferences: array{jurisdiction: string}, jurisdictions: list<array{key: string, name: string}>} */
	private function state(string $jurisdiction): array {
		return [
			'preferences' => ['jurisdiction' => $jurisdiction],
			'jurisdictions' => [['key' => 'de', 'name' => 'Germany']],
		];
	}

	/** A preference belongs to whoever is logged in, and to nobody else (docs/security.md). */
	public function testTheAnswerIsTheSessionUsers(): void {
		$this->service->expects($this->once())
			->method('forUser')
			->with('alice')
			->willReturn($this->state('de'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('de', $response->getData()['preferences']['jurisdiction']);
	}

	/**
	 * The whole payload is handed over, routing parameters and all: which of them is a preference
	 * is the service's question, not the controller's.
	 */
	public function testAWriteCarriesWhatTheScreenSent(): void {
		$this->params = ['jurisdiction' => 'generic', '_route' => 'nextfleet.preferences.update'];
		$this->service->expects($this->once())
			->method('write')
			->with('alice', $this->params)
			->willReturn($this->state('generic'));

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('generic', $response->getData()['preferences']['jurisdiction']);
	}

	/**
	 * A value the screen never offered is the request's fault, and the answer says which
	 * preference - a 500 from an uncaught exception would tell the user nothing.
	 */
	public function testAPreferenceTheAppDoesNotKeepIsABadRequest(): void {
		$this->params = ['jurisdiction' => 'zz'];
		$this->service->method('write')
			->willThrowException(new \InvalidArgumentException('jurisdiction is one of de, generic'));

		$response = $this->controller()->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'jurisdiction is one of de, generic'], $response->getData());
	}
}
