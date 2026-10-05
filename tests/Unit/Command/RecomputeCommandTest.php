<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\RecomputeCommand;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\OdometerService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--all` stopped partway, which the integration suite cannot provoke. The happy paths are
 * Integration\RecomputeCommandTest's.
 */
class RecomputeCommandTest extends TestCase {
	/** Each vehicle commits on its own, so what was settled before the failure is printed. */
	public function testAFailurePrintsWhatWasSettledAndExitsOne(): void {
		[$settled, $gone, $failing, $never] = array_map(self::vehicle(...), ['a', 'b', 'c', 'd']);
		$mapper = $this->createMock(VehicleMapper::class);
		$mapper->method('findForAdmin')->with(null, null)->willReturn([$settled, $gone, $failing, $never]);
		$odometer = $this->createMock(OdometerService::class);
		$odometer->expects($this->exactly(3))->method('recompute')->willReturnCallback(static fn (Vehicle $vehicle): array => match ($vehicle) {
			$settled => [['counter' => OdoReading::MAIN, 'table' => 'fleet_vehicles', 'row' => 'a', 'column' => 'odo_value', 'before' => 1, 'after' => 2]],
			$gone => throw new DoesNotExistException('gone'),
			default => throw new \RuntimeException('database went away'),
		});
		$command = new CommandTester(new RecomputeCommand($mapper, $odometer));

		$this->assertSame(1, $command->execute(['--all' => true, '--output' => 'json'], ['capture_stderr_separately' => true]));

		$this->assertSame([['vehicle' => 'a', 'table' => 'fleet_vehicles', 'row' => 'a', 'column' => 'odo_value', 'before' => 1, 'after' => 2]], json_decode($command->getDisplay(), true));
		$this->assertStringContainsString('Stopped at vehicle c: database went away', $command->getErrorOutput());
	}

	private static function vehicle(string $uuid): Vehicle {
		$vehicle = new Vehicle();
		$vehicle->setUuid($uuid);

		return $vehicle;
	}
}
