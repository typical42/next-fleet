<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\ImportController;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An import: the translation between a request and an answer, as EnergyControllerTest tests it.
 * The rules are ImportService's (tests/Integration/ImportTest.php).
 */
class ImportControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';

	private ImportService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(ImportService::class);
	}

	private function controller(): ImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ImportController(Application::APP_ID, $request, $this->service, $session);
	}

	public function testThePreviewIsTheServicesForTheRequestAsSent(): void {
		$this->params = ['uuid' => self::UUID, 'file_id' => 42, 'importer' => 'lubelogger', 'units' => ['distance' => 'km']];
		$preview = ['importer' => 'lubelogger', 'etag' => 'abc'];
		$this->service->expects($this->once())->method('preview')->with('alice', self::UUID, $this->params)->willReturn($preview);

		$response = $this->controller()->preview(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($preview, $response->getData());
	}

	public function testTheImportIsTheServicesForTheRequestAsSent(): void {
		$this->params = ['uuid' => self::UUID, 'file_id' => 42, 'importer' => 'lubelogger', 'etag' => 'abc'];
		$result = ['counts' => ['new' => 1, 'duplicate' => 0, 'unreadable' => 0, 'creates' => 1], 'created' => [['type' => 'energy', 'uuid' => 'e']]];
		$this->service->expects($this->once())->method('import')->with('alice', self::UUID, $this->params)->willReturn($result);

		$response = $this->controller()->import(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	/** The screen previews again on a 409; the preview's counts no longer describe the file. */
	public function testAFileChangedSinceThePreviewIsAConflict(): void {
		$this->service->method('import')->willThrowException(new FileChangedException('changed'));

		$response = $this->controller()->import(self::UUID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertIsString($response->getData()['message']);
	}

	public function testTheUndoIsTheServicesForTheRequestAsSent(): void {
		$this->params = ['uuid' => self::UUID, 'created' => [['type' => 'energy', 'uuid' => 'e']]];
		$this->service->expects($this->once())->method('undo')->with('alice', self::UUID, $this->params)->willReturn(['undone' => 1]);

		$response = $this->controller()->undo(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['undone' => 1], $response->getData());
	}

	/** An entry deleted since, or not the caller's: the screen says so, and nothing was taken back. */
	public function testAnUndoNamingAnEntryTheImportDidNotLeaveIsAConflict(): void {
		$this->service->method('undo')->willThrowException(new ImportChangedException('energy e is not a live entry of the caller\'s on this vehicle'));

		$response = $this->controller()->undo(self::UUID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertIsString($response->getData()['message']);
	}

	/** The reason is a word the screen puts into words, and the row is where reading stopped. */
	public function testAFileTheImportWillNotReadIsUnprocessableWithItsReason(): void {
		$this->service->method('preview')->willThrowException(new ImportRefusedException('binary', 3));

		$response = $this->controller()->preview(self::UUID);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['reason' => 'binary', 'row' => 3], array_intersect_key($response->getData(), ['reason' => 0, 'row' => 0]));
		$this->assertIsString($response->getData()['message']);
	}
}
