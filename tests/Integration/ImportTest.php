<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\TimelineService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Another tool's export, previewed from the caller's own Files (docs/architecture.md#import). The
 * accounts are real, because the file is one somebody's Files holds.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ImportTest extends TestCase {
	private const OWNER = 'nextfleet-test-import-owner';
	/** Has Files of their own, which the owner cannot read. */
	private const OTHER = 'nextfleet-test-import-other';
	private const ACCOUNTS = [self::OWNER, self::OTHER];
	private const FIXTURES = __DIR__ . '/../Fixture/import/';

	private ImportService $import;
	private VehicleService $vehicles;
	private EnergyService $fillUps;
	private TimelineService $timeline;
	private OdometerService $odometer;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->import = $container->get(ImportService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->fillUps = $container->get(EnergyService::class);
		$this->timeline = $container->get(TimelineService::class);
		$this->odometer = $container->get(OdometerService::class);
	}

	/** The accounts live for the whole class, for the reason DocumentTest gives. */
	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->get($uid)?->delete();
		}
	}

	protected function tearDown(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (['fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_odo_readings', 'fleet_access', 'fleet_reminder_recipients'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];
	}

	/**
	 * The fixture's four rows: two fill-ups, one without an amount, one on a day April does not
	 * have. The slash dates only fit month first, so nothing is left to ask.
	 */
	public function testAFuelExportIsPreviewedRowByRow(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');

		$preview = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file));

		$this->assertSame('lubelogger', $preview['importer']);
		$this->assertSame('fuel', $preview['record_type']);
		$this->assertSame(
			['Date' => 'filled_at', 'Odometer' => 'odo', 'FuelConsumed' => 'amount', 'Cost' => 'total', 'IsFillToFull' => 'full_tank', 'MissedFuelUp' => 'missed_previous'],
			array_column($preview['columns']['placed'], 'field', 'header'),
		);
		$this->assertSame(['FuelEconomy', 'StartingSoc', 'EndingSoc', 'Notes', 'Tags', 'ExtraFields', 'Files'], $preview['columns']['ignored']);
		$this->assertSame([], $preview['questions']);
		$this->assertSame([], $preview['categories']);
		$this->assertSame(['new' => 2, 'duplicate' => 0, 'unreadable' => 2, 'creates' => 2], $preview['counts']);
		$this->assertSame([['reason' => 'missing', 'count' => 1], ['reason' => 'date', 'count' => 1]], $preview['reasons']);
		$this->assertSame([2, 3, 4, 5], array_column($preview['proposals'], 'row'));
		$this->assertSame('{"row":4,"kind":"energy","fields":{},"outcome":"unreadable","reason":"missing","column":"FuelConsumed"}', json_encode($preview['proposals'][2]));
		$this->assertSame(
			['energy' => 'diesel', 'amount' => 10500, 'total' => 4250],
			array_intersect_key((array)$preview['proposals'][0]['fields'], ['energy' => 0, 'amount' => 0, 'total' => 0]),
		);
		$this->assertSame($file->getEtag(), $preview['etag']);
	}

	/** The first row was entered by hand already; it is skipped unless the request includes it. */
	public function testARowAlreadyOnTheVehicleIsADuplicate(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');
		// 2026-03-14 18:00 in Berlin: another hour, the same day and counter.
		$this->fillUps->record(self::OWNER, $vehicle->getUuid(), ['filled_at' => 1773507600, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 10000, 'odo' => 52000]);

		$skipped = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file));
		$included = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['include_duplicates' => true]));

		$this->assertSame(['new' => 1, 'duplicate' => 1, 'unreadable' => 2, 'creates' => 1], $skipped['counts']);
		$this->assertSame('duplicate', $skipped['proposals'][0]['outcome']);
		$this->assertSame(['new' => 1, 'duplicate' => 1, 'unreadable' => 2, 'creates' => 2], $included['counts']);
	}

	/**
	 * What only the user can say stays open, and the rows are read meanwhile: an energy of
	 * several, a date order every slash date fits both ways, a cost category nobody mapped yet.
	 */
	public function testWhatTheFileDoesNotSayIsAsked(): void {
		$hybrid = $this->vehicle(['energy_types' => ['petrol', 'electric']]);
		$ambiguous = $this->file(self::OWNER, 'fuel.csv', "Date,Odometer,FuelConsumed,Cost\n1/2/2026,52000,10,20\n");

		$asked = $this->import->preview(self::OWNER, $hybrid->getUuid(), $this->request($ambiguous));
		$answered = $this->import->preview(self::OWNER, $hybrid->getUuid(), $this->request($ambiguous, answers: ['energy' => 'petrol', 'date_order' => 'dmy']));

		$this->assertSame([['name' => 'energy', 'choices' => ['petrol', 'electric']], ['name' => 'date_order', 'choices' => ['dmy', 'mdy']]], $asked['questions']);
		$this->assertSame(1, $asked['counts']['new']);
		$this->assertSame([], $answered['questions']);

		$costs = $this->import->preview(self::OWNER, $hybrid->getUuid(), $this->request(
			$this->file(self::OWNER, 'costs.csv', "Datum;Kostenart;Kosten\n15.01.2026;Steuer;120\n10.02.2026;Wartung;290\n01.03.2026;6;480\n"),
			'spritmonitor',
			'costs',
			['category_map' => ['Steuer' => 'expense.tax']],
		));

		$this->assertSame([['name' => 'category_map', 'choices' => ['Wartung']]], $costs['questions']);
		$this->assertSame(['Steuer', 'Wartung', '6'], $costs['categories']);
		$this->assertSame([['text' => '6', 'meaning' => 'expense.tax']], $costs['category_defaults']);
	}

	/**
	 * A file in codes asks nothing, and its escaped note keeps the row in shape: the service reads
	 * with the format's escape character.
	 */
	public function testASpritmonitorFileInCodesImportsByItsDefaults(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$fuel = $this->file(self::OWNER, 'spritmonitor-fuel.csv');
		$costs = $this->file(self::OWNER, 'spritmonitor-costs.csv');

		$fuelPreview = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($fuel, 'spritmonitor'));
		$costsPreview = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($costs, 'spritmonitor', 'costs'));

		$this->assertSame([], $fuelPreview['questions']);
		$this->assertSame(['new' => 3, 'duplicate' => 0, 'unreadable' => 2, 'creates' => 3], $fuelPreview['counts']);
		$this->assertSame([], $costsPreview['questions']);
		$this->assertSame(
			[['reason' => 'missing', 'count' => 1], ['reason' => 'purchase', 'count' => 1], ['reason' => 'refund', 'count' => 1]],
			$costsPreview['reasons'],
		);
		$done = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($costs, 'spritmonitor', 'costs', ['etag' => $costsPreview['etag']]));
		$this->assertSame(['expense', 'maintenance', 'expense', 'expense', 'maintenance'], array_column($done['created'], 'type'));
	}

	/** Rows that name their own fuel leave nothing to ask, however many energies the vehicle takes. */
	public function testAFileThatNamesEveryFuelAsksNoEnergy(): void {
		$hybrid = $this->vehicle(['energy_types' => ['petrol', 'electric']]);
		$file = $this->file(self::OWNER, 'fuel.csv', "Datum;Km-Stand;Spritmenge;Kosten;Tankart;Kraftstoff\n14.03.2026;52000;42,15;71,61;1;Strom\n");

		$preview = $this->import->preview(self::OWNER, $hybrid->getUuid(), $this->request($file, 'spritmonitor'));

		$this->assertSame([], $preview['questions']);
		$this->assertSame('electric', ((array)$preview['proposals'][0]['fields'])['energy']);
	}

	/** A fill-up of an energy the vehicle does not take would be refused, so it is unreadable. */
	public function testAnEnergyTheVehicleDoesNotTakeIsUnreadable(): void {
		$vehicle = $this->vehicle(['energy_types' => ['petrol']]);
		$file = $this->file(self::OWNER, 'fuel.csv', "Datum;Km-Stand;Spritmenge;Kosten;Tankart;Kraftstoff\n14.03.2026;52000;42,15;71,61;1;Diesel\n");

		$preview = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file, 'spritmonitor'));

		$this->assertSame('{"row":2,"kind":"energy","fields":{},"outcome":"unreadable","reason":"energy","column":null}', json_encode($preview['proposals'][0]));
		// Nor may the user answer one for a row that names none.
		$unnamed = $this->file(self::OWNER, 'fuel.csv', "Datum;Km-Stand;Spritmenge;Kosten;Tankart;Kraftstoff\n14.03.2026;52000;42,15;71,61;1;Super E10\n");
		$this->expectException(\InvalidArgumentException::class);
		$this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($unnamed, 'spritmonitor', answers: ['energy' => 'diesel']));
	}

	/**
	 * Only a file of the caller's own, as for a paper: an id from somebody else's Files is not
	 * found, whoever asks.
	 */
	public function testAFileFromSomebodyElsesFilesIsNotFound(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$theirs = $this->file(self::OTHER, 'lubelogger-fuel.csv');

		$this->expectException(DoesNotExistException::class);
		$this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($theirs));
	}

	/** Importing writes other people's history: a driver's `log` does not cover it. */
	public function testADriverMayNotImport(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$this->grant($vehicle, self::OTHER, 'driver');
		$theirs = $this->file(self::OTHER, 'lubelogger-fuel.csv');

		$this->expectException(AccessDeniedException::class);
		$this->import->preview(self::OTHER, $vehicle->getUuid(), $this->request($theirs));
	}

	/** A manager may: `edit` is what an import takes. */
	public function testAManagerMayImportTheirOwnFile(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$this->grant($vehicle, self::OTHER, 'manager');
		$theirs = $this->file(self::OTHER, 'lubelogger-fuel.csv');

		$this->assertSame(2, $this->import->preview(self::OTHER, $vehicle->getUuid(), $this->request($theirs))['counts']['new']);
	}

	/** A laid-up car's history is still worth bringing along. */
	public function testALaidUpVehicleTakesAnImport(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel'], 'lifecycle' => 'laid_up']);

		$this->assertSame(2, $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($this->file(self::OWNER, 'lubelogger-fuel.csv')))['counts']['new']);
	}

	public function testADisposedVehicleTakesNoImport(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel'], 'lifecycle' => 'disposed', 'disposed_at' => '2026-01-01']);

		$this->expectException(\InvalidArgumentException::class);
		$this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($this->file(self::OWNER, 'lubelogger-fuel.csv')));
	}

	/** Refused before a byte is read, by the size the server knows. */
	public function testAFileOverTheCapIsRefused(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$big = $this->file(self::OWNER, 'big.csv', "Date,Odometer\n" . str_repeat("1/2/2026,1\n", 500_000));

		try {
			$this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($big));
			$this->fail('previewed a file over the cap');
		} catch (ImportRefusedException $e) {
			$this->assertSame('too_large', $e->reason);
		}
	}

	/** A file the reader gives up on midway is refused whole: nothing of it is previewed. */
	public function testAFileTheReaderRefusesMidwayIsRefusedWhole(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$binary = $this->file(self::OWNER, 'fuel.csv', "Date,Odometer,FuelConsumed,Cost\n3/14/2026,52000,10,20\n3/15/2026,\0,10,20\n");

		try {
			$this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($binary));
			$this->fail('previewed a file with a NUL byte');
		} catch (ImportRefusedException $e) {
			$this->assertSame(['binary', 3], [$e->reason, $e->row]);
		}
	}

	/**
	 * The import writes what the preview counted as created, each row as the entry sheet would,
	 * entered by the importer, and answers what it created.
	 */
	public function testAnImportCreatesTheRowsThePreviewCounted(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');
		$preview = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file));

		$done = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['etag' => $preview['etag']]));

		$this->assertSame($preview['counts'], $done['counts']);
		$this->assertSame(['energy', 'energy'], array_column($done['created'], 'type'));
		$this->assertSame([10500, 9800], array_map(fn (array $created): mixed => $this->entry($vehicle, $created)['amount'], $done['created']));
		$this->assertSame(self::OWNER, $this->entry($vehicle, $done['created'][0])['created_by']);
		$this->assertSame(52310, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * The user saw a preview of another file: they preview again rather than import unseen rows.
	 * An etag that is not the file's stands in for a file written since, because within one PHP
	 * process a rewrite leaves the etag as it was; across requests it moves.
	 */
	public function testAFileChangedSinceThePreviewIsNotImported(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');

		try {
			$this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['etag' => 'an earlier version']));
			$this->fail('imported a file that changed since its preview');
		} catch (FileChangedException) {
		}
		$this->assertSame([], $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
	}

	/** The rows were read with a provisional answer; importing them so would be a guess. */
	public function testAnOpenQuestionRefusesTheImport(): void {
		$hybrid = $this->vehicle(['energy_types' => ['petrol', 'electric']]);
		$file = $this->file(self::OWNER, 'fuel.csv', "Date,Odometer,FuelConsumed,Cost\n3/14/2026,52000,10,20\n");
		$etag = $this->import->preview(self::OWNER, $hybrid->getUuid(), $this->request($file))['etag'];

		try {
			$this->import->import(self::OWNER, $hybrid->getUuid(), $this->request($file, answers: ['etag' => $etag]));
			$this->fail('imported while the energy was still to be answered');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('energy', $e->getMessage());
		}
		$this->assertSame([], $this->timeline->page(self::OWNER, $hybrid->getUuid(), null, null)['rows']);
	}

	/** An import is checked against the preview it follows, so it cannot go without one. */
	public function testAnImportWithoutTheEtagIsRefused(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);

		$this->expectException(\InvalidArgumentException::class);
		$this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($this->file(self::OWNER, 'lubelogger-fuel.csv')));
	}

	/** A row already there is skipped, unless the request includes duplicates. */
	public function testADuplicateIsCreatedOnlyWhenIncluded(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');
		$this->fillUps->record(self::OWNER, $vehicle->getUuid(), ['filled_at' => 1773507600, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 10000, 'odo' => 52000]);
		$etag = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file))['etag'];

		$skipped = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['etag' => $etag]));
		$this->assertSame(['new' => 1, 'duplicate' => 1, 'unreadable' => 2, 'creates' => 1], $skipped['counts']);
		$this->assertSame([9800], array_map(fn (array $created): mixed => $this->entry($vehicle, $created)['amount'], $skipped['created']));

		// The second import finds the first's row as well, so only an explicit "include" doubles it.
		$included = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['etag' => $etag, 'include_duplicates' => true]));
		$this->assertSame(['new' => 0, 'duplicate' => 2, 'unreadable' => 2, 'creates' => 2], $included['counts']);
		$this->assertCount(2, $included['created']);
	}

	/**
	 * Straight after an import, its answer takes it back: those entries and nothing else, their
	 * Readings with them, and the vehicle's counter as it stood before.
	 */
	public function testAnUndoRemovesExactlyWhatTheImportCreated(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$byHand = $this->fillUps->record(self::OWNER, $vehicle->getUuid(), ['filled_at' => 1767265200, 'filled_at_off' => 60, 'energy' => 'diesel', 'amount' => 10000, 'odo' => 50000]);
		$done = $this->imported($vehicle, 'lubelogger-fuel.csv');

		$undone = $this->import->undo(self::OWNER, $vehicle->getUuid(), ['created' => $done['created']]);

		$this->assertSame(['undone' => 2], $undone);
		$this->assertSame([$byHand['uuid']], array_map(static fn (array $row): string => $row['energy']->getUuid(), $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']));
		$this->assertCount(1, $this->odometer->list(self::OWNER, $vehicle->getUuid()));
		$this->assertSame(50000, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * Once one entry was deleted by hand, the list no longer names what the import left: the undo
	 * takes back nothing, and the rest is deleted one by one like any other entry.
	 */
	public function testAnEntryDeletedSinceRefusesTheWholeUndo(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$done = $this->imported($vehicle, 'lubelogger-fuel.csv');
		// The last, so the first is taken back before the refusal and has to come back with it.
		$last = $this->entry($vehicle, $done['created'][1]);
		$this->fillUps->delete(self::OWNER, $vehicle->getUuid(), $last['uuid'], $last['updated_at']);

		$this->assertUndoRefused($vehicle, $done['created']);
		$this->assertCount(1, $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
		// The first's Reading came back with it, and so did the counter it set.
		$this->assertCount(1, $this->odometer->list(self::OWNER, $vehicle->getUuid()));
		$this->assertSame(52000, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/** A manager's import is theirs to undo; the owner deletes its entries one by one. */
	public function testSomebodyElsesImportIsNotTheirsToUndo(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$this->grant($vehicle, self::OTHER, 'manager');
		$done = $this->imported($vehicle, 'lubelogger-fuel.csv', uid: self::OTHER);

		$this->assertUndoRefused($vehicle, $done['created']);
		$this->assertSame(['undone' => 2], $this->import->undo(self::OTHER, $vehicle->getUuid(), ['created' => $done['created']]));
	}

	/**
	 * A Reading a fill-up wrote goes with its fill-up, and an entry on another vehicle is not this
	 * one's: neither is named by any import's list.
	 */
	public function testAnUndoNamesOnlyTheVehiclesOwnEntries(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$done = $this->imported($vehicle, 'lubelogger-fuel.csv');
		$fillUpsReading = $this->odometer->list(self::OWNER, $vehicle->getUuid())[0]->getUuid();
		$elsewhere = $this->imported($this->vehicle(['energy_types' => ['diesel']]), 'lubelogger-fuel.csv');

		$this->assertUndoRefused($vehicle, [['type' => 'odometer', 'uuid' => $fillUpsReading]]);
		$this->assertUndoRefused($vehicle, [...$done['created'], ...$elsewhere['created']]);
		$this->assertCount(2, $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
	}

	/** @return iterable<string, array{mixed}> */
	public static function unreadableUndos(): iterable {
		yield 'no list' => [null];
		yield 'an empty list' => [[]];
		yield 'a map' => [['a' => ['type' => 'energy', 'uuid' => 'u']]];
		yield 'a trip' => [[['type' => 'trip', 'uuid' => 'u']]];
		yield 'no uuid' => [[['type' => 'energy']]];
		yield 'an entry twice' => [[['type' => 'energy', 'uuid' => 'u'], ['type' => 'energy', 'uuid' => 'u']]];
	}

	/** @dataProvider unreadableUndos */
	public function testAnUndoThatNamesNoImportsListIsRefused(mixed $created): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);

		$this->expectException(\InvalidArgumentException::class);
		$this->import->undo(self::OWNER, $vehicle->getUuid(), ['created' => $created]);
	}

	/** Undoing writes as importing does: a driver's `log` does not cover it. */
	public function testADriverMayNotUndoAnImport(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$done = $this->imported($vehicle, 'lubelogger-fuel.csv');
		$this->grant($vehicle, self::OTHER, 'driver');

		$this->expectException(AccessDeniedException::class);
		$this->import->undo(self::OTHER, $vehicle->getUuid(), ['created' => $done['created']]);
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function recordTypes(): iterable {
		// The vehicle is kept in euros; the English file's dollars would be refused.
		yield 'service' => ['lubelogger-service-de.csv', 'service', 'maintenance'];
		yield 'tax' => ['lubelogger-tax.csv', 'tax', 'expense'];
		yield 'supplies' => ['lubelogger-supplies.csv', 'supplies', 'expense'];
		yield 'odometer' => ['lubelogger-odometer.csv', 'odometer', 'odometer'];
	}

	/**
	 * Each record type becomes the entry its kind is, never a trip.
	 *
	 * @dataProvider recordTypes
	 */
	public function testEachRecordTypeBecomesItsKindOfEntry(string $fixture, string $recordType, string $kind): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, $fixture);
		$etag = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file, recordType: $recordType))['etag'];

		$done = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, recordType: $recordType, answers: ['etag' => $etag]));

		$this->assertNotSame([], $done['created']);
		$this->assertSame([$kind], array_values(array_unique(array_column($done['created'], 'type'))));
		foreach ($done['created'] as $created) {
			$this->assertSame(self::OWNER, $this->entry($vehicle, $created)['created_by']);
		}

		$this->import->undo(self::OWNER, $vehicle->getUuid(), ['created' => $done['created']]);
		$this->assertSame([], $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * Years of weekly fill-ups in one request, inside the web server's default 30 seconds. The
	 * Readings are settled once, afterwards, as if each had been entered by hand: the counter
	 * swapped halfway is flagged, not refused, and the vehicle shows the newest value.
	 */
	public function testFiveThousandFillUpsImportInOneRequest(): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$rows = ['Date,Odometer,FuelConsumed,Cost'];
		$day = new \DateTimeImmutable('2000-01-03');
		for ($i = 0; $i < 5000; $i++) {
			// Row 2502 is a cluster swap: a counter lower than the one before it.
			$odo = $i === 2500 ? 100 : 1000 + 500 * $i;
			$rows[] = $day->modify("+$i days")->format('Y-m-d') . ",$odo,40.5,61.20";
		}
		$file = $this->file(self::OWNER, 'fuel.csv', implode("\n", $rows) . "\n");
		$etag = $this->import->preview(self::OWNER, $vehicle->getUuid(), $this->request($file))['etag'];

		$started = microtime(true);
		$done = $this->import->import(self::OWNER, $vehicle->getUuid(), $this->request($file, answers: ['etag' => $etag]));
		$this->assertLessThan(30, microtime(true) - $started);

		$this->assertCount(5000, $done['created']);
		$readings = $this->odometer->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(5000, $readings);
		$flagged = array_values(array_filter($readings, static fn ($reading): bool => $reading->getFlagged()));
		$this->assertSame([100], array_map(static fn ($reading): int => $reading->getValue(), $flagged));
		$this->assertSame(1000 + 500 * 4999, $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());

		// And taken back in one request, as straight after the import the screen offers it.
		$started = microtime(true);
		$this->import->undo(self::OWNER, $vehicle->getUuid(), ['created' => $done['created']]);
		$this->assertLessThan(30, microtime(true) - $started);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
		$this->assertNull($this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function unreadableRequests(): iterable {
		yield 'no importer' => [['importer' => null]];
		yield 'an unknown importer' => [['importer' => 'drivvo']];
		yield 'an unknown record type' => [['record_type' => 'trips']];
		yield 'a word for a file' => [['file_id' => 'abc']];
		yield 'no units' => [['units' => null]];
		yield 'a unit of nothing' => [['units' => ['distance' => 'furlong', 'volume' => 'l']]];
		yield 'no zone' => [['tz' => null]];
		yield 'a date order of nothing' => [['date_order' => 'ymd']];
		yield 'include_duplicates as a word' => [['include_duplicates' => 'maybe']];
	}

	/**
	 * @dataProvider unreadableRequests
	 * @param array<string, mixed> $fields
	 */
	public function testARequestThatSaysNothingUsableIsRefused(array $fields): void {
		$vehicle = $this->vehicle(['energy_types' => ['diesel']]);
		$file = $this->file(self::OWNER, 'lubelogger-fuel.csv');

		$this->expectException(\InvalidArgumentException::class);
		$this->import->preview(self::OWNER, $vehicle->getUuid(), array_merge($this->request($file), $fields));
	}

	/**
	 * What a fuel preview sends, the units the fixture's README names.
	 *
	 * @param array<string, mixed> $answers
	 * @return array<string, mixed>
	 */
	private function request(\OCP\Files\File $file, string $importer = 'lubelogger', string $recordType = 'fuel', array $answers = []): array {
		return $answers + [
			'file_id' => $file->getId(),
			'importer' => $importer,
			'record_type' => $recordType,
			'units' => ['distance' => 'km', 'volume' => 'l'],
			'tz' => 'Europe/Berlin',
		];
	}

	/**
	 * A fixture previewed and imported as the screen does it.
	 *
	 * @return array{counts: array<string, int>, created: list<array{type: string, uuid: string}>}
	 */
	private function imported(Vehicle $vehicle, string $fixture, string $recordType = 'fuel', string $uid = self::OWNER): array {
		$file = $this->file($uid, $fixture);
		$etag = $this->import->preview($uid, $vehicle->getUuid(), $this->request($file, recordType: $recordType))['etag'];

		return $this->import->import($uid, $vehicle->getUuid(), $this->request($file, recordType: $recordType, answers: ['etag' => $etag]));
	}

	/**
	 * The undo is refused whole: every entry it names that was live is live still.
	 *
	 * @param list<array{type: string, uuid: string}> $created
	 */
	private function assertUndoRefused(Vehicle $vehicle, array $created): void {
		$live = $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows'];
		try {
			$this->import->undo(self::OWNER, $vehicle->getUuid(), ['created' => $created]);
			$this->fail('undid a list that is not what an import of the owner\'s left');
		} catch (ImportChangedException) {
		}
		$this->assertEquals($live, $this->timeline->page(self::OWNER, $vehicle->getUuid(), null, null)['rows']);
	}

	/**
	 * One entry an import answered with, as the timeline sends it.
	 *
	 * @param array{type: string, uuid: string} $created
	 * @return array<string, mixed>
	 */
	private function entry(Vehicle $vehicle, array $created): array {
		$row = $this->timeline->one(self::OWNER, $vehicle->getUuid(), $created['type'], $created['uuid']);

		return json_decode((string)json_encode($row[$created['type']]), true);
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(array $fields = [], string $owner = self::OWNER): Vehicle {
		$vehicle = $this->vehicles->create($owner, $fields + ['plate' => 'B-IM 1', 'currency' => 'EUR']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	/** A fixture copied into the account's own Files, as the Files app puts an upload. */
	private function file(string $uid, string $fixture, ?string $content = null): \OCP\Files\File {
		return $this->folder($uid)->newFile($fixture, $content ?? (string)file_get_contents(self::FIXTURES . $fixture));
	}

	/** A folder of its own for each call, since the accounts outlive a test. */
	private function folder(string $uid): Folder {
		return \OCP\Server::get(IRootFolder::class)->getUserFolder($uid)->newFolder(bin2hex(random_bytes(6)));
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
