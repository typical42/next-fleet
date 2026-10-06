<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * The Fahrtenbuch export against the real database, trips and mode flips written through the
 * services. It checks what the unit tests take on trust: that the year's query selects voided
 * trips, and that the flips come back off the trail as the periods.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class LogbookExportTest extends TestCase {
	/** Not a Nextcloud account: `created_by` is a string column with no key on it. */
	private const AUTHOR = 'nextfleet-test-alice';
	/** An account, since a grantee has to exist on the instance. */
	private const DRIVER = 'nextfleet-test-logbook-ben';

	private LogbookExport $export;
	private TripService $trips;
	private VehicleService $vehicles;
	private GrantService $grants;
	/** The year the server is in, so the flips written now fall into the year exported. */
	private int $year;
	private int $now;

	public static function setUpBeforeClass(): void {
		$users = \OCP\Server::get(IUserManager::class);
		$users->get(self::DRIVER)?->delete();
		$users->createUser(self::DRIVER, bin2hex(random_bytes(16)))?->setDisplayName('Ben Fahrer');
	}

	public static function tearDownAfterClass(): void {
		\OCP\Server::get(IUserManager::class)->get(self::DRIVER)?->delete();
	}

	protected function setUp(): void {
		$this->export = \OCP\Server::get(LogbookExport::class);
		$this->trips = \OCP\Server::get(TripService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->now = \OCP\Server::get(ITimeFactory::class)->getTime();
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
	 * The year's trips with the voided one listed as voided, last year's left out, the period the
	 * mode was on stated, and the requirement cited. The flips are written now and the trips in
	 * January, so each trip set off outside the period and none is marked incomplete, not even the
	 * one without a partner.
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
		$this->assertMatchesRegularExpression('/(Nachträglich a|A)nnulliert am/u', $lines[1]);
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

		// The app's own, as its container hands one to the export.
		$l = \OCP\Server::get(IFactory::class)->get(Application::APP_ID);
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
		$this->assertMatchesRegularExpression('/Nachträglich geändert am \d\d\.\d\d\.\d{4}, \d\d:\d\d UTC[+−]\d\d:\d\d\. Vorher: Zweck „Abnahme“$/u', $lines[0]);
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
		$at = '\d\d\.\d\d\.\d{4}, \d\d:\d\d UTC[+−]\d\d:\d\d';
		$this->assertMatchesRegularExpression(
			"/Nachträglich annulliert am {$at}Nachträglich wiederhergestellt am $at\. Vorher: annulliert am $at$/u",
			$lines[0],
		);
	}

	/**
	 * Late is judged at export: a far-future `ended_at` stopped the clock the void stored its flag
	 * by, and the trip's own `created_at` restarts it. The stored flag says no; the line says late.
	 */
	public function testAVoidOfATripEndingFarAheadIsLateFromWhenTheTripWasEntered(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 138', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);
		$monthAgo = $this->now - 30 * 86400;
		$trip = $this->trip($uuid, $monthAgo, ['start_odo' => 120000, 'end_odo' => 120450]);
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_trips')
			->set('ended_at', $qb->createNamedParameter($this->now + 365 * 86400, $qb::PARAM_INT))
			->set('created_at', $qb->createNamedParameter($monthAgo, $qb::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trip->getId(), $qb::PARAM_INT)));
		$qb->executeStatement();
		$this->trips->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());

		[$lines] = $this->printed($uuid, (int)gmdate('Y', $monthAgo + 3600));

		$this->assertCount(1, $lines);
		$this->assertStringContainsString('Nachträglich annulliert am', $lines[0]);
	}

	/**
	 * The stored flag is not trusted either way: a void inside the delay is on time whatever its
	 * row says.
	 */
	public function testAVoidInsideTheDelayIsOnTimeWhateverItsRowSays(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 139', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$this->switchMode($uuid, true);
		$trip = $this->trip($uuid, $this->now - 7200, ['start_odo' => 120000, 'end_odo' => 120450]);
		$this->trips->delete(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt());
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_audit')
			->set('diff_json', $qb->createNamedParameter(json_encode(['change' => 'voided', 'fields' => ['deleted_at' => [null, $this->now]], 'late' => true])))
			->where($qb->expr()->eq('entity', $qb->createNamedParameter('trip')))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($trip->getId(), $qb::PARAM_INT)))
			->andWhere($qb->expr()->like('diff_json', $qb->createNamedParameter('%voided%')));
		$this->assertSame(1, $qb->executeStatement());

		[$lines] = $this->printed($uuid, (int)gmdate('Y', $this->now - 3600));

		$this->assertCount(1, $lines);
		$this->assertStringNotContainsString('Nachträglich', $lines[0]);
		$this->assertStringContainsString('Annulliert am', $lines[0]);
	}

	/** Nobody else ever reached the vehicle, so naming who entered a trip says nothing. */
	public function testALogbookNobodyElseReachesNamesNobody(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 134', 'jurisdiction' => 'de'])->getUuid();
		$this->trip($uuid, gmmktime(8, 0, 0, 1, 10, $this->year), ['start_odo' => 120000, 'end_odo' => 120450]);

		[, $page] = $this->printed($uuid, $this->year);

		$this->assertSame(0.0, $page->evaluate('count(//th[. = "Eingetragen von"])'));
	}

	/**
	 * Once someone was given access, each line names who entered it - and still does after the
	 * grant is revoked, because the trips that driver entered are still in the year. An author with
	 * no account, as an erased one's pseudonym, reads as the id the row carries.
	 */
	public function testOnceAccessWasGivenEachLineSaysWhoEnteredIt(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 135', 'jurisdiction' => 'de'])->getUuid();
		$grants = $this->grants->grant(self::AUTHOR, $uuid, ['grantee' => self::DRIVER, 'grantee_type' => 'user', 'role' => 'driver']);
		$january = gmmktime(8, 0, 0, 1, 10, $this->year);
		$this->trip($uuid, $january, ['start_odo' => 120000, 'end_odo' => 120450]);
		$this->trips->record(self::DRIVER, $uuid, [
			'started_at' => $january + 86400,
			'started_at_off' => 60,
			'ended_at' => $january + 86400 + 3600,
			'ended_at_off' => 60,
			'start_odo' => 120450,
			'end_odo' => 120500,
			'category' => Trip::PRIVATE,
		]);
		$this->grants->revoke(self::AUTHOR, $uuid, $grants[0]['uuid']);

		[$lines, $page] = $this->printed($uuid, $this->year);

		$this->assertSame(1.0, $page->evaluate('count(//th[. = "Eingetragen von"])'));
		$this->assertStringContainsString(self::AUTHOR, $lines[0]);
		$this->assertStringContainsString('Ben Fahrer', $lines[1]);
	}

	/**
	 * The vehicle's flips moved to the instants given, oldest first. A flip is stamped with the
	 * server's clock, and a period in the past is what this suite cannot otherwise write.
	 *
	 * @param list<int> $instants
	 */
	private function flippedAt(int $vehicleId, array $instants): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('fleet_audit')
			->where($qb->expr()->eq('entity', $qb->createNamedParameter('vehicle')))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($vehicleId, $qb::PARAM_INT)))
			->orderBy('id', 'ASC');
		$ids = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		$this->assertCount(count($instants), $ids);

		foreach ($ids as $i => $id) {
			$qb = $db->getQueryBuilder();
			$qb->update('fleet_audit')
				->set('created_at', $qb->createNamedParameter($instants[$i], $qb::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $qb::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/**
	 * The trail follows the trip, not the switch: on in March, off, the March trip edited while off,
	 * on again - and the edit is on the March line. A trail that followed the switch could be
	 * switched off to change a kept logbook unseen.
	 */
	public function testAnEditWhileTheModeIsOffIsOnTheLineOfATripSetOffUnderIt(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 136', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$year = $this->year - 1;
		$march = gmmktime(8, 0, 0, 3, 10, $year);
		$this->switchMode($uuid, true);
		$trip = $this->trip($uuid, $march, ['start_odo' => 120000, 'end_odo' => 120450]);
		$this->switchMode($uuid, false);
		$this->flippedAt((int)$vehicle->getId(), [(int)gmmktime(0, 0, 0, 3, 1, $year), (int)gmmktime(0, 0, 0, 4, 1, $year)]);

		$this->trips->update(self::AUTHOR, $uuid, $trip->getUuid(), $trip->getUpdatedAt(), [
			'started_at' => $march,
			'started_at_off' => 60,
			'ended_at' => $march + 5400,
			'ended_at_off' => 60,
			'start_odo' => 120000,
			'end_odo' => 120450,
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Besuch',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);
		$this->switchMode($uuid, true);

		[$lines] = $this->printed($uuid, $year);

		$this->assertCount(1, $lines);
		$this->assertMatchesRegularExpression('/Nachträglich geändert am \d\d\.\d\d\.\d{4}, \d\d:\d\d UTC[+−]\d\d:\d\d\. Vorher: Zweck „Abnahme“$/u', $lines[0]);
	}

	/**
	 * Each period names the plate the vehicle carried in it, and when a new one took over inside it:
	 * the header's plate is today's, and an auditor matches trips to the plate on the receipts.
	 */
	public function testEachPeriodNamesThePlatesValidInIt(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 140', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$year = $this->year - 1;
		$this->switchMode($uuid, true);
		$this->renamed($uuid, 'B-XY 141');
		$this->switchMode($uuid, false);
		$this->renamed($uuid, 'B-XY 142');
		$this->switchMode($uuid, true);
		$this->flippedAt((int)$vehicle->getId(), array_map(
			static fn (int $month): int => (int)gmmktime(0, 0, 0, $month, 1, $year),
			[3, 4, 5, 6, 7],
		));

		[, $page] = $this->printed($uuid, $year);

		$periods = array_map(
			static fn (\DOMNode $item): string => $item->textContent,
			iterator_to_array($page->query('//section[@id="modus"]//li') ?: []),
		);
		$this->assertCount(2, $periods);
		// No session reads it, so the reader's zone is the server's default: UTC on a dev instance.
		$this->assertStringEndsWith('Kennzeichen B-XY 140, ab dem 01.04.' . $year . ', 00:00 UTC+00:00 B-XY 141', $periods[0]);
		$this->assertStringEndsWith('Kennzeichen B-XY 142', $periods[1]);
	}

	private function renamed(string $uuid, string $plate): void {
		$vehicle = $this->vehicles->find(self::AUTHOR, $uuid);
		$this->vehicles->update(self::AUTHOR, $uuid, $vehicle->getUpdatedAt(), ['plate' => $plate]);
	}

	/**
	 * Defence in depth: a trip under the mode changed by something that went around the service -
	 * a statement run against the database - says so on its line, with when.
	 */
	public function testATripChangedWithoutARowSaysSoOnItsLine(): void {
		$uuid = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 137', 'jurisdiction' => 'de'])->getUuid();
		$this->switchMode($uuid, true);
		// After the flip, which the server stamped a moment ago.
		$startedAt = $this->now + 60;
		$trip = $this->trip($uuid, $startedAt, ['start_odo' => 120000, 'end_odo' => 120450]);
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_trips')
			->set('purpose', $qb->createNamedParameter('Urlaub'))
			->set('updated_at', $qb->createNamedParameter($trip->getUpdatedAt() + 60, $qb::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trip->getId(), $qb::PARAM_INT)));
		$qb->executeStatement();

		[$lines] = $this->printed($uuid, (int)gmdate('Y', $startedAt + 3600));

		$this->assertCount(1, $lines);
		$this->assertStringEndsWith('Geändert ohne Protokoll am ' . gmdate('d.m.Y, H:i', $trip->getUpdatedAt() + 60) . ' UTC+00:00', $lines[0]);
	}

	/** A trip set off while the mode is on is marked with what it lacks: the mode went on last New Year. */
	public function testATripSetOffUnderTheModeIsMarkedIncomplete(): void {
		$vehicle = $this->vehicles->create(self::AUTHOR, ['plate' => 'B-XY 128', 'jurisdiction' => 'de']);
		$uuid = $vehicle->getUuid();
		$this->switchMode($uuid, true);
		$this->flippedAt((int)$vehicle->getId(), [(int)gmmktime(0, 0, 0, 1, 1, $this->year - 1)]);
		$this->trip($uuid, gmmktime(8, 0, 0, 1, 10, $this->year - 1), ['start_odo' => 120000, 'end_odo' => 120450, 'partner' => null]);

		[$lines] = $this->printed($uuid, $this->year - 1);

		$this->assertCount(1, $lines);
		$this->assertStringContainsString('Unvollständig, es fehlt: Geschäftspartner', $lines[0]);
	}
}
