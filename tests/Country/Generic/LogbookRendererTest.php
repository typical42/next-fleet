<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country\Generic;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\Generic;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use OCA\NextFleet\Tests\Stub\Untranslated;
use PHPUnit\Framework\TestCase;

/**
 * The plain logbook a country nobody has written prints: the trips as they were entered, with no
 * country's requirements laid over them. That it loads nothing and escapes everything is the
 * shared kit's; this is what a reader finds on the page.
 */
class LogbookRendererTest extends TestCase {
	/** 2026-03-02 07:00 UTC. */
	private const MONDAY = 1772434800;

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
			'from_label' => 'Vienna, office',
			'to_label' => 'Linz, Hafenstraße 1',
			'purpose' => 'Delivery',
			'partner' => 'Example Ltd',
			'category' => Trip::BUSINESS,
			'created_at' => self::MONDAY + 6000,
			'created_by' => 'alice',
		]);
	}

	/** @param list<Trip> $trips */
	private function render(array $trips): \DOMXPath {
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => 'W-12345X', 'manufacturer' => 'VW', 'model' => 'Caddy', 'jurisdiction' => 'generic']);
		$lines = array_map(static fn (Trip $trip): array => ['trip' => $trip, 'missing' => [], 'late' => []], $trips);
		$html = (new Generic\LogbookRenderer(new Untranslated()))->render(new LogbookReport($vehicle, 2026, $lines, [], null));

		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));

		return new \DOMXPath($document);
	}

	/** @return list<array<string, string>> the table's rows, each as its cells' text under the headers */
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
		return trim(preg_replace('/\s+/u', ' ', (string)$page->evaluate('string(' . $query . ')')) ?? '');
	}

	/**
	 * Date, route, purpose, counter, distance and category, and nothing a country would add. The date and time are the wall clock where the trip set off.
	 */
	public function testATripReadsAsOneLine(): void {
		$this->assertSame([[
			'Date' => '2026-03-02',
			'Time' => '08:00–09:30',
			'From' => 'Vienna, office',
			'To' => 'Linz, Hafenstraße 1',
			'Purpose' => 'Delivery',
			'Odometer start' => '120000',
			'Odometer end' => '120450',
			'Kilometres' => '450',
			'Category' => 'Business',
			'Note' => '',
		]], $this->rows($this->render([$this->trip()])));
	}

	/** A voided trip stays on the page, marked, and says when: an entry that vanished says nothing. */
	public function testAVoidedTripIsListedAsVoided(): void {
		$rows = $this->rows($this->render([
			$this->trip(['deleted_at' => 1772740800]), // 2026-03-05 20:00 UTC
			$this->trip(),
		]));

		$this->assertSame('Voided on 2026-03-05, 20:00 UTC', $rows[0]['Note']);
		$this->assertSame('Delivery', $rows[0]['Purpose'], 'a voided line is still readable');
		$this->assertSame('', $rows[1]['Note']);
	}

	/** A Reconciliation Trip says its kilometres were derived, not read off the counter. */
	public function testAReconciliationTripSaysItWasDerived(): void {
		$rows = $this->rows($this->render([$this->trip([
			'start_odo' => null,
			'end_odo' => null,
			'distance' => 200,
			'category' => Trip::PRIVATE,
			'reconciled' => true,
		])]));

		$this->assertSame('200', $rows[0]['Kilometres']);
		$this->assertSame('Private', $rows[0]['Category']);
		$this->assertSame('Distance derived from the odometer, not read off it', $rows[0]['Note']);
	}

	/** A journey ending on a later day says which. */
	public function testAnOvernightTripSaysWhenItEnded(): void {
		$rows = $this->rows($this->render([$this->trip(['ended_at' => self::MONDAY + 86400])]));

		$this->assertSame('08:00–2026-03-03 08:00', $rows[0]['Time']);
	}

	/** @return array<string, string> each category of the split, with what it reads */
	private function split(\DOMXPath $page): array {
		$split = [];
		foreach ($page->query('//section[@id="split"]//dt') ?: [] as $dt) {
			$split[trim($dt->textContent)] = $this->text($page, '//section[@id="split"]//dt[.="' . trim($dt->textContent) . '"]/following-sibling::dd[1]');
		}

		return $split;
	}

	/**
	 * The business/private/commute split, in kilometres per category. A voided trip drove nothing,
	 * so it counts nowhere. A trip whose distance is not stated is left out, and the page says so
	 * rather than letting the sum pass for the whole.
	 */
	public function testTheYearIsSplitByCategory(): void {
		$page = $this->render([
			$this->trip(),
			$this->trip(['start_odo' => 120450, 'end_odo' => 120500, 'category' => Trip::PRIVATE]),
			$this->trip(['start_odo' => 120500, 'end_odo' => 120520, 'category' => Trip::COMMUTE]),
			$this->trip(['start_odo' => 120520, 'end_odo' => 120620, 'category' => Trip::PRIVATE]),
			$this->trip(['start_odo' => 120620, 'end_odo' => 121620, 'deleted_at' => self::MONDAY + 9000]),
			$this->trip(['start_odo' => null, 'category' => Trip::COMMUTE]),
		]);

		$this->assertSame(['Business' => '450', 'Private' => '150', 'Commute' => '20'], $this->split($page));
		$this->assertSame('Trips with no distance stated, not counted above: 1', $this->text($page, '//section[@id="split"]/p'));
	}

	/** A category whose every trip leaves its distance unstated is not stated, not zero. */
	public function testACategoryWithNoStatedDistanceIsNotStated(): void {
		$page = $this->render([$this->trip(), $this->trip(['start_odo' => null, 'category' => Trip::COMMUTE])]);

		$this->assertSame(['Business' => '450', 'Private' => '0', 'Commute' => 'Not stated'], $this->split($page));
	}

	/** A vehicle counting hours has its trips counted in hours, and says so. */
	public function testAVehicleCountingHoursSaysHours(): void {
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => 'W-12345X', 'odo_unit' => 'h', 'jurisdiction' => 'generic']);
		$html = (new Generic\LogbookRenderer(new Untranslated()))->render(new LogbookReport($vehicle, 2026, [
			['trip' => $this->trip(['start_odo' => 1200, 'end_odo' => 1203]), 'missing' => [], 'late' => []],
		], [], null));
		$document = new \DOMDocument();
		$this->assertTrue($document->loadHTML($html, LIBXML_NOERROR));
		$page = new \DOMXPath($document);

		$this->assertSame('3', $this->rows($page)[0]['Hours']);
		$this->assertSame('Hours by category', $this->text($page, '//section[@id="split"]/h2'));
	}

	/** A year without a trip prints as one, not as a table with nothing under its headers. */
	public function testAnEmptyYearSaysSo(): void {
		$this->assertSame('No trips in 2026.', $this->text($this->render([]), '//table/tbody'));
	}

	/** The heading names the year, the header the vehicle, and the footer disclaims certification. */
	public function testThePageNamesTheVehicleAndTheYear(): void {
		$page = $this->render([]);

		$this->assertSame('Logbook 2026', $this->text($page, '//h1'));
		$this->assertStringContainsString('W-12345X', $this->text($page, '//header'));
		$this->assertStringContainsString('VW Caddy', $this->text($page, '//header'));
		$this->assertStringContainsString('not a certification', $this->text($page, '//footer'));
	}
}
