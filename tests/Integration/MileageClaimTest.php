<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The mileage claim against the real database and the real German profile: the year's query, the
 * voiding and the rate table meet on one page.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class MileageClaimTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';
	/** An account, since a grantee has to exist on the instance. */
	private const DRIVER = 'nextfleet-test-claim-ben';

	private MileageClaimExport $claim;
	private TripService $trips;
	private VehicleService $vehicles;
	private GrantService $grants;

	public static function setUpBeforeClass(): void {
		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::DRIVER)?->delete();
		$users->createUser(self::DRIVER, bin2hex(random_bytes(16)));
	}

	public static function tearDownAfterClass(): void {
		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();
	}

	protected function setUp(): void {
		$this->claim = \OCP\Server::get(MileageClaimExport::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->grants = \OCP\Server::get(GrantService::class);

		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$tables = [
			'fleet_trips' => 'created_by',
			'fleet_audit' => 'created_by',
			'fleet_odo_readings' => 'created_by',
			'fleet_access' => 'created_by',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter([self::AUTHOR, self::DRIVER], $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** @param array<string, mixed> $fields */
	private function trip(string $vehicle, int $startedAt, array $fields, string $author = self::AUTHOR): Trip {
		return $this->trips->record($author, $vehicle, $fields + [
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 60,
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);
	}

	/** @return list<string> each body row's text, whitespace collapsed */
	private function lines(\DOMXPath $page): array {
		$lines = [];
		foreach ($page->query('//table/tbody/tr') ?: [] as $row) {
			$lines[] = trim((string)preg_replace('/\s+/u', ' ', $row->textContent));
		}

		return $lines;
	}

	/**
	 * The year's business trips at 30 ct, the commute and the voided trip left off, the sum beneath
	 * and the statute cited. 2024, so the rate is that of the trips' year, not today's.
	 */
	public function testAGermanCarsBusinessTripsAreValuedAtTheStatutoryRate(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 131', 'jurisdiction' => 'de', 'vehicle_type' => 'car'])->getUuid();
		$day = gmmktime(7, 0, 0, 3, 4, 2024);
		// Last year's, which a claim for 2024 does not reach.
		$this->trip($uuid, gmmktime(7, 0, 0, 12, 30, 2023), ['start_odo' => 901, 'end_odo' => 1000, 'purpose' => 'Vorjahr']);
		$this->trip($uuid, $day, ['start_odo' => 1000, 'end_odo' => 1120, 'purpose' => 'Kunde A']);
		$this->trip($uuid, $day + 86400, ['start_odo' => 1120, 'end_odo' => 1150, 'category' => Trip::COMMUTE, 'purpose' => 'Pendeln']);
		$voided = $this->trip($uuid, $day + 2 * 86400, ['start_odo' => 1150, 'end_odo' => 1200, 'purpose' => 'Storniert']);
		$this->trips->delete(self::AUTHOR, $uuid, $voided->getUuid(), $voided->getUpdatedAt());
		$this->trip($uuid, $day + 3 * 86400, ['start_odo' => 1200, 'end_odo' => 1233, 'purpose' => 'Kunde B']);

		$html = $this->claim->year(self::AUTHOR, $uuid, 2024);

		$this->assertNotNull($html);
		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));
		$page = new \DOMXPath($document);
		$lines = $this->lines($page);

		$this->assertCount(2, $lines);
		$this->assertStringContainsString('Kunde A', $lines[0]);
		$this->assertStringContainsString('36,00 €', $lines[0]);
		$this->assertStringContainsString('Kunde B', $lines[1]);
		$this->assertStringContainsString('9,90 €', $lines[1]);
		$this->assertStringContainsString('45,90 €', (string)$page->evaluate('string(//table/tfoot)'));
		$this->assertSame('https://www.gesetze-im-internet.de/estg/__9.html', (string)$page->evaluate('string(//footer//a/@href)'));
	}

	/**
	 * A business trip missing what the German ruleset requires is not summed, Fahrtenbuch or not:
	 * the Finanzamt asks the same of a claim.
	 */
	public function testAnIncompleteTripIsListedButNotSummed(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 132', 'jurisdiction' => 'de', 'vehicle_type' => 'car'])->getUuid();
		$day = gmmktime(7, 0, 0, 3, 4, 2024);
		$this->trip($uuid, $day, ['start_odo' => 1000, 'end_odo' => 1120, 'purpose' => 'Kunde A']);
		$this->trip($uuid, $day + 86400, ['start_odo' => 1120, 'end_odo' => 1150, 'purpose' => 'Kunde B', 'partner' => null]);

		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML((string)$this->claim->year(self::AUTHOR, $uuid, 2024), LIBXML_NOERROR));
		$page = new \DOMXPath($document);
		$lines = $this->lines($page);

		$this->assertCount(2, $lines);
		$this->assertStringContainsString('(9,00 €) fehlt: Geschäftspartner', $lines[1]);
		$this->assertStringContainsString('36,00 €', (string)$page->evaluate('string(//table/tfoot)'));
	}

	/** On a shared car each reader's claim holds the business trips they entered, and says so. */
	public function testEachReadersClaimHoldsTheTripsTheyEntered(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 133', 'jurisdiction' => 'de', 'vehicle_type' => 'car'])->getUuid();
		$this->grants->grant(self::AUTHOR, $uuid, ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$day = gmmktime(7, 0, 0, 3, 4, 2024);
		$this->trip($uuid, $day, ['start_odo' => 1000, 'end_odo' => 1120, 'purpose' => 'Kunde A']);
		$this->trip($uuid, $day + 86400, ['start_odo' => 1120, 'end_odo' => 1150, 'purpose' => 'Kunde B'], self::DRIVER);

		$claims = [];
		foreach ([self::AUTHOR, self::DRIVER] as $reader) {
			$document = new \DOMDocument();
			$this->assertTrue($document->loadHTML((string)$this->claim->year($reader, $uuid, 2024), LIBXML_NOERROR));
			$page = new \DOMXPath($document);
			$this->assertStringContainsString('Nur Fahrten, die Sie eingetragen haben.', (string)$page->evaluate('string(//header)'));
			$claims[$reader] = $this->lines($page);
		}

		$this->assertCount(1, $claims[self::AUTHOR]);
		$this->assertStringContainsString('Kunde A', $claims[self::AUTHOR][0]);
		$this->assertCount(1, $claims[self::DRIVER]);
		$this->assertStringContainsString('Kunde B', $claims[self::DRIVER][0]);
	}

	/** Under `generic` there is no rate, so there is no claim - not one of 0,00 €. */
	public function testAGenericVehicleHasNoClaim(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 132', 'jurisdiction' => 'generic', 'currency' => 'EUR'])->getUuid();
		$this->trip($uuid, gmmktime(7, 0, 0, 3, 4, 2024), ['start_odo' => 1000, 'end_odo' => 1120]);

		$this->assertNull($this->claim->year(self::AUTHOR, $uuid, 2024));
	}
}
