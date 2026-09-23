<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The mileage claim against the real database and the real German profile, trips written the way
 * the app writes them. What the unit test takes on trust is what this checks: that the year's
 * query, the voiding and the rate table meet on one page.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class MileageClaimTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';

	private MileageClaimExport $claim;
	private TripService $trips;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->claim = $container->get(MileageClaimExport::class);
		$this->trips = $container->get(TripService::class);
		$this->vehicles = $container->get(VehicleService::class);

		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_trips' => 'created_by', 'fleet_audit' => 'created_by', 'fleet_odo_readings' => 'created_by', 'fleet_vehicles' => 'user_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::AUTHOR)));
			$qb->executeStatement();
		}
	}

	/** @param array<string, mixed> $fields */
	private function trip(string $vehicle, int $startedAt, array $fields): Trip {
		return $this->trips->record(self::AUTHOR, $vehicle, $fields + [
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 60,
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
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
	 * The PRD's sentence against the instance: the year's business trips at 30 ct, the commute and
	 * the voided trip left off, the sum beneath and the statute cited. 2024, so the rate is the
	 * one of the trips' year and not today's.
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

	/** Under `generic` there is no rate, so there is no claim - not one of 0,00 €. */
	public function testAGenericVehicleHasNoClaim(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 132', 'jurisdiction' => 'generic', 'currency' => 'EUR'])->getUuid();
		$this->trip($uuid, gmmktime(7, 0, 0, 3, 4, 2024), ['start_odo' => 1000, 'end_odo' => 1120]);

		$this->assertNull($this->claim->year(self::AUTHOR, $uuid, 2024));
	}
}
