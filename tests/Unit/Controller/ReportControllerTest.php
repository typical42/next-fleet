<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\ReportController;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\LogbookExport;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The printable logbook. What is in it is LogbookExport's; what is tested here is that the page
 * reaches the browser as a page, and that it can reach nothing further from there.
 */
class ReportControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const PAGE = '<!DOCTYPE html><html lang="de"><body>Fahrtenbuch 2026</body></html>';

	private LogbookExport&MockObject $export;

	protected function setUp(): void {
		$this->export = $this->createMock(LogbookExport::class);
	}

	private function controller(): ReportController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReportController(Application::APP_ID, $this->createMock(IRequest::class), $this->export, $session);
	}

	public function testTheLogbookIsServedAsTheHtmlItsCountryPrinted(): void {
		$this->export->expects($this->once())
			->method('year')
			->with('alice', self::UUID, 2026)
			->willReturn(self::PAGE);

		$response = $this->controller()->logbook(self::UUID, '2026');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::PAGE, $response->render());
		// getHeaders() asks the running server for a request id; the ones the controller set are these.
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$this->assertIsArray($headers);
		$this->assertStringStartsWith('text/html', (string)($headers['Content-Type'] ?? ''));
	}

	/**
	 * The renderer promises to load nothing (tests/Country's kit); the policy is what holds when a
	 * renderer breaks that promise. Inline style is the one thing the page needs.
	 */
	public function testThePageMayLoadNothingAndRunNothing(): void {
		$this->export->method('year')->willReturn(self::PAGE);

		$policy = $this->controller()->logbook(self::UUID, '2026')->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString("default-src 'none'", $policy);
		$this->assertMatchesRegularExpression("/style-src\\s+'unsafe-inline'(;|$)/", $policy);
		$this->assertStringNotContainsString('script-src', $policy);
		$this->assertStringContainsString("frame-ancestors 'none'", $policy);
	}

	/**
	 * The page is opened by navigating to it, which carries no request token, and it writes nothing.
	 * docs/security.md: an export is rate-limited all the same.
	 */
	public function testTheRouteOpensInATabAndIsRateLimited(): void {
		$method = new \ReflectionMethod(ReportController::class, 'logbook');

		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($method->getAttributes(UserRateLimit::class));
	}

	/** @return iterable<string, array{\Throwable|null, int}> */
	public static function refusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException(''), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException(), Http::STATUS_FORBIDDEN];
		// Not an empty page that would pass for a logbook.
		yield 'a country with no export' => [null, Http::STATUS_NOT_FOUND];
	}

	/** @dataProvider refusals */
	public function testARefusalIsNoPage(?\Throwable $thrown, int $status): void {
		if ($thrown === null) {
			$this->export->method('year')->willReturn(null);
		} else {
			$this->export->method('year')->willThrowException($thrown);
		}

		$this->assertSame($status, $this->controller()->logbook(self::UUID, '2026')->getStatus());
	}

	/** @return iterable<string, array{string}> */
	public static function notAYear(): iterable {
		yield 'a word' => ['last'];
		yield 'a fraction' => ['2026.5'];
		yield 'two digits' => ['26'];
		yield 'five digits' => ['20260'];
	}

	/**
	 * A year is four digits; anything else is a request this route never handed out.
	 *
	 * @dataProvider notAYear
	 */
	public function testAnythingButAYearIsRefusedBeforeTheExportRuns(string $year): void {
		$this->export->expects($this->never())->method('year');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->logbook(self::UUID, $year)->getStatus());
	}
}
