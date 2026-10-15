<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\EnteredBy;
use OCA\NextFleet\Service\ExportService;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\SpyLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * What the CSV export tells the log and what it calls the file. Which rows a file holds is
 * tests/Integration/ExportTest's.
 */
class ExportServiceTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';

	public function testAnExportIsOneInfoLineNamingWhoWhatAndHowManyRows(): void {
		$logger = new SpyLogger();
		$expenses = $this->createMock(ExpenseMapper::class);
		// The window reaches into the next year, so a row of New Year's Eve is caught and left out.
		$expenses->method('findBetween')->willReturn([
			self::expense('Parking at the Theater', gmmktime(10, 0, 0, 3, 1, 2026)),
			self::expense('Car wash', gmmktime(10, 0, 0, 6, 1, 2026)),
			self::expense('Toll', gmmktime(2, 0, 0, 1, 1, 2027)),
		]);

		$this->service($expenses, $logger)->csv('alice', self::VEHICLE, 2026, 'expenses');

		$this->assertSame([[
			'level' => LogLevel::INFO,
			'message' => 'CSV export',
			'context' => ['app' => 'nextfleet', 'user' => 'alice', 'vehicle' => self::VEHICLE, 'year' => 2026, 'table' => 'expenses', 'rows' => 2],
		]], $logger->lines);
	}

	/** The route takes any word for the table; one with no file is a 400, and nothing was handed out. */
	public function testATableWithNoFileIsRefusedAndNotLogged(): void {
		$logger = new SpyLogger();

		try {
			$this->service($this->createMock(ExpenseMapper::class), $logger)->csv('alice', self::VEHICLE, 2026, 'readings');
			$this->fail('exported');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('No such export', $e->getMessage());
		}
		$this->assertSame([], $logger->lines);
	}

	/**
	 * The file is called by the vehicle's name, as a notice calls it: the plate, else the display
	 * name, else make and model. What a file system could misread goes; with nothing left, the uuid.
	 *
	 * @param array<string, string> $vehicle
	 */
	#[DataProvider('names')]
	public function testTheFileIsCalledByTheVehiclesName(array $vehicle, string $name): void {
		$file = $this->service($this->createMock(ExpenseMapper::class), new SpyLogger(), $vehicle)->csv('alice', self::VEHICLE, 2026, 'expenses');

		$this->assertSame($name, $file['name']);
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function names(): array {
		return [
			'the plate' => [['plate' => 'B-XY 123', 'display_name' => 'Family van'], 'B-XY_123-2026-expenses.csv'],
			'the display name' => [['display_name' => 'Family van', 'manufacturer' => 'VW'], 'Family_van-2026-expenses.csv'],
			'make and model' => [['manufacturer' => 'VW', 'model' => 'Caddy'], 'VW_Caddy-2026-expenses.csv'],
			'a letter a file system could misread' => [['display_name' => 'Käfer'], 'K_fer-2026-expenses.csv'],
			'nothing left of the name' => [['display_name' => '☀ ☀'], self::VEHICLE . '-2026-expenses.csv'],
			'no name at all' => [[], self::VEHICLE . '-2026-expenses.csv'],
		];
	}

	/** @param array<string, string> $vehicle */
	private function service(ExpenseMapper $expenses, SpyLogger $logger, array $vehicle = ['plate' => 'B-XY 123']): ExportService {
		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturn(Vehicle::fromRow(['id' => 7, 'uuid' => self::VEHICLE, 'currency' => 'EUR'] + $vehicle));

		return new ExportService(
			$fleet,
			$this->createMock(TripMapper::class),
			$this->createMock(EnergyMapper::class),
			$this->createMock(MaintenanceMapper::class),
			$expenses,
			$this->createMock(EnteredBy::class),
			$logger,
		);
	}

	private static function expense(string $notes, int $spentAt): Expense {
		return Expense::fromRow(['id' => 1, 'uuid' => 'e-' . $spentAt, 'vehicle_id' => 7, 'spent_at' => $spentAt, 'spent_at_off' => 60, 'category' => 'other', 'amount' => 500, 'notes' => $notes]);
	}
}
