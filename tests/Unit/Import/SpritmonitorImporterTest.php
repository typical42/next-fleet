<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Import\CsvReader;
use OCA\NextFleet\Import\Proposal;
use OCA\NextFleet\Import\Proposals;
use OCA\NextFleet\Import\SpritmonitorImporter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A Spritmonitor export, fuel or costs, becomes entries in this app's units. The files under
 * tests/Fixture/import/ are written from the sources their README names.
 */
class SpritmonitorImporterTest extends TestCase {
	private const DE = ['units' => ['distance' => 'km', 'volume' => 'l'], 'energy' => 'petrol', 'tz' => 'Europe/Berlin', 'currency' => 'EUR'];

	private SpritmonitorImporter $importer;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$this->importer = new SpritmonitorImporter($l);
	}

	private static function noon(string $day): int {
		return (new \DateTimeImmutable($day . ' 12:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
	}

	/** @return array{list<string>, \Generator<int, list<string>>} */
	private function file(string $name): array {
		$reader = CsvReader::open(fopen(__DIR__ . '/../../Fixture/import/' . $name, 'rb'), $this->importer->escape());

		return [$reader->header, $reader->rows()];
	}

	/** @return array{list<string>, \Generator<int, list<string>>} */
	private function csv(string $text): array {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $text);
		rewind($stream);
		$reader = CsvReader::open($stream, $this->importer->escape());

		return [$reader->header, $reader->rows()];
	}

	/**
	 * @param array<string, mixed> $answers
	 */
	private function propose(string $recordType, string $name, array $answers = self::DE): Proposals {
		[$header, $rows] = $this->file($name);

		return $this->importer->propose($recordType, $header, $rows, $answers);
	}

	/**
	 * @param array<string, mixed> $answers
	 */
	private function proposeCsv(string $recordType, string $text, array $answers = self::DE): Proposals {
		[$header, $rows] = $this->csv($text);

		return $this->importer->propose($recordType, $header, $rows, $answers);
	}

	/** @return list<?string> each proposal's reason, null for one that is read */
	private static function reasons(Proposals $proposals): array {
		return array_map(static fn (Proposal $p): ?string => $p->reason, $proposals->all);
	}

	public function testItNamesItselfNominatively(): void {
		$this->assertSame('spritmonitor', $this->importer->key());
		$this->assertSame('CSV (Spritmonitor format)', $this->importer->label());
		$this->assertSame(['fuel', 'costs'], $this->importer->recordTypes());
	}

	public function testItEscapesWithABackslash(): void {
		$this->assertSame('\\', $this->importer->escape());
	}

	public function testItAsksTheUnits(): void {
		// The energy and what each category text means are questions only the rows raise
		// (Proposals::$open).
		$this->assertSame(['units'], $this->importer->questions('fuel'));
		$this->assertSame(['units'], $this->importer->questions('costs'));
	}

	/** A plug-in hybrid's file that names every fuel asks nothing; one row that does not, asks. */
	public function testOnlyARowThatNamesNoFuelAsksTheEnergy(): void {
		$header = ['Datum', 'Spritmenge', 'Kraftstoff'];
		$hybrid = ['energies' => ['petrol', 'electric']] + self::DE;
		unset($hybrid['energy']);

		$named = $this->importer->propose('fuel', $header, [2 => ['14.03.2026', '30', '7'], 3 => ['15.03.2026', '20', '19']], $hybrid);
		$unnamed = $this->importer->propose('fuel', $header, [2 => ['14.03.2026', '30', 'Super E10']], $hybrid);

		$this->assertSame([], $named->open);
		$this->assertSame(['energy' => ['petrol', 'electric']], $unnamed->open);
		$this->assertSame('petrol', $unnamed->all[0]->fields['energy']);
	}

	public function testAnUnknownRecordTypeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->importer->columns('trips', ['Datum']);
	}

	/** A vehicle with no energy type yet takes no fuel import, however well the file names its fuels. */
	public function testAVehicleWithoutAnEnergyTypeTakesNoFuelFile(): void {
		$answers = ['energies' => []] + self::DE;
		unset($answers['energy']);

		try {
			$this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;40;Diesel\n", $answers);
			$this->fail('A fuel file went into a vehicle that takes no energy');
		} catch (RefusedException $e) {
			// The reason lets the sheet say it in the entry sheet's words (src/utils/imports.js).
			$this->assertSame('no_energy', $e->reason);
		}
	}

	public function testAGermanFuelExportPlacesWhatAnEnergyEntryHoldsAndNamesTheRest(): void {
		[$header] = $this->file('spritmonitor-fuel.csv');

		$this->assertSame([
			'placed' => [
				'Datum' => 'filled_at',
				'Km-Stand' => 'odo',
				'Spritmenge' => 'amount',
				'Kosten' => 'total',
				'Währung' => 'currency',
				'Tankart' => 'full_tank',
				'Kraftstoff' => 'energy',
			],
			// Distance and consumption are worked out here; an energy entry has no notes.
			'ignored' => ['Teil-Km', 'Reifen', 'Strecken', 'Fahrweise', 'Bemerkung', 'Verbrauch'],
		], $this->importer->columns('fuel', $header));
	}

	/** The on-board computer's own figures are the car's, not the fill-up's. */
	public function testAnEnglishFuelExportPlacesTheSameAndIgnoresTheComputersColumns(): void {
		[$header] = $this->file('spritmonitor-fuel-en.csv');

		$this->assertSame([
			'placed' => [
				'Date' => 'filled_at',
				'Odometer' => 'odo',
				'Quantity' => 'amount',
				'Total price' => 'total',
				'Currency' => 'currency',
				'Type' => 'full_tank',
				'Fuel' => 'energy',
			],
			'ignored' => ['Trip', 'Unit price', 'Tires', 'Roads', 'Driving style', 'Note', 'Consumption', 'BC-Consumption', 'BC-Speed'],
		], $this->importer->columns('fuel', $header));
	}

	public function testTheOtherNamesOfAColumnPlaceToo(): void {
		$this->assertSame(
			['placed' => ['tachostand' => 'odo', 'Menge' => 'amount', 'Gesamtkosten' => 'total'], 'ignored' => ['Distanz']],
			$this->importer->columns('fuel', ['tachostand', 'Distanz', 'Menge', 'Gesamtkosten']),
		);
	}

	public function testFillUpsBecomeEnergyEntriesInCanonicalUnits(): void {
		$proposals = $this->propose('fuel', 'spritmonitor-fuel.csv');

		$this->assertSame([], $proposals->open);
		$first = $proposals->all[0];
		$this->assertSame([Proposal::ENERGY, 2, null], [$first->kind, $first->row, $first->outcome]);
		$this->assertSame([
			'filled_at' => self::noon('2026-03-14'),
			'filled_at_off' => 60,
			'energy' => 'diesel',
			'odo' => 52000,
			'amount' => 42150,
			'total' => 7161,
			'full_tank' => true,
			'missed_previous' => false,
		], $first->fields);
	}

	public function testAnEnglishExportReadsAlikeAndItsEscapedNoteKeepsTheRowInShape(): void {
		$proposals = $this->propose('fuel', 'spritmonitor-fuel-en.csv', ['energy' => null] + self::DE);
		[$full, $partial] = $proposals->all;

		$this->assertSame([], $proposals->open);
		$this->assertSame(
			['energy' => 'diesel', 'odo' => 52000, 'amount' => 42150, 'total' => 7161, 'full_tank' => true],
			array_intersect_key($full->fields, ['energy' => 0, 'odo' => 0, 'amount' => 0, 'total' => 0, 'full_tank' => 0]),
		);
		$this->assertFalse($partial->fields['full_tank']);
	}

	/** The escape took the line break, so the next row became part of the note. */
	public function testANoteEndingInALoneBackslashThatSwallowedTheNextRowIsUnreadable(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Bemerkung\n14.03.2026;30;Waschanlage\\\n15.03.2026;20;\n");

		$this->assertCount(1, $proposals->all);
		$swallowed = $proposals->all[0];
		$this->assertSame([Proposal::UNREADABLE, 'cells', null, 2], [$swallowed->outcome, $swallowed->reason, $swallowed->column, $swallowed->row]);
	}

	public function testTheFillTypeSaysFullPartialOrFirstAndAnythingElseIsUnreadable(): void {
		[, $partial, $first, $none] = $this->propose('fuel', 'spritmonitor-fuel.csv')->all;

		$this->assertSame([false, false], [$partial->fields['full_tank'], $partial->fields['missed_previous']]);
		// A first fill-up has no fill-up before it to measure from.
		$this->assertSame([true, true], [$first->fields['full_tank'], $first->fields['missed_previous']]);
		$this->assertSame([Proposal::UNREADABLE, 'code', 'Tankart'], [$none->outcome, $none->reason, $none->column]);
	}

	public function testAFillUpInAnotherCurrencyIsUnreadable(): void {
		$francs = $this->propose('fuel', 'spritmonitor-fuel.csv')->all[4];

		$this->assertSame([Proposal::UNREADABLE, 'currency', 'Währung'], [$francs->outcome, $francs->reason, $francs->column]);
	}

	/** @return iterable<string, array{string, string}> */
	public static function fuelCodes(): iterable {
		foreach ([1, 2, 3, 4] as $code) {
			yield $code . ' diesel' => [(string)$code, 'diesel'];
		}
		foreach ([6, 7, 8, 9, 15, 16, 18, 20, 22] as $code) {
			yield $code . ' petrol' => [(string)$code, 'petrol'];
		}
		yield '12 lpg' => ['12', 'lpg'];
		yield '13 cng' => ['13', 'cng'];
		yield '14 cng' => ['14', 'cng'];
		yield '19 electric' => ['19', 'electric'];
	}

	/**
	 * A vehicle of every energy and no answer: only a code that decides leaves nothing open.
	 *
	 * @dataProvider fuelCodes
	 */
	public function testAFuelCodeDecidesTheEnergy(string $code, string $energy): void {
		$answers = ['energy' => null, 'energies' => ['electric', 'petrol', 'diesel', 'lpg', 'cng']] + self::DE;

		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;40;" . $code . "\n", $answers);

		$this->assertSame([], $proposals->open);
		$this->assertSame($energy, $proposals->all[0]->fields['energy']);
	}

	public function testAdBlueAndHydrogenAreNoEnergyAFillUpRecords(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;10;21\n02.03.2026;4;23\n");

		$this->assertSame(['adblue', 'hydrogen'], self::reasons($proposals));
		$this->assertSame('Kraftstoff', $proposals->all[0]->column);
	}

	/** A row refused for what it holds is no fuel that needs a volume. */
	public function testChargesAndAnAdBlueRowNeedNoVolume(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;41,5;19\n02.03.2026;10;21\n", ['units' => ['distance' => 'km'], 'tz' => 'UTC']);

		$this->assertSame([null, 'adblue'], self::reasons($proposals));
	}

	public function testACodeNoSourceNamesAndAWordThatNamesNoneLeaveTheAnswer(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;40;5\n02.03.2026;40;Super E10\n03.03.2026;40;Diesel\n04.03.2026;40;Autogas (LPG)\n05.03.2026;40;Erdgas (CNG)\n06.03.2026;40;Strom\n");

		$this->assertSame(
			['petrol', 'petrol', 'diesel', 'lpg', 'cng', 'electric'],
			array_map(static fn (Proposal $p): mixed => $p->fields['energy'], $proposals->all),
		);
	}

	public function testAChargingSessionIsCountedInKilowattHoursWhateverTheVolumeUnit(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;40;1\n02.03.2026;41,5;19\n", ['units' => ['distance' => 'km', 'volume' => 'us_gal']] + self::DE)->all;

		$this->assertSame([151416, 41500], [$proposals[0]->fields['amount'], $proposals[1]->fields['amount']]);
	}

	public function testAFileOfChargesNeedsNoVolume(): void {
		$proposal = $this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;41,5;Strom\n", ['units' => ['distance' => 'km'], 'energy' => 'electric', 'tz' => 'UTC'])->all[0];

		$this->assertSame(41500, $proposal->fields['amount']);
	}

	public function testAFileWithFuelNeedsTheVolumeEvenWhenTheAnswerIsElectric(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->proposeCsv('fuel', "Datum;Spritmenge;Kraftstoff\n01.03.2026;40;Diesel\n", ['units' => ['distance' => 'km'], 'energy' => 'electric', 'tz' => 'UTC']);
	}

	/** The export writes a decimal comma, so a lone point before three digits groups thousands. */
	public function testANumberTheColumnLeavesOpenTakesTheDecimalComma(): void {
		$proposal = $this->proposeCsv('fuel', "Datum;Km-Stand;Spritmenge\n01.03.2026;52.000;40\n")->all[0];

		$this->assertSame(52000, $proposal->fields['odo']);
	}

	/** The export writes the day first, so slash dates that fit both ways ask nothing. */
	public function testDatesThatFitBothOrdersAreDayFirst(): void {
		$proposals = $this->proposeCsv('fuel', "Datum;Spritmenge\n01/02/2026;40\n");

		$this->assertSame([], $proposals->open);
		$this->assertSame(self::noon('2026-02-01'), $proposals->all[0]->fields['filled_at']);
	}

	public function testACostsExportPlacesItsCategoryCode(): void {
		[$header] = $this->file('spritmonitor-costs.csv');

		$this->assertSame([
			'placed' => [
				'Datum' => 'date',
				'Km-Stand' => 'odo',
				'Kostenart' => 'category',
				'Bezeichnung' => 'title',
				'Kosten' => 'amount',
				'Währung' => 'currency',
				'Bemerkung' => 'notes',
			],
			'ignored' => [],
		], $this->importer->columns('costs', $header));
		$this->assertSame('category', $this->importer->columns('costs', ['Cost type'])['placed']['Cost type']);
	}

	/** @return iterable<string, array{int, string}> */
	public static function costCodes(): iterable {
		$map = [
			1 => 'maintenance.service', 2 => 'maintenance.repair', 3 => 'maintenance.tyres', 4 => 'maintenance.service',
			5 => 'expense.insurance', 6 => 'expense.tax', 7 => 'maintenance.inspection', 8 => 'maintenance.upgrade',
			9 => 'expense.other', 11 => 'expense.other', 12 => 'expense.other', 13 => 'expense.other',
			14 => 'expense.other', 15 => 'expense.other', 17 => 'expense.fine', 18 => 'expense.parking',
			19 => 'expense.toll', 20 => 'maintenance.repair',
		];
		foreach ($map as $code => $meaning) {
			yield $code . ' ' . $meaning => [$code, $meaning];
		}
	}

	/** @dataProvider costCodes */
	public function testACostCodeHasAMeaningByDefault(int $code, string $meaning): void {
		$proposals = $this->proposeCsv('costs', "Datum;Kostenart;Kosten\n15.01.2026;" . $code . ";10\n");

		$this->assertSame([], $proposals->open);
		$this->assertSame([(string)$code], $proposals->categories);
		$this->assertSame([$code => $meaning], $proposals->defaults);
		[$kind, $what] = explode('.', $meaning);
		$this->assertSame($kind, $proposals->all[0]->kind);
		$this->assertSame($what, $proposals->all[0]->fields[$kind === 'expense' ? 'category' : 'type']);
	}

	/** Neither is a running cost; a refund would be a negative one. Nobody is asked about them. */
	public function testAPurchasePriceAndARefundAreSkippedWithTheirReason(): void {
		$proposals = $this->propose('costs', 'spritmonitor-costs.csv');

		$this->assertSame(['purchase', 'refund'], array_slice(self::reasons($proposals), 6));
		$this->assertSame('Kostenart', $proposals->all[6]->column);
		$this->assertSame(['6', '1', '5', '9', '4'], $proposals->categories);
	}

	/** Its date or currency does not matter: it would not be imported either way. */
	public function testAPurchasePriceIsSkippedWhateverElseItsRowHolds(): void {
		$proposals = $this->proposeCsv('costs', "Datum;Kostenart;Kosten;Währung\n;10;15000;CHF\n");

		$this->assertSame(['purchase'], self::reasons($proposals));
	}

	/** A code's own name fits only the meaning it has by default. */
	public function testACodeMappedElsewhereIsTitledByItsText(): void {
		$proposals = $this->proposeCsv('costs', "Datum;Kostenart;Kosten\n15.01.2026;20;10\n16.01.2026;20;10\n", ['category_map' => ['20' => 'maintenance.service']] + self::DE);
		$default = $this->proposeCsv('costs', "Datum;Kostenart;Kosten\n15.01.2026;20;10\n");

		$this->assertSame('20', $proposals->all[0]->fields['title']);
		$this->assertSame('Spare parts', $default->all[0]->fields['title']);
	}

	public function testACodedCostsFileAsksNothingAndBecomesExpensesAndMaintenance(): void {
		$proposals = $this->propose('costs', 'spritmonitor-costs.csv');
		[$tax, $service, $insurance, $wash, $oil, $uncategorised] = $proposals->all;

		$this->assertSame([], $proposals->open);
		$this->assertSame([6 => 'expense.tax', 1 => 'maintenance.service', 5 => 'expense.insurance', 9 => 'expense.other', 4 => 'maintenance.service'], $proposals->defaults);
		$this->assertSame(Proposal::EXPENSE, $tax->kind);
		$this->assertSame([
			'spent_at' => self::noon('2026-01-15'),
			'spent_at_off' => 60,
			'category' => 'tax',
			'amount' => 12000,
			'notes' => 'Kfz-Steuer',
		], $tax->fields);
		$this->assertSame(Proposal::MAINTENANCE, $service->kind);
		$this->assertSame([
			'done_at' => self::noon('2026-02-10'),
			'done_at_off' => 60,
			'type' => 'service',
			'title' => 'Inspektion',
			'odo' => 51200,
			'cost' => 28990,
			'notes' => 'mit Ölwechsel',
		], $service->fields);
		$this->assertSame('insurance', $insurance->fields['category']);
		// The escaped separator stays in the title, so the amount after it is still the amount.
		$this->assertSame(['other', 'Wäsche; innen', 1200], [$wash->fields['category'], $wash->fields['notes'], $wash->fields['amount']]);
		// A maintenance record needs a title; the code's name is the best the row has.
		$this->assertSame('Oil change', $oil->fields['title']);
		$this->assertSame([Proposal::UNREADABLE, 'missing', 'Kostenart'], [$uncategorised->outcome, $uncategorised->reason, $uncategorised->column]);
	}

	public function testTheUsersAnswerOverridesTheDefault(): void {
		$proposals = $this->propose('costs', 'spritmonitor-costs.csv', ['category_map' => ['9' => 'skip', '1' => 'maintenance.inspection']] + self::DE);

		$this->assertSame('inspection', $proposals->all[1]->fields['type']);
		$this->assertSame([Proposal::UNREADABLE, 'skipped'], [$proposals->all[3]->outcome, $proposals->all[3]->reason]);
		// The default stays listed, so the screen can offer it back.
		$this->assertSame('expense.other', $proposals->defaults[9]);
	}

	public function testAWordOrACodeNoSourceNamesIsAskedAndItsRowsWaitUnreadable(): void {
		$proposals = $this->proposeCsv('costs', "Datum;Kostenart;Kosten\n15.01.2026;Wäsche;12\n16.01.2026;21;5\n17.01.2026;6;100\n");

		$this->assertSame(['category_map' => ['Wäsche', '21']], $proposals->open);
		$this->assertSame(['Wäsche', '21', '6'], $proposals->categories);
		$this->assertSame(['category', 'category', null], self::reasons($proposals));
	}

	public function testOnlyTheTextsNotYetMappedStayOpen(): void {
		$proposals = $this->proposeCsv('costs', "Datum;Kostenart;Kosten\n15.01.2026;Wäsche;12\n16.01.2026;Steuer;5\n", ['category_map' => ['Steuer' => 'expense.tax']] + self::DE);

		$this->assertSame(['category_map' => ['Wäsche']], $proposals->open);
		$this->assertSame('tax', $proposals->all[1]->fields['category']);
	}

	public function testAHeaderMatchesWhateverTheCaseOfItsUmlaut(): void {
		$this->assertSame(['placed' => ['WÄHRUNG' => 'currency'], 'ignored' => []], $this->importer->columns('fuel', ['WÄHRUNG']));
	}

	public function testANoteOfZeroIsKept(): void {
		$proposal = $this->proposeCsv('costs', "Datum;Kostenart;Kosten;Bemerkung\n15.01.2026;6;120;0\n")->all[0];

		$this->assertSame('0', $proposal->fields['notes']);
	}

	public function testACategoryMappedToWhatNoEntryHoldsIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->propose('costs', 'spritmonitor-costs.csv', ['category_map' => ['6' => 'expense.fuel']] + self::DE);
	}

	/** The request's own field: a client may send anything there. */
	public function testACategoryMapThatIsNoMapIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('category_map maps each category text to what it becomes');
		$this->propose('costs', 'spritmonitor-costs.csv', ['category_map' => 'expense.tax'] + self::DE);
	}
}
