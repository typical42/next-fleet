<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Import\CsvReader;
use OCA\NextFleet\Import\LubeLoggerImporter;
use OCA\NextFleet\Import\Proposal;
use OCA\NextFleet\Import\Proposals;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A LubeLogger export, one file per record type, becomes entries in this app's units. The files
 * under tests/Fixture/import/ are written from LubeLogger's headers; their README names the source.
 */
class LubeLoggerImporterTest extends TestCase {
	private const US = ['units' => ['distance' => 'mi', 'volume' => 'us_gal'], 'energy' => 'petrol', 'tz' => 'America/Chicago'];

	private LubeLoggerImporter $importer;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$this->importer = new LubeLoggerImporter($l);
	}

	private static function noon(string $day): int {
		return (new \DateTimeImmutable($day . ' 12:00', new \DateTimeZone('America/Chicago')))->getTimestamp();
	}

	/** @return array{list<string>, \Generator<int, list<string>>} */
	private static function file(string $name): array {
		$stream = fopen(__DIR__ . '/../../Fixture/import/' . $name, 'rb');
		$reader = CsvReader::open($stream);

		return [$reader->header, $reader->rows()];
	}

	/** @return array{list<string>, \Generator<int, list<string>>} */
	private static function csv(string $text): array {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $text);
		rewind($stream);
		$reader = CsvReader::open($stream);

		return [$reader->header, $reader->rows()];
	}

	/**
	 * @param array<string, mixed> $answers
	 */
	private function propose(string $recordType, string $name, array $answers = self::US): Proposals {
		[$header, $rows] = self::file($name);

		return $this->importer->propose($recordType, $header, $rows, $answers);
	}

	public function testItNamesItselfNominatively(): void {
		$this->assertSame('lubelogger', $this->importer->key());
		$this->assertSame('CSV (LubeLogger format)', $this->importer->label());
		$this->assertSame(['fuel', 'service', 'repair', 'upgrade', 'tax', 'supplies', 'odometer'], $this->importer->recordTypes());
	}

	/** CsvHelper writes RFC 4180: a quote doubled, no escape character. */
	public function testItEscapesNothing(): void {
		$this->assertNull($this->importer->escape());
	}

	public function testItAsksTheUnitsAndForFuelTheEnergy(): void {
		$this->assertSame(['units', 'energy'], $this->importer->questions('fuel'));
		$this->assertSame(['units'], $this->importer->questions('repair'));
		$this->assertSame(['units'], $this->importer->questions('odometer'));
		$this->assertSame([], $this->importer->questions('tax'));
	}

	public function testAnUnknownRecordTypeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->importer->columns('trips', ['Date']);
	}

	public function testAFuelExportPlacesWhatAnEnergyEntryHoldsAndNamesTheRest(): void {
		[$header] = self::file('lubelogger-fuel.csv');

		$this->assertSame([
			'placed' => [
				'Date' => 'filled_at',
				'Odometer' => 'odo',
				'FuelConsumed' => 'amount',
				'Cost' => 'total',
				'IsFillToFull' => 'full_tank',
				'MissedFuelUp' => 'missed_previous',
			],
			// An energy entry has no notes, so Notes and Tags have nowhere to go.
			'ignored' => ['FuelEconomy', 'StartingSoc', 'EndingSoc', 'Notes', 'Tags', 'ExtraFields', 'Files'],
		], $this->importer->columns('fuel', $header));
	}

	public function testHeadersMatchInAnyCaseAndUnderTheAliasesLubeLoggerAccepts(): void {
		$header = ['fuelup_date', 'ODO', 'Litres', 'Total Cost', 'partial_fuelup', 'missed_fuelup', 'Mood'];

		$this->assertSame([
			'placed' => [
				'fuelup_date' => 'filled_at',
				'ODO' => 'odo',
				'Litres' => 'amount',
				'Total Cost' => 'total',
				'partial_fuelup' => 'full_tank',
				'missed_fuelup' => 'missed_previous',
			],
			'ignored' => ['Mood'],
		], $this->importer->columns('fuel', $header));
	}

	public function testASecondHeaderForTheSameValueIsIgnoredNotMerged(): void {
		$this->assertSame(
			['placed' => ['Odometer' => 'odo'], 'ignored' => ['odo']],
			$this->importer->columns('service', ['Odometer', 'odo']),
		);
	}

	public function testAnAliasPlacesOnlyWhereItsValueBelongs(): void {
		// `qty` is LubeLogger's name for fuel consumed; a supplies file's quantity is PartQuantity.
		$this->assertSame(['placed' => [], 'ignored' => ['qty']], $this->importer->columns('supplies', ['qty']));
	}

	/** The fixture's dates carry .NET's midnight, `12:00:00 AM`: a day, read as a date alone is. */
	public function testFuelUpsBecomeEnergyEntriesInCanonicalUnits(): void {
		$proposals = $this->propose('fuel', 'lubelogger-fuel.csv');

		$this->assertSame([], $proposals->open);
		$first = $proposals->all[0];
		$this->assertSame(Proposal::ENERGY, $first->kind);
		$this->assertSame(2, $first->row);
		$this->assertNull($first->outcome);
		$this->assertSame([
			'filled_at' => self::noon('2026-03-14'),
			'filled_at_off' => -300,
			'energy' => 'petrol',
			'odo' => 83686,
			'amount' => 39747,
			'total' => 4250,
			'full_tank' => true,
			'missed_previous' => false,
		], $first->fields);
		$this->assertFalse($proposals->all[1]->fields['full_tank']);
	}

	/**
	 * A German server writes `00:00:00` and decimal commas, quoted since CsvHelper separates with
	 * commas whatever the culture.
	 */
	public function testAGermanFuelExportIsReadAsTheEnglishOneIs(): void {
		$proposals = $this->propose('fuel', 'lubelogger-fuel-de.csv', ['units' => ['distance' => 'km', 'volume' => 'l'], 'energy' => 'diesel', 'tz' => 'Europe/Berlin']);

		$this->assertSame([], $proposals->open);
		$this->assertSame([
			'filled_at' => (new \DateTimeImmutable('2026-03-14T12:00:00+01:00'))->getTimestamp(),
			'filled_at_off' => 60,
			'energy' => 'diesel',
			'odo' => 52000,
			'amount' => 40500,
			'total' => 7285,
			'full_tank' => true,
			'missed_previous' => false,
		], $proposals->all[0]->fields);
		$this->assertSame([38200, 6830, false, true], [
			$proposals->all[1]->fields['amount'],
			$proposals->all[1]->fields['total'],
			$proposals->all[1]->fields['full_tank'],
			$proposals->all[1]->fields['missed_previous'],
		]);
	}

	/** .NET with ICU 72 or later writes `12:00:00 AM`. */
	public function testMidnightBehindANarrowSpaceIsTheDayToo(): void {
		[$header, $rows] = self::csv("Date,FuelConsumed\n3/14/2026 12:00:00\u{202F}AM,40\n");

		$proposal = $this->importer->propose('fuel', $header, $rows, ['units' => ['distance' => 'km', 'volume' => 'l'], 'energy' => 'diesel', 'tz' => 'UTC'])->all[0];

		$this->assertSame(gmmktime(12, 0, 0, 3, 14, 2026), $proposal->fields['filled_at']);
	}

	public function testATimeOtherThanMidnightIsKept(): void {
		[$header, $rows] = self::csv("Date,FuelConsumed\n3/14/2026 7:45:00 PM,40\n");

		$proposal = $this->importer->propose('fuel', $header, $rows, ['units' => ['distance' => 'km', 'volume' => 'l'], 'energy' => 'diesel', 'tz' => 'UTC', 'date_order' => 'mdy'])->all[0];

		$this->assertSame(gmmktime(19, 45, 0, 3, 14, 2026), $proposal->fields['filled_at']);
	}

	/** Which cell is which cannot be told; no column is to blame. */
	public function testARowWithMoreCellsThanTheHeaderIsUnreadable(): void {
		[$header, $rows] = self::csv("Date,FuelConsumed\n3/14/2026,40,12\n3/15/2026,30\n");

		[$wide, $fine] = $this->importer->propose('fuel', $header, $rows, self::US)->all;

		$this->assertSame([Proposal::UNREADABLE, 'cells', null, 2], [$wide->outcome, $wide->reason, $wide->column, $wide->row]);
		$this->assertNull($fine->outcome);
	}

	public function testARowWithoutTheAmountOrADayIsUnreadableAndBlamesItsColumn(): void {
		$proposals = $this->propose('fuel', 'lubelogger-fuel.csv');

		[, , $noAmount, $noDay] = $proposals->all;
		$this->assertSame([4, Proposal::UNREADABLE, 'missing', 'FuelConsumed', []], [$noAmount->row, $noAmount->outcome, $noAmount->reason, $noAmount->column, $noAmount->fields]);
		$this->assertSame([5, Proposal::UNREADABLE, 'date', 'Date'], [$noDay->row, $noDay->outcome, $noDay->reason, $noDay->column]);
	}

	public function testAChargeIsCountedInWattHours(): void {
		[$header, $rows] = self::csv("Date,Odometer,FuelConsumed,Cost\n2026-03-14,12000,41.25,9.90\n");

		$proposals = $this->importer->propose('fuel', $header, $rows, ['units' => ['distance' => 'km'], 'energy' => 'electric', 'tz' => 'Europe/Berlin']);

		$this->assertSame(41250, $proposals->all[0]->fields['amount']);
		$this->assertSame(12000, $proposals->all[0]->fields['odo']);
	}

	public function testAPartialFuelUpIsNoFullTankAndAFileThatSaysNeitherMeansFull(): void {
		[$header, $rows] = self::csv("Date,FuelConsumed,partial_fuelup\n2026-03-14,40,true\n2026-03-21,40,\n");

		$proposals = $this->importer->propose('fuel', $header, $rows, ['units' => ['distance' => 'km', 'volume' => 'l'], 'energy' => 'diesel', 'tz' => 'UTC']);

		$this->assertFalse($proposals->all[0]->fields['full_tank']);
		// The entry sheet's default too (docs/ui.md): most fill-ups are to the brim.
		$this->assertTrue($proposals->all[1]->fields['full_tank']);
		$this->assertFalse($proposals->all[1]->fields['missed_previous']);
		$this->assertArrayNotHasKey('odo', $proposals->all[1]->fields);
	}

	public function testServiceRepairAndUpgradeBecomeMaintenanceOfThatType(): void {
		foreach (['service', 'repair', 'upgrade'] as $type) {
			$proposals = $this->propose($type, 'lubelogger-service.csv');

			$this->assertSame(Proposal::MAINTENANCE, $proposals->all[0]->kind);
			$this->assertSame([
				'done_at' => self::noon('2026-03-14'),
				'done_at_off' => -300,
				'type' => $type,
				'title' => 'Oil change',
				'odo' => 83686,
				'cost' => 8999,
				'notes' => "5W-30, 4.5 qt\noil filter",
			], $proposals->all[0]->fields);
		}
		$this->assertArrayNotHasKey('notes', $proposals->all[1]->fields);
	}

	/**
	 * A service cost is .NET's currency format, `ToString("C")`: a symbol, grouping, and a negative
	 * either signed or bracketed. A negative cost is unreadable: a credit is no running cost.
	 */
	public function testAServiceCostInDollarsIsReadAndANegativeOneIsUnreadable(): void {
		[, , $grouped, $signed, $bracketed] = $this->propose('service', 'lubelogger-service.csv')->all;

		$this->assertSame(123456, $grouped->fields['cost']);
		$this->assertSame(['negative', 'Cost'], [$signed->reason, $signed->column]);
		$this->assertSame(['negative', 'Cost'], [$bracketed->reason, $bracketed->column]);
	}

	public function testAGermanServiceExportIsReadInEuros(): void {
		$proposals = $this->propose('service', 'lubelogger-service-de.csv', ['units' => ['distance' => 'km'], 'tz' => 'Europe/Berlin', 'currency' => 'EUR']);

		[$oil, $belt, $credit] = $proposals->all;
		$this->assertSame([], $proposals->open);
		$this->assertSame([
			'done_at' => (new \DateTimeImmutable('2026-03-14T12:00:00+01:00'))->getTimestamp(),
			'done_at_off' => 60,
			'type' => 'service',
			'title' => 'Ölwechsel',
			'odo' => 52000,
			'cost' => 8999,
			'notes' => "5W-30, 4,5 l\nÖlfilter",
		], $oil->fields);
		$this->assertSame(123456, $belt->fields['cost']);
		$this->assertSame(['negative', 'Cost'], [$credit->reason, $credit->column]);
	}

	public function testATaxBecomesATaxExpenseWithItsDescriptionInTheNotes(): void {
		$proposals = $this->propose('tax', 'lubelogger-tax.csv');

		$this->assertSame(Proposal::EXPENSE, $proposals->all[0]->kind);
		$this->assertSame([
			'spent_at' => self::noon('2026-01-15'),
			'spent_at_off' => -360,
			'category' => 'tax',
			'amount' => 12000,
			'notes' => "Registration\nTwo years",
		], $proposals->all[0]->fields);
	}

	public function testSuppliesBecomeOtherExpensesWithThePartInTheNotes(): void {
		$proposals = $this->propose('supplies', 'lubelogger-supplies.csv');

		$this->assertSame([
			'spent_at' => self::noon('2026-03-14'),
			'spent_at_off' => -300,
			'category' => 'other',
			'amount' => 3299,
			'notes' => "Engine oil\nPart number: 0W20-5QT\nSupplier: Parts Shop\nQuantity: 1\nFor the spring service",
		], $proposals->all[0]->fields);
	}

	public function testAnOdometerRecordBecomesAnOdometerEntryOfItsReading(): void {
		$proposals = $this->propose('odometer', 'lubelogger-odometer.csv');

		$this->assertSame(Proposal::ODOMETER, $proposals->all[0]->kind);
		$this->assertSame(2, $proposals->all[0]->row);
		$this->assertSame([
			'read_at' => self::noon('2026-03-20'),
			'read_at_off' => -300,
			'value' => 83927,
		], $proposals->all[0]->fields);
	}

	public function testDatesEveryOrderFitsLeaveTheOrderOpenUntilAnswered(): void {
		$text = "Date,Description,Cost\n3/4/2026,Registration,120\n";

		[$header, $rows] = self::csv($text);
		$open = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC']);
		[$header, $rows] = self::csv($text);
		$answered = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC', 'date_order' => 'mdy']);

		$this->assertSame(['date_order' => ['dmy', 'mdy']], $open->open);
		$this->assertSame([], $answered->open);
		$this->assertSame(gmmktime(12, 0, 0, 3, 4, 2026), $answered->all[0]->fields['spent_at']);
	}

	public function testTheColumnSettlesADecimalMarkOneCellCannot(): void {
		[$header, $rows] = self::csv("Date;Description;Cost\n2026-03-14;Registration;1,250\n2026-03-15;Toll;2,5\n");

		$proposals = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC']);

		$this->assertSame(125, $proposals->all[0]->fields['amount']);
	}

	public function testACostInCurrencyFormatIsReadAndOneInAnotherCurrencyIsUnreadable(): void {
		[$header, $rows] = self::csv("Date;Description;Cost\n2026-03-14;Registration;\"1.234,50\u{00A0}€\"\n2026-03-15;Toll;£2,00\n2026-03-16;Toll;\$2,00\n");

		[$euros, $pounds, $dollars] = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC', 'currency' => 'EUR'])->all;

		$this->assertSame(123450, $euros->fields['amount']);
		$this->assertSame(['currency', 'Cost'], [$pounds->reason, $pounds->column]);
		// `$` is several currencies, but never the euro.
		$this->assertSame(['currency', 'Cost'], [$dollars->reason, $dollars->column]);
	}

	/** A vehicle's currency typed in lower case is still its code. */
	public function testTheVehiclesCurrencyIsReadInAnyCase(): void {
		[$header, $rows] = self::csv("Date;Description;Cost\n2026-03-14;Registration;12,00\u{00A0}€\n2026-03-15;Toll;£2,00\n");

		[$euros, $pounds] = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC', 'currency' => 'eur'])->all;

		$this->assertSame(1200, $euros->fields['amount']);
		$this->assertSame(['currency', 'Cost'], [$pounds->reason, $pounds->column]);
	}

	/**
	 * A vehicle whose currency is no code, from before the vehicle sheet checked it, cannot price
	 * a row: each row with money is unreadable and blames no column, since the fault is the
	 * vehicle's. A row without money still imports, and so does a file without any.
	 */
	public function testAVehicleCurrencyThatIsNoCodeLeavesOutOnlyTheRowsWithMoney(): void {
		[$header, $rows] = self::csv("Date,Odometer,Description,Cost\n2026-03-14,12000,Brakes,20\n2026-03-15,12100,Wipers,\n");

		[$priced, $free] = $this->importer->propose('repair', $header, $rows, ['units' => ['distance' => 'km'], 'tz' => 'UTC', 'currency' => '€'])->all;
		$odometer = $this->propose('odometer', 'lubelogger-odometer.csv', self::US + ['currency' => '€']);

		$this->assertSame(['currency', null], [$priced->reason, $priced->column]);
		$this->assertNull($free->outcome);
		$this->assertNull($odometer->all[0]->outcome);
	}

	public function testANegativeAmountIsUnreadable(): void {
		[$header, $rows] = self::csv("Date,Description,Cost\n2026-03-14,Refund,-20\n");

		$proposal = $this->importer->propose('tax', $header, $rows, ['tz' => 'UTC'])->all[0];

		$this->assertSame(['negative', 'Cost'], [$proposal->reason, $proposal->column]);
	}

	public function testAShortRowReadsItsMissingCellsAsEmpty(): void {
		[$header, $rows] = self::csv("Date,Odometer,Description,Cost\n2026-03-14,12000,Brakes\n");

		$proposal = $this->importer->propose('repair', $header, $rows, ['units' => ['distance' => 'km'], 'tz' => 'UTC'])->all[0];

		$this->assertNull($proposal->outcome);
		$this->assertArrayNotHasKey('cost', $proposal->fields);
	}

	public function testATitleLongerThanAMaintenanceRecordTakesIsUnreadable(): void {
		[$header, $rows] = self::csv("Date,Description\n2026-03-14," . str_repeat('x', 256) . "\n");

		$proposal = $this->importer->propose('service', $header, $rows, ['units' => ['distance' => 'km'], 'tz' => 'UTC'])->all[0];

		$this->assertSame(['too_long', 'Description'], [$proposal->reason, $proposal->column]);
	}

	/**
	 * The entry services' bounds (docs/security.md), met in the preview: past them the write would
	 * refuse the row and with it the whole import. A note is joined from several cells, so it is
	 * the joined note that counts.
	 */
	public function testANoteOrACostPastTheEntryBoundsIsUnreadable(): void {
		$half = str_repeat('x', 5_000);
		[$header, $rows] = self::csv("Date,Description,Cost,Notes,Tags\n2026-03-14,Oil,10,{$half},{$half}\n2026-03-14,Oil,10000000001,,\n2026-03-14,Oil,10000000000,{$half},\n");

		$all = $this->importer->propose('service', $header, $rows, ['units' => ['distance' => 'km'], 'tz' => 'UTC'])->all;

		$this->assertSame(['too_long', 'Notes'], [$all[0]->reason, $all[0]->column]);
		$this->assertSame(['too_large', 'Cost'], [$all[1]->reason, $all[1]->column]);
		$this->assertNull($all[2]->outcome);
	}

	public function testAUnitTheFileNeedsAndTheAnswersLackIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->propose('fuel', 'lubelogger-fuel.csv', ['units' => ['distance' => 'km'], 'energy' => 'petrol', 'tz' => 'UTC']);
	}

	public function testAnEnergyTheAppDoesNotKnowIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->propose('fuel', 'lubelogger-fuel.csv', ['energy' => 'hydrogen'] + self::US);
	}

	/**
	 * The file names no energy: the vehicle's only one answers it, and of several the first is
	 * read while the question stays open.
	 */
	public function testTheVehiclesEnergiesAnswerOrOpenTheEnergy(): void {
		$us = self::US;
		unset($us['energy']);

		$this->assertSame([], $this->propose('fuel', 'lubelogger-fuel.csv', ['energies' => ['diesel']] + $us)->open);
		$hybrid = $this->propose('fuel', 'lubelogger-fuel.csv', ['energies' => ['petrol', 'electric']] + $us);
		$this->assertSame(['energy' => ['petrol', 'electric']], $hybrid->open);
		$this->assertSame('petrol', $hybrid->all[0]->fields['energy']);
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function energiesItCannotTake(): iterable {
		yield 'none' => [['energies' => []]];
		yield 'an answer the vehicle does not take' => [['energies' => ['diesel'], 'energy' => 'petrol']];
	}

	/**
	 * @dataProvider energiesItCannotTake
	 * @param array<string, mixed> $answers
	 */
	public function testAVehicleThatTakesNoSuchEnergyIsRefused(array $answers): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->propose('fuel', 'lubelogger-fuel.csv', $answers + self::US);
	}
}
