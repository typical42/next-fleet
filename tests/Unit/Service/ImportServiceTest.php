<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Import\Importers;
use OCA\NextFleet\Import\LubeLoggerImporter;
use OCA\NextFleet\Import\SpritmonitorImporter;
use OCA\NextFleet\Service\AfterCommit;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\OwnFiles;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use OCA\NextFleet\Tests\Stub\Untranslated;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * What an import tells the log, and what it decides before an entry service sees a row. What it
 * writes is tests/Integration/ImportTest's.
 */
class ImportServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const LUBELOGGER = ['file_id' => 42, 'importer' => 'lubelogger', 'record_type' => 'fuel', 'units' => ['distance' => 'mi', 'volume' => 'us_gal'], 'tz' => 'America/Chicago', 'energy' => 'petrol'];
	private const SPRITMONITOR = ['file_id' => 42, 'importer' => 'spritmonitor', 'record_type' => 'fuel', 'units' => ['distance' => 'km', 'volume' => 'l'], 'tz' => 'Europe/Berlin'];

	private SpyLogger $logger;
	private EnergyService&MockObject $energy;

	protected function setUp(): void {
		$this->logger = new SpyLogger();
		$this->energy = $this->createMock(EnergyService::class);
		$this->energy->method('add')->willReturnCallback(static fn (): array => ['uuid' => bin2hex(random_bytes(4))]);
	}

	private function file(string $etag): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(400);
		$file->method('getEtag')->willReturn($etag);

		return $file;
	}

	/**
	 * @param string $fixture a file under tests/Fixture/import/
	 * @param list<string> $etags what each look-up of the file finds, the last one kept
	 */
	private function service(string $fixture, array $etags = ['abc'], string $energies = '["petrol"]'): ImportService {
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn(Vehicle::fromRow(['id' => 7, 'uuid' => self::VEHICLE, 'plate' => 'B-XY 123', 'currency' => 'EUR', 'energy_types' => $energies]));
		$files = $this->createMock(OwnFiles::class);
		$found = array_map($this->file(...), $etags);
		$files->method('find')->willReturnCallback(static function () use (&$found): File {
			return count($found) > 1 ? array_shift($found) : $found[0];
		});
		$files->method('open')->willReturnCallback(static fn () => fopen(__DIR__ . '/../../Fixture/import/' . $fixture, 'rb'));
		$odometer = $this->createMock(OdometerService::class);
		$odometer->method('batch')->willReturnCallback(static fn (Vehicle $vehicle, \Closure $writes): mixed => $writes());
		// Remembers nothing, so every import is a first one.
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new ImportService(
			$fleet,
			new Importers(new LubeLoggerImporter(new Untranslated()), new SpritmonitorImporter(new Untranslated())),
			$files,
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$this->createMock(ExpenseMapper::class),
			$this->createMock(OdoReadingMapper::class),
			$this->energy,
			$this->createMock(MaintenanceService::class),
			$this->createMock(ExpenseService::class),
			$odometer,
			$this->createMock(VehicleMapper::class),
			new AfterCommit($this->createMock(IDBConnection::class), $this->logger),
			$this->logger,
			$caches,
			$this->createMock(ITimeFactory::class),
		);
	}

	public function testAnImportIsOneInfoLineNamingWhoWhatAndHowManyRows(): void {
		$this->service('lubelogger-fuel.csv')->import('alice', self::VEHICLE, self::LUBELOGGER + ['etag' => 'abc']);

		// The fixture's two readable rows; the unreadable ones were not written, so not counted.
		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'Import',
			'context' => ['app' => 'nextfleet', 'user' => 'alice', 'vehicle' => self::VEHICLE, 'importer' => 'lubelogger', 'record_type' => 'fuel', 'rows' => 2],
		]], $this->logger->lines);
	}

	/**
	 * Diesel on a petrol car is a fill-up the entry sheet takes and flags (EnergyService), so the
	 * import takes it too rather than leave a real expense out.
	 */
	public function testAFillUpOfAnEnergyTheVehicleDoesNotTakeIsImported(): void {
		$service = $this->service('spritmonitor-fuel.csv', energies: '["petrol"]');

		$preview = $service->preview('alice', self::VEHICLE, self::SPRITMONITOR);
		$this->energy->expects($this->exactly(3))->method('add');
		$service->import('alice', self::VEHICLE, self::SPRITMONITOR + ['etag' => 'abc']);

		$this->assertSame(['new' => 3, 'duplicate' => 0, 'unreadable' => 2, 'creates' => 3], $preview['counts']);
		$this->assertSame('diesel', ((array)$preview['proposals'][0]['fields'])['energy']);
	}

	/**
	 * The etag is checked again once the file is read: a client that wrote it in between would
	 * otherwise have the import write rows nobody previewed.
	 */
	public function testAFileWrittenWhileItIsReadIsNotImported(): void {
		$this->energy->expects($this->never())->method('add');

		$this->expectException(FileChangedException::class);
		$this->service('lubelogger-fuel.csv', ['abc', 'def'])->import('alice', self::VEHICLE, self::LUBELOGGER + ['etag' => 'abc']);
	}
}
