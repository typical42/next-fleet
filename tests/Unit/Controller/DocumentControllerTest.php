<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\DocumentController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\DocumentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle's papers: the translation between a request and an answer, as EnergyControllerTest
 * tests it. The rules are DocumentService's (tests/Integration/DocumentTest.php).
 */
class DocumentControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const DOCUMENT = '0195e2f1-0000-4000-8000-000000000002';
	private const LISTED = [['uuid' => self::DOCUMENT, 'kind' => 'manual']];

	private DocumentService&MockObject $service;
	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->service = $this->createMock(DocumentService::class);
	}

	private function controller(): DocumentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DocumentController(Application::APP_ID, $request, $this->service, $session);
	}

	/** Each route answers 200 with the list as it now stands. */
	public function testEachRouteAnswersWithTheList(): void {
		$this->params = ['uuid' => self::UUID, 'file_id' => '42', 'kind' => 'manual'];
		$this->service->expects($this->once())->method('list')->with('alice', self::UUID)->willReturn(self::LISTED);
		$this->service->expects($this->once())->method('attach')->with('alice', self::UUID, $this->params)->willReturn(self::LISTED);
		$this->service->expects($this->once())->method('detach')->with('alice', self::UUID, self::DOCUMENT)->willReturn([]);

		foreach ([
			[$this->controller()->index(self::UUID), self::LISTED],
			[$this->controller()->create(self::UUID), self::LISTED],
			[$this->controller()->delete(self::UUID, self::DOCUMENT), []],
		] as [$response, $data]) {
			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertSame($data, $response->getData());
		}
	}

	/**
	 * A paper leaves as an attachment and is never rendered on our origin: a receipt can be an SVG,
	 * and an SVG shown inline from here is script (docs/security.md#hostile-content).
	 */
	public function testAPaperIsServedAsAnAttachmentThatRunsNothing(): void {
		$this->service->expects($this->once())->method('download')->with('alice', self::UUID, self::DOCUMENT)
			->willReturn($this->file('Beleg.svg', 'image/svg+xml', '<svg onload="alert(1)"/>'));

		$response = $this->controller()->download(self::UUID, self::DOCUMENT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = self::headers($response);
		$this->assertSame('attachment; filename="Beleg.svg"; filename*=UTF-8\'\'Beleg.svg', $headers['Content-Disposition']);
		$this->assertSame('image/svg+xml', $headers['Content-Type']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('24', $headers['Content-Length']);
		$this->assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());
		$this->assertSame('<svg onload="alert(1)"/>', $this->body($response));
	}

	/**
	 * A name is whatever somebody typed in Files. The quoted fallback keeps to ASCII a header can
	 * carry, and the UTF-8 form beside it is what a browser saves the file as.
	 */
	public function testANameIsQuotedSoItCannotBreakTheHeader(): void {
		$this->service->method('download')->willReturn($this->file('Prüfbericht "HU" \\ 2026.pdf', 'application/pdf', '%PDF'));

		$this->assertSame(
			'attachment; filename="Pr_fbericht _HU_ _ 2026.pdf"; filename*=UTF-8\'\'Pr%C3%BCfbericht%20%22HU%22%20%5C%202026.pdf',
			self::headers($this->controller()->download(self::UUID, self::DOCUMENT))['Content-Disposition'],
		);
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalIsTheStatusItMeans(\Exception $refusal, int $status): void {
		foreach (['list', 'attach', 'detach', 'download'] as $method) {
			$this->service->method($method)->willThrowException($refusal);
		}

		$this->assertSame($status, $this->controller()->index(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->create(self::UUID)->getStatus());
		$this->assertSame($status, $this->controller()->delete(self::UUID, self::DOCUMENT)->getStatus());
		if ($status !== Http::STATUS_BAD_REQUEST) {
			$this->assertSame($status, $this->controller()->download(self::UUID, self::DOCUMENT)->getStatus());
		}
	}

	/** Found in the database, gone from the storage by the time it is opened: still not a 500. */
	public function testAFileThatCannotBeOpenedIsNotFound(): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willThrowException(new NotFoundException('gone'));
		$this->service->method('download')->willReturn($file);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->download(self::UUID, self::DOCUMENT)->getStatus());
	}

	/** An empty file is still a file. Nextcloud's stream answers 400 for a copy of no bytes. */
	public function testAnEmptyPaperIsServedAsOne(): void {
		$this->service->method('download')->willReturn($this->file('Leer.txt', 'text/plain', ''));

		$response = $this->controller()->download(self::UUID, self::DOCUMENT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('attachment; filename="Leer.txt"; filename*=UTF-8\'\'Leer.txt', self::headers($response)['Content-Disposition']);
		$this->assertSame('0', self::headers($response)['Content-Length']);
		$this->assertSame('', $this->body($response));
		$this->assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());
	}

	/** The owner's client is writing it right now: a moment later it works, which a 500 does not say. */
	public function testAFileBeingWrittenIsLocked(): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willThrowException(new LockedException('Beleg.pdf'));
		$this->service->method('download')->willReturn($file);

		$this->assertSame(Http::STATUS_LOCKED, $this->controller()->download(self::UUID, self::DOCUMENT)->getStatus());
	}

	private function file(string $name, string $mime, string $content): File {
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $content);
		rewind($stream);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('fopen')->with('rb')->willReturn($stream);

		return $file;
	}

	/**
	 * getHeaders() asks the running server for a request id; the ones the controller set are these.
	 *
	 * @return array<string, mixed>
	 */
	private static function headers(Response $response): array {
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
		self::assertIsArray($headers);

		return $headers;
	}

	/**
	 * What the framework would send, by running the response's own callback against an output that
	 * answers as Nextcloud's does: a stream that copied no bytes is a failure.
	 */
	private function body(Response $response): string {
		if (!$response instanceof ICallbackResponse) {
			return (string)$response->render();
		}
		$sent = '';
		$output = $this->createMock(IOutput::class);
		$output->method('getHttpResponseCode')->willReturn(Http::STATUS_OK);
		$output->method('setReadfile')->willReturnCallback(function ($stream) use (&$sent): bool {
			$sent = stream_get_contents($stream);

			return $sent !== '';
		});
		$output->expects($this->never())->method('setHttpResponseCode');
		$response->callback($output);

		return $sent;
	}

	/** @return iterable<string, array{\Exception, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle, file or entry' => [new DoesNotExistException('gone'), Http::STATUS_NOT_FOUND];
		yield 'not theirs' => [new AccessDeniedException('no'), Http::STATUS_FORBIDDEN];
		yield 'a field its column cannot hold' => [new \InvalidArgumentException('kind is one of …'), Http::STATUS_BAD_REQUEST];
	}
}
