<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The Fahrtenbuch export against the real database, written the way the app writes it: trips
 * through TripService, the mode flipped through VehicleService. What the unit tests take on trust
 * is what this checks - that the year's query selects voided trips, and that the flips come back
 * off the trail as the periods.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class LogbookExportTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';

	private LogbookExport $export;
	private TripService $trips;
	private VehicleService $vehicles;
	/** The year the server is in, so the flips written now fall into the year exported. */
	private int $year;
	private int $now;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->export = $container->get(LogbookExport::class);
		$this->trips = $container->get(TripService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->now = $container->get(ITimeFactory::class)->getTime();
		$this->year = (int)gmdate('Y', $this->now);

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
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::AUTHOR)));
			$qb->executeStatement();
		}
	}

	/** @param array<string, mixed> $fields */
	private function trip(string $uuid, int $startedAt, array $fields): Trip {
		return $this->trips->record(self::AUTHOR, $uuid, $fields + [
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 5400,
			'ended_at_off' => 60,
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);
	}

	private function switchMode(string $uuid, bool $on): void {
		$vehicle = $this->vehicles->find(self::AUTHOR, $uuid);
		$this->vehicles->update(self::AUTHOR, $uuid, $vehicle->getUpdatedAt(), ['logbook_mode' => $on]);
	}

	/** @return array{list<string>, \DOMXPath} one export's trip lines, whitespace collapsed, and its page */
	private function printed(string $uuid, int $year): array {
		$html = $this->export->year(self::AUTHOR, $uuid, $year);

		$this->assertNotNull($html);
		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));
		$page = new \DOMXPath($document);

		$lines = [];
		foreach ($page->query('//table/tbody/tr') ?: [] as $row) {
			$lines[] = trim((string)preg_replace('/\s+/u', ' ', $row->textContent));
		}

		return [$lines, $page];
	}

	/**
	 * The PRD's sentence against the instance: the year's trips with the voided one listed as
	 * voided, last year's left out, the period the mode was on stated, and the requirement cited.
	 * The flips are written now and the trips in January, so each trip set off outside the period
	 * and none is marked incomplete, not even the one without a partner.
	 */
	public function testTheYearsLogbookListsWhatHappenedInItAsItHappened(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 127', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);

		$january = gmmktime(8, 0, 0, 1, 10, $this->year);
		$this->trip($uuid, gmmktime(8, 0, 0, 12, 20, $this->year - 1), ['start_odo' => 119000, 'end_odo' => 119500, 'purpose' => 'Last year']);
		$this->trip($uuid, $january, ['start_odo' => 120000, 'end_odo' => 120450]);
		$voided = $this->trip($uuid, $january + 86400, ['start_odo' => 120450, 'end_odo' => 120900, 'purpose' => 'Voided']);
		$this->trips->delete(self::AUTHOR, $uuid, $voided->getUuid(), $voided->getUpdatedAt());
		$this->trip($uuid, $january + 2 * 86400, ['start_odo' => 120450, 'end_odo' => 120700, 'partner' => null]);

		$this->switchMode($uuid, false);

		[$lines, $page] = $this->printed($uuid, $this->year);

		$this->assertCount(3, $lines);
		$this->assertStringNotContainsString('Last year', implode(' ', $lines));
		// Late unless today is within a week of that January trip.
		$this->assertMatchesRegularExpression('/(Nachträglich s|S)torniert am/u', $lines[1]);
		$this->assertStringContainsString('Voided', $lines[1]);
		$this->assertStringNotContainsString('Unvollständig', implode(' ', $lines));

		$periods = array_map(
			static fn (\DOMNode $item): string => $item->textContent,
			iterator_to_array($page->query('//section[@id="modus"]//li') ?: []),
		);
		$this->assertCount(1, $periods);
		$this->assertStringContainsString('bis zum', $periods[0]);

		$this->assertStringStartsWith('https://', (string)$page->evaluate('string(//footer//a/@href)'));
	}

	/**
	 * A country nobody has written prints a plain listing (the generic profile), built by the app's
	 * container with the reader's `IL10N`. Nextcloud's formatter has to read the trip's own offset:
	 * half past midnight on New Year's Day in Berlin is 23:30 UTC the day before.
	 */
	public function testAGenericVehiclePrintsAPlainLogbookOnItsOwnWallClock(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 133', 'jurisdiction' => 'generic', 'currency' => 'EUR'])->getUuid();
		$this->trip($uuid, gmmktime(23, 30, 0, 12, 31, $this->year - 1), ['start_odo' => 120000, 'end_odo' => 120450, 'purpose' => 'New Year']);

		[$lines, $page] = $this->printed($uuid, $this->year);

		$l = (new Application())->getContainer()->get(IL10N::class);
		$local = new \DateTime(sprintf('%d-01-01T00:30:00+01:00', $this->year));
		$this->assertCount(1, $lines);
		$this->assertStringContainsString('New Year', $lines[0]);
		$this->assertStringStartsWith(
			// The row's text runs its cells together, whitespace collapsed as `printed()` does: the
			// date, then the time the trip set off.
			preg_replace('/\s+/u', ' ', $l->l('date', $local, ['width' => 'medium']) . $l->l('time', $local, ['width' => 'short']) . '–'),
			$lines[0],
		);
		$this->assertSame(0.0, $page->evaluate('count(//section[@id="modus"])'), 'no country, so no Logbook Mode section');
	}

	/**
	 * A trip edited past the lock delay shows the change on its line, read off the trail the edit
	 * wrote. A month back is past Germany's seven days whatever today's date is.
	 */
	public function testALateEditShowsOnItsLine(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 129', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);
		$monthAgo = $this->now - 30 * 86400;
		$old = $this->trip($uuid, $monthAgo, ['start_odo' => 120000, 'end_odo' => 120450]);
		$this->trips->update(self::AUTHOR, $uuid, $old->getUuid(), $old->getUpdatedAt(), [
			'started_at' => $old->getStartedAt(),
			'started_at_off' => 60,
			'ended_at' => $old->getEndedAt(),
			'ended_at_off' => 60,
			'start_odo' => 120000,
			'end_odo' => 120450,
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Besuch',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);

		[$lines] = $this->printed($uuid, (int)gmdate('Y', $monthAgo + 3600));

		$this->assertCount(1, $lines);
		$this->assertMatchesRegularExpression('/Nachträglich geändert am \d\d\.\d\d\.\d{4}, \d\d:\d\d UTC\. Vorher: Zweck „Abnahme“$/u', $lines[0]);
	}

	/**
	 * A trip voided and restored past the lock delay says both on its line, the restore with when
	 * the trip had been voided - read off the trail the two writes left.
	 */
	public function testALateVoidAndRestoreShowOnTheLine(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 130', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);
		$monthAgo = $this->now - 30 * 86400;
		$old = $this->trip($uuid, $monthAgo, ['start_odo' => 120000, 'end_odo' => 120450]);
		$voided = $this->trips->delete(self::AUTHOR, $uuid, $old->getUuid(), $old->getUpdatedAt());
		$this->trips->restore(self::AUTHOR, $uuid, $old->getUuid(), $voided->getUpdatedAt());

		[$lines] = $this->printed($uuid, (int)gmdate('Y', $monthAgo + 3600));

		$this->assertCount(1, $lines);
		$at = '\d\d\.\d\d\.\d{4}, \d\d:\d\d UTC';
		$this->assertMatchesRegularExpression(
			"/Nachträglich storniert am {$at}Nachträglich wiederhergestellt am $at\. Vorher: storniert am $at$/u",
			$lines[0],
		);
	}

	/** A trip set off while the mode is on is marked with what it lacks: next January is under it. */
	public function testATripSetOffUnderTheModeIsMarkedIncomplete(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 128', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);
		$this->trip($uuid, gmmktime(8, 0, 0, 1, 10, $this->year + 1), ['start_odo' => 120000, 'end_odo' => 120450, 'partner' => null]);

		[$lines] = $this->printed($uuid, $this->year + 1);

		$this->assertCount(1, $lines);
		$this->assertStringContainsString('Unvollständig, es fehlt: Geschäftspartner', $lines[0]);
	}
}
