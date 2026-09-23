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
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\MileageClaimExport;
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
 * The printable logbook and mileage claim, and the CSV files. What is in them is the services';
 * what is tested here is that a page reaches the browser as a page and can reach nothing further
 * from there, and that a file is a download.
 */
class ReportControllerTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const PAGE = '<!DOCTYPE html><html lang="de"><body>Fahrtenbuch 2026</body></html>';

	private LogbookExport&MockObject $export;
	private MileageClaimExport&MockObject $claim;
	private ExportService&MockObject $csv;

	protected function setUp(): void {
		$this->export = $this->createMock(LogbookExport::class);
		$this->claim = $this->createMock(MileageClaimExport::class);
		$this->csv = $this->createMock(ExportService::class);
	}

	private function controller(): ReportController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReportController(Application::APP_ID, $this->createMock(IRequest::class), $this->export, $this->claim, $this->csv, $session);
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

	/** @return iterable<string, array{string}> each printable page, by its action */
	public static function pages(): iterable {
		yield 'the logbook' => ['logbook'];
		yield 'the mileage claim' => ['mileage'];
	}

	/** The service behind a page. */
	private function printer(string $page): MockObject {
		return $page === 'logbook' ? $this->export : $this->claim;
	}

	private function open(string $page, string $year): Response {
		return $page === 'logbook'
			? $this->controller()->logbook(self::UUID, $year)
			: $this->controller()->mileage(self::UUID, $year);
	}

	/** @dataProvider pages */
	public function testAPageIsServedAsTheHtmlItsCountryPrinted(string $page): void {
		$this->printer($page)->expects($this->once())
			->method('year')
			->with('alice', self::UUID, 2026)
			->willReturn(self::PAGE);

		$response = $this->open($page, '2026');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::PAGE, $response->render());
		$headers = self::headers($response);
		$this->assertStringStartsWith('text/html', (string)($headers['Content-Type'] ?? ''));
	}

	/**
	 * The renderer promises to load nothing (tests/Country's kit); the policy is what holds when a
	 * renderer breaks that promise. Inline style is the one thing the page needs.
	 *
	 * @dataProvider pages
	 */
	public function testThePageMayLoadNothingAndRunNothing(string $page): void {
		$this->printer($page)->method('year')->willReturn(self::PAGE);

		$policy = $this->open($page, '2026')->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString("default-src 'none'", $policy);
		$this->assertMatchesRegularExpression("/style-src\\s+'unsafe-inline'(;|$)/", $policy);
		$this->assertStringNotContainsString('script-src', $policy);
		$this->assertStringContainsString("frame-ancestors 'none'", $policy);
	}

	/**
	 * The page is opened by navigating to it, which carries no request token, and it writes nothing.
	 * docs/security.md: an export is rate-limited all the same.
	 *
	 * @dataProvider pages
	 */
	public function testTheRouteOpensInATabAndIsRateLimited(string $page): void {
		$method = new \ReflectionMethod(ReportController::class, $page);

		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($method->getAttributes(UserRateLimit::class));
	}

	/** @return iterable<string, array{string, \Throwable|null, int}> */
	public static function refusals(): iterable {
		foreach (self::pages() as $name => [$page]) {
			yield $name . ', no such vehicle' => [$page, new DoesNotExistException(''), Http::STATUS_NOT_FOUND];
			yield $name . ', not yours' => [$page, new AccessDeniedException(), Http::STATUS_FORBIDDEN];
			// Not an empty page that would pass for one.
			yield $name . ', a country with none' => [$page, null, Http::STATUS_NOT_FOUND];
		}
	}

	/** @dataProvider refusals */
	public function testARefusalIsNoPage(string $page, ?\Throwable $thrown, int $status): void {
		if ($thrown === null) {
			$this->printer($page)->method('year')->willReturn(null);
		} else {
			$this->printer($page)->method('year')->willThrowException($thrown);
		}

		$this->assertSame($status, $this->open($page, '2026')->getStatus());
	}

	/** @return iterable<string, array{string, string}> */
	public static function pagesAndNotAYear(): iterable {
		foreach (self::pages() as $name => [$page]) {
			foreach (self::notAYear() as $case => [$year]) {
				yield $name . ', ' . $case => [$page, $year];
			}
		}
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
	 * @dataProvider pagesAndNotAYear
	 */
	public function testAnythingButAYearIsRefusedBeforeTheExportRuns(string $page, string $year): void {
		$this->printer($page)->expects($this->never())->method('year');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->open($page, $year)->getStatus());
	}

	/** A file to save, not a page to show: whatever is in a cell never reaches the browser as markup. */
	public function testACsvIsADownloadNamedAfterTheVehicle(): void {
		$this->csv->expects($this->once())
			->method('csv')
			->with('alice', self::UUID, 2026, 'trips')
			->willReturn(['name' => 'B-XY_127-2026-trips.csv', 'body' => "\u{FEFF}uuid\r\n"]);

		$response = $this->controller()->csv(self::UUID, '2026', 'trips');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame("\u{FEFF}uuid\r\n", $response->render());
		$headers = self::headers($response);
		$this->assertSame('attachment; filename="B-XY_127-2026-trips.csv"', $headers['Content-Disposition'] ?? null);
		$this->assertStringStartsWith('text/csv', (string)($headers['Content-Type'] ?? ''));
	}

	/** The link is followed, so it carries no token; a bulk read is rate-limited like every export. */
	public function testTheCsvRouteIsFollowedAndRateLimited(): void {
		$method = new \ReflectionMethod(ReportController::class, 'csv');

		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($method->getAttributes(UserRateLimit::class));
	}

	/** @return iterable<string, array{\Throwable, int}> */
	public static function csvRefusals(): iterable {
		yield 'no such vehicle' => [new DoesNotExistException(''), Http::STATUS_NOT_FOUND];
		yield 'not yours' => [new AccessDeniedException(), Http::STATUS_FORBIDDEN];
		yield 'no such table' => [new \InvalidArgumentException(''), Http::STATUS_NOT_FOUND];
	}

	/** @dataProvider csvRefusals */
	public function testARefusalIsNoFile(\Throwable $thrown, int $status): void {
		$this->csv->method('csv')->willThrowException($thrown);

		$this->assertSame($status, $this->controller()->csv(self::UUID, '2026', 'trips')->getStatus());
	}

	/** @dataProvider notAYear */
	public function testAnythingButAYearIsNoFile(string $year): void {
		$this->csv->expects($this->never())->method('csv');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->csv(self::UUID, $year, 'trips')->getStatus());
	}
}
