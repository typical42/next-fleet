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
use OCA\NextFleet\Import\Importers;
use OCA\NextFleet\Import\LubeLoggerImporter;
use OCA\NextFleet\Import\SpritmonitorImporter;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\OwnFiles;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use OCA\NextFleet\Tests\Stub\Untranslated;
use OCP\Files\File;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * What an import tells the log. What it writes is tests/Integration/ImportTest's.
 */
class ImportServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';

	public function testAnImportIsOneInfoLineNamingWhoWhatAndHowManyRows(): void {
		$logger = new SpyLogger();
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn(Vehicle::fromRow(['id' => 7, 'uuid' => self::VEHICLE, 'plate' => 'B-XY 123', 'currency' => 'USD', 'energy_types' => '["petrol"]']));
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(400);
		$file->method('getEtag')->willReturn('abc');
		$files = $this->createMock(OwnFiles::class);
		$files->method('find')->willReturn($file);
		$files->method('open')->willReturnCallback(static fn () => fopen(__DIR__ . '/../../Fixture/import/lubelogger-fuel.csv', 'rb'));
		$energy = $this->createMock(EnergyService::class);
		$energy->method('add')->willReturnCallback(static fn (): array => ['uuid' => bin2hex(random_bytes(4))]);
		$odometer = $this->createMock(OdometerService::class);
		$odometer->method('batch')->willReturnCallback(static fn (Vehicle $vehicle, \Closure $writes): mixed => $writes());

		$service = new ImportService(
			$fleet,
			new Importers(new LubeLoggerImporter(new Untranslated()), new SpritmonitorImporter(new Untranslated())),
			$files,
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$this->createMock(ExpenseMapper::class),
			$this->createMock(OdoReadingMapper::class),
			$energy,
			$this->createMock(MaintenanceService::class),
			$this->createMock(ExpenseService::class),
			$odometer,
			$this->createMock(VehicleMapper::class),
			$this->createMock(IDBConnection::class),
			$logger,
		);
		$service->import('alice', self::VEHICLE, [
			'file_id' => 42,
			'importer' => 'lubelogger',
			'record_type' => 'fuel',
			'units' => ['distance' => 'mi', 'volume' => 'us_gal'],
			'tz' => 'America/Chicago',
			'energy' => 'petrol',
			'etag' => 'abc',
		]);

		// The fixture's two readable rows; the unreadable ones were not written, so not counted.
		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'Import',
			'context' => ['app' => 'nextfleet', 'user' => 'alice', 'vehicle' => self::VEHICLE, 'importer' => 'lubelogger', 'record_type' => 'fuel', 'rows' => 2],
		]], $logger->lines);
	}
}
