<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * M3's rows through their mappers and back: every property lands in a column that exists and
 * comes back as it went in. No service writes these tables yet, so this is the only proof.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class CostMappersTest extends TestCase {
	private const OWNER = 'nextfleet-test-costs';

	protected function setUp(): void {
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_odo_readings'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/** @template T of object @param class-string<T> $class @return T */
	private function get(string $class): object {
		return (new Application())->getContainer()->get($class);
	}

	public function testAFillUpRoundTrips(): void {
		$energy = new Energy();
		$energy->setVehicleId(1);
		$energy->setFilledAt(1758240000);
		$energy->setFilledAtOff(7200);
		$energy->setOdo(51234);
		$energy->setEnergy('electric');
		$energy->setAmount(42500);
		$energy->setTotal(1870);
		$energy->setVatRate(1900);
		$energy->setFullTank(true);
		$energy->setIsDc(true);
		$energy->setLocationKind('public');
		$energy->setStation('Ionity A9');
		$energy->setCreatedBy(self::OWNER);

		$mapper = $this->get(EnergyMapper::class);
		$written = $mapper->insert($energy);
		$read = $mapper->findByUuid($written->getUuid());

		$this->assertSame($written->jsonSerialize(), $read->jsonSerialize());
		$this->assertNull($read->getSecondOdo());
		$this->assertNull($read->getUnitPrice());
		$this->assertFalse($read->getMissedPrevious());
	}

	public function testMaintenanceRoundTrips(): void {
		$maintenance = new Maintenance();
		$maintenance->setVehicleId(1);
		$maintenance->setDoneAt(1758240000);
		$maintenance->setDoneAtOff(0);
		$maintenance->setSecondOdo(1234);
		$maintenance->setTitle('Oil change');
		$maintenance->setType('service');
		$maintenance->setCost(12900);
		$maintenance->setCreatedBy(self::OWNER);

		$mapper = $this->get(MaintenanceMapper::class);
		$written = $mapper->insert($maintenance);

		$this->assertSame($written->jsonSerialize(), $mapper->findByUuid($written->getUuid())->jsonSerialize());
	}

	public function testAnExpenseKeepsAStatedZeroRateApartFromNone(): void {
		$mapper = $this->get(ExpenseMapper::class);

		$zero = new Expense();
		$zero->setVehicleId(1);
		$zero->setSpentAt(1758240000);
		$zero->setSpentAtOff(0);
		$zero->setAmount(850);
		$zero->setVatRate(0);
		$zero->setCategory('toll');
		$zero->setCreatedBy(self::OWNER);
		$zero = $mapper->insert($zero);

		$none = new Expense();
		$none->setVehicleId(1);
		$none->setSpentAt(1758240000);
		$none->setSpentAtOff(0);
		$none->setAmount(850);
		$none->setCreatedBy(self::OWNER);
		$none = $mapper->insert($none);

		$this->assertSame(0, $mapper->findByUuid($zero->getUuid())->getVatRate());
		$this->assertNull($mapper->findByUuid($none->getUuid())->getVatRate());
	}

	/**
	 * A period is half-open and leaves out deleted rows and other vehicles' - what CostService sums.
	 * The vehicle id is one no seeded vehicle has, so the demo fleet cannot leak in.
	 */
	public function testEachCostTableFindsTheLiveRowsOfOnePeriod(): void {
		$vehicleId = 987654;
		foreach ([
			[EnergyMapper::class, static function (int $vehicle, int $at): Energy {
				$row = new Energy();
				$row->setVehicleId($vehicle);
				$row->setFilledAt($at);
				$row->setFilledAtOff(0);
				$row->setEnergy('diesel');
				$row->setAmount(1000);
				return $row;
			}],
			[MaintenanceMapper::class, static function (int $vehicle, int $at): Maintenance {
				$row = new Maintenance();
				$row->setVehicleId($vehicle);
				$row->setDoneAt($at);
				$row->setDoneAtOff(0);
				$row->setTitle('Oil change');
				return $row;
			}],
			[ExpenseMapper::class, static function (int $vehicle, int $at): Expense {
				$row = new Expense();
				$row->setVehicleId($vehicle);
				$row->setSpentAt($at);
				$row->setSpentAtOff(0);
				$row->setAmount(850);
				return $row;
			}],
		] as [$class, $make]) {
			$mapper = $this->get($class);
			$insert = function (int $vehicle, int $at, bool $deleted = false) use ($mapper, $make) {
				$row = $make($vehicle, $at);
				$row->setCreatedBy(self::OWNER);
				if ($deleted) {
					$row->setDeletedAt(1);
				}
				return $mapper->insert($row);
			};
			$insert($vehicleId, 999);
			$later = $insert($vehicleId, 1500);
			$first = $insert($vehicleId, 1000);
			$insert($vehicleId, 1200, deleted: true);
			$insert($vehicleId + 1, 1200);
			$insert($vehicleId, 2000);

			$this->assertSame(
				[$first->getUuid(), $later->getUuid()],
				array_map(static fn ($row) => $row->getUuid(), $mapper->findBetween($vehicleId, 1000, 2000)),
				$class,
			);
		}
	}

	/**
	 * A Reading written the way M2 writes one - no counter named - is on the main counter.
	 */
	public function testAReadingThatNamesNoCounterIsOnTheMainOne(): void {
		$reading = new OdoReading();
		$reading->setVehicleId(1);
		$reading->setReadAt(1758240000);
		$reading->setReadAtOff(0);
		$reading->setValue(100);
		$reading->setKind('reading');
		$reading->setOrigin('observed');
		$reading->setSourceType(OdoReading::MANUAL);
		$reading->setCreatedBy(self::OWNER);

		$mapper = $this->get(OdoReadingMapper::class);
		$written = $mapper->insert($reading);

		$this->assertSame(OdoReading::MAIN, $mapper->findByUuid($written->getUuid())->getCounter());
	}
}
