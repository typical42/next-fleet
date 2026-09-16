<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\De;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\De;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use PHPUnit\Framework\TestCase;

/**
 * How Germany prints a Fahrtenbuch. What goes into it is the core's (`LogbookReport`); that the page
 * loads nothing and escapes everything is the shared kit's. This is the layout: what an auditor
 * reads on each line, and in which words.
 */
class FahrtenbuchRendererTest extends TestCase {
	/** 2026-03-02 07:00 UTC, which is 08:00 in Berlin in winter. */
	private const MONDAY = 1772434800;
	private const SOURCE = 'https://www.gesetze-im-internet.de/estg/__6.html';

	private int $nextId = 1;

	/** @param array<string, mixed> $row */
	private function trip(array $row = []): Trip {
		$id = $this->nextId++;

		return Trip::fromRow($row + [
			'id' => $id,
			'uuid' => sprintf('0195e2f1-0000-4000-8000-%012d', $id),
			'vehicle_id' => 7,
			'started_at' => self::MONDAY,
			'started_at_off' => 60,
			'ended_at' => self::MONDAY + 5400,
			'ended_at_off' => 60,
			'start_odo' => 120000,
			'end_odo' => 120450,
			'from_label' => 'Berlin, Büro',
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
			'created_at' => self::MONDAY + 6000,
			'created_by' => 'alice',
		]);
	}

	/**
	 * @param list<array{trip: Trip, missing: list<string>}> $trips
	 * @param list<array{from: int, to: ?int}> $periods
	 */
	private function render(array $trips, array $periods = [], ?string $source = self::SOURCE): \DOMXPath {
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => 'B-XY 123', 'manufacturer' => 'VW', 'model' => 'Caddy', 'jurisdiction' => 'de']);
		$html = (new De\FahrtenbuchRenderer())->render(new LogbookReport($vehicle, 2026, $trips, $periods, $source));

		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));

		return new \DOMXPath($document);
	}

	/**
	 * The table's rows, each as its cells' text under the column headers.
	 *
	 * @return list<array<string, string>>
	 */
	private function rows(\DOMXPath $page): array {
		$headers = [];
		foreach ($page->query('//table/thead/tr/th') ?: [] as $th) {
			$headers[] = trim($th->textContent);
		}

		$rows = [];
		foreach ($page->query('//table/tbody/tr') ?: [] as $tr) {
			$cells = [];
			foreach ($page->query('td', $tr) ?: [] as $td) {
				$cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? '');
			}
			$rows[] = array_combine($headers, $cells);
		}

		return $rows;
	}

	private function text(\DOMXPath $page, string $query): string {
		$text = '';
		foreach ($page->query($query) ?: [] as $node) {
			$text .= ' ' . $node->textContent;
		}

		return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
	}

	/**
	 * Every column the business trip's line is judged on (docs/features.md#logbook-mode), counters
	 * written the way a German reads a number, and the category in words.
	 */
	public function testABusinessTripReadsAsOneLineOfTheLogbook(): void {
		$rows = $this->rows($this->render([['trip' => $this->trip(), 'missing' => []]]));

		$this->assertSame([[
			'Datum' => '02.03.2026',
			'Zeit' => '08:00–09:30',
			'Km-Stand Beginn' => '120.000',
			'Km-Stand Ende' => '120.450',
			'Kilometer' => '450',
			'Abfahrtsort' => 'Berlin, Büro',
			'Reiseziel' => 'Hamburg, Hafenstraße 1',
			'Zweck' => 'Abnahme',
			'Geschäftspartner' => 'Muster GmbH',
			'Art' => 'Dienstlich',
			'Erfasst' => '02.03.2026',
			'Vermerk' => '',
		]], $rows);
	}

	/**
	 * A Fahrtenbuch is judged on local calendar dates (docs/adr/0007-time-is-an-instant-plus-an-offset.md):
	 * a trip setting off at half past midnight in Berlin set off on that day, not on the UTC one
	 * before it. A journey ending on a later day says which.
	 */
	public function testATripIsDatedWhereItSetOff(): void {
		$rows = $this->rows($this->render([['trip' => $this->trip([
			'started_at' => 1774996200, // 2026-03-31 22:30 UTC
			'started_at_off' => 120,
			'ended_at' => 1775001600, // 2026-04-01 00:00 UTC
			'ended_at_off' => 120,
		]), 'missing' => []]]));

		$this->assertSame('01.04.2026', $rows[0]['Datum']);
		$this->assertSame('00:30–02:00', $rows[0]['Zeit']);

		$rows = $this->rows($this->render([['trip' => $this->trip([
			'ended_at' => self::MONDAY + 86400,
		]), 'missing' => []]]));

		$this->assertSame('08:00–03.03.2026 08:00', $rows[0]['Zeit']);
	}

	/** A distance trip states its kilometres and no counter it never read. */
	public function testADistanceTripStatesItsKilometres(): void {
		$rows = $this->rows($this->render([['trip' => $this->trip([
			'start_odo' => null,
			'end_odo' => null,
			'distance' => 1234,
			'category' => Trip::PRIVATE,
		]), 'missing' => []]]));

		$this->assertSame('', $rows[0]['Km-Stand Beginn']);
		$this->assertSame('', $rows[0]['Km-Stand Ende']);
		$this->assertSame('1.234', $rows[0]['Kilometer']);
		$this->assertSame('Privat', $rows[0]['Art']);
	}

	/**
	 * A voided trip stays in the logbook, as voided (docs/features.md#logbook-mode) - with the day it
	 * was voided, because a line that simply ended would say nothing about when.
	 */
	public function testAVoidedTripIsListedAsVoided(): void {
		$rows = $this->rows($this->render([
			['trip' => $this->trip(['deleted_at' => 1772740800]), 'missing' => []], // 2026-03-05 20:00 UTC
			['trip' => $this->trip(), 'missing' => []],
		]));

		$this->assertCount(2, $rows);
		$this->assertSame('Storniert am 05.03.2026', $rows[0]['Vermerk']);
		$this->assertSame('Abnahme', $rows[0]['Zweck'], 'a voided line is still readable');
		$this->assertSame('', $rows[1]['Vermerk']);
	}

	/** Incomplete is a flag, and the line names what it lacks in the words of its column headers. */
	public function testAnIncompleteTripSaysWhatItLacks(): void {
		$rows = $this->rows($this->render([['trip' => $this->trip(['purpose' => null, 'partner' => null]), 'missing' => ['purpose', 'partner']]]));

		$this->assertSame('Unvollständig, es fehlt: Zweck, Geschäftspartner', $rows[0]['Vermerk']);
	}

	/**
	 * A Reconciliation Trip says its kilometres were derived, not read off the counter - the honest
	 * entry an auditor is meant to find (docs/features.md#logbook-mode).
	 */
	public function testAReconciliationTripSaysItWasDerived(): void {
		$rows = $this->rows($this->render([['trip' => $this->trip([
			'start_odo' => null,
			'end_odo' => null,
			'distance' => 200,
			'from_label' => null,
			'to_label' => null,
			'purpose' => null,
			'partner' => null,
			'category' => Trip::PRIVATE,
			'reconciled' => true,
		]), 'missing' => []]]));

		$this->assertSame('Abgleich: Kilometer aus dem Zählerstand abgeleitet, nicht abgelesen', $rows[0]['Vermerk']);
	}

	/**
	 * The periods the mode was on, stated plainly (docs/adr/0003-logbook-mode-does-not-lock-the-past.md):
	 * one that ended and one that has not. The instants are the server's and carry no offset, so
	 * they are stated in UTC and say so.
	 */
	public function testThePeriodsTheModeWasOnAreStated(): void {
		$page = $this->render([], [
			['from' => 1770113100, 'to' => 1781427600], // 2026-02-03 10:05 UTC to 2026-06-14 09:00 UTC
			['from' => 1785578400, 'to' => null], // 2026-08-01 10:00 UTC
		]);

		$this->assertSame(
			[
				'vom 03.02.2026, 10:05 UTC bis zum 14.06.2026, 09:00 UTC',
				'seit dem 01.08.2026, 10:00 UTC',
			],
			array_map(
				static fn (\DOMNode $li): string => trim($li->textContent),
				iterator_to_array($page->query('//section[@id="modus"]//li') ?: []),
			),
		);
	}

	/** A year the mode never reached is said to be one, rather than left to an empty list. */
	public function testAYearWithoutTheModeSaysSo(): void {
		$page = $this->render([['trip' => $this->trip(), 'missing' => []]]);

		$this->assertStringContainsString('2026 nicht eingeschaltet', $this->text($page, '//section[@id="modus"]'));
		$this->assertSame(0, $page->query('//section[@id="modus"]//li')?->length);
	}

	/** A year without a trip prints as one, not as a table with nothing under its headers. */
	public function testAnEmptyYearSaysSo(): void {
		$page = $this->render([]);

		$this->assertSame('Keine Fahrten in 2026.', $this->text($page, '//table/tbody'));
	}

	/**
	 * The footer cites the requirement the logbook claims to meet, as a link and as the address
	 * itself, because a printed link is only its text.
	 */
	public function testTheFooterCitesTheRequirement(): void {
		$page = $this->render([]);

		$this->assertStringContainsString(self::SOURCE, $this->text($page, '//footer'));
		$this->assertSame(self::SOURCE, $page->query('//footer//a')?->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
	}

	/** The page's heading names the vehicle and the year. */
	public function testThePageNamesTheVehicleAndTheYear(): void {
		$page = $this->render([]);

		$this->assertSame('Fahrtenbuch 2026', $this->text($page, '//h1'));
		$this->assertStringContainsString('B-XY 123', $this->text($page, '//header'));
		$this->assertStringContainsString('VW Caddy', $this->text($page, '//header'));
	}
}
