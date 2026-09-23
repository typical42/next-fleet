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
use OCA\NextFleet\Jurisdiction\MileageClaim;
use PHPUnit\Framework\TestCase;

/**
 * How Germany prints a mileage claim. What is valued is the core's (`MileageClaim`); that the page
 * loads nothing and escapes everything is the shared kit's. This is the layout, in German whatever
 * the reader's language: it goes to a German tax office.
 */
class MileageClaimRendererTest extends TestCase {
	/** 2026-03-02 07:00 UTC, which is 08:00 in Berlin in winter. */
	private const MONDAY = 1772434800;
	private const SOURCE = 'https://www.gesetze-im-internet.de/estg/__9.html';

	private int $nextId = 1;

	/**
	 * @param array<string, mixed> $row
	 * @return array{trip: Trip, kilometres: ?int, rate: ?int, amount: ?int}
	 */
	private function line(?int $kilometres, ?int $rate, ?int $amount, array $row = []): array {
		$id = $this->nextId++;
		$trip = Trip::fromRow($row + [
			'id' => $id,
			'uuid' => sprintf('0195e2f1-0000-4000-8000-%012d', $id),
			'vehicle_id' => 7,
			'started_at' => self::MONDAY,
			'started_at_off' => 60,
			'ended_at' => self::MONDAY + 5400,
			'ended_at_off' => 60,
			'distance' => $kilometres,
			'from_label' => 'Berlin, Büro',
			'to_label' => 'Hamburg, Hafenstraße 1',
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);

		return ['trip' => $trip, 'kilometres' => $kilometres, 'rate' => $rate, 'amount' => $amount];
	}

	/** @param list<array{trip: Trip, kilometres: ?int, rate: ?int, amount: ?int}> $lines */
	private function render(array $lines, ?int $total, ?int $kilometres): \DOMXPath {
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => 'B-XY 123', 'manufacturer' => 'VW', 'model' => 'Caddy', 'jurisdiction' => 'de']);
		$html = (new De\MileageClaimRenderer())->render(new MileageClaim($vehicle, 2026, $lines, $total, $kilometres, self::SOURCE));

		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));

		return new \DOMXPath($document);
	}

	/** @return list<array<string, string>> each body row's cells under the column headers */
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

	/** The sum row's non-empty cells, space-separated. */
	private static function sum(\DOMXPath $page): string {
		$cells = [];
		foreach ($page->query('//table/tfoot/tr/*') ?: [] as $cell) {
			$text = trim($cell->textContent);
			if ($text !== '') {
				$cells[] = $text;
			}
		}

		return implode(' ', $cells);
	}

	private static function text(\DOMXPath $page, string $path): string {
		return trim((string)preg_replace('/\s+/u', ' ', (string)$page->evaluate('string(' . $path . ')')));
	}

	/** A line is the trip as the tax office asks for it, then kilometres × rate = amount. */
	public function testALineIsTheTripAndWhatItIsWorth(): void {
		$page = $this->render([$this->line(120, 300, 3600)], 3600, 120);

		$this->assertSame([[
			'Datum' => '02.03.2026',
			'Abfahrtsort' => 'Berlin, Büro',
			'Reiseziel' => 'Hamburg, Hafenstraße 1',
			'Zweck' => 'Abnahme',
			'Geschäftspartner' => 'Muster GmbH',
			'Kilometer' => '120',
			'Satz je km' => '0,30 €',
			'Betrag' => '36,00 €',
		]], $this->rows($page));
		$this->assertSame('de', $page->evaluate('string(/html/@lang)'));
		$this->assertStringContainsString('B-XY 123', self::text($page, '//header'));
		$this->assertStringContainsString('VW Caddy', self::text($page, '//header'));
	}

	/** The sum row states the kilometres and the amount it adds up. */
	public function testTheSumIsTheTotalOfWhatWasValued(): void {
		$page = $this->render([$this->line(1234, 300, 37020), $this->line(10, 305, 305)], 37325, 1244);

		$this->assertSame('Summe 1.244 373,25 €', self::sum($page));
		$this->assertSame('0,305 €', $this->rows($page)[1]['Satz je km']);
	}

	/**
	 * A trip the table has no rate for, or whose kilometres nobody stated, says so, and the page
	 * says the sum leaves it out: a reader must not take the sum for the year's.
	 */
	public function testWhatWasNotValuedSaysSoAndIsCountedOut(): void {
		$page = $this->render([$this->line(120, null, null), $this->line(null, 300, null), $this->line(40, 300, 1200)], 1200, 40);
		$rows = $this->rows($page);

		$this->assertSame('nicht angegeben', $rows[0]['Satz je km']);
		$this->assertSame('nicht angegeben', $rows[0]['Betrag']);
		$this->assertSame('nicht angegeben', $rows[1]['Kilometer']);
		$this->assertSame('nicht angegeben', $rows[1]['Betrag']);
		$this->assertStringContainsString('2 Fahrten ohne Betrag sind in der Summe nicht enthalten', self::text($page, '//body'));
	}

	/** Nothing valued is no sum, not 0,00 €. */
	public function testNoTotalIsNotAZeroTotal(): void {
		$page = $this->render([$this->line(120, null, null)], null, null);

		$this->assertSame('Summe nicht angegeben', self::sum($page));
	}

	public function testAYearWithoutBusinessTripsSaysSo(): void {
		$page = $this->render([], null, null);

		$this->assertSame('Keine Dienstfahrten in 2026.', self::text($page, '//table/tbody/tr'));
	}

	/**
	 * Commutes are not on it, and the page says so, since a reader who kept them in the logbook
	 * would otherwise look for them. The rate is cited, and the page claims no legal standing.
	 */
	public function testItSaysWhatItLeavesOutAndWhereTheRateIsWritten(): void {
		$page = $this->render([$this->line(120, 300, 3600)], 3600, 120);
		$body = self::text($page, '//body');

		$this->assertStringContainsString('Wohnung und erster Tätigkeitsstätte', $body);
		$this->assertSame(self::SOURCE, $page->evaluate('string(//footer//a/@href)'));
		$this->assertStringContainsString('nicht rechtlich geprüft', self::text($page, '//footer'));
	}
}
