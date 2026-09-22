<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Command\SeedCommand;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The demo fleet, written through the real services and read back as the vehicle header reads
 * it: the costs it seeds have to add up to the figures the screenshots and the E2E expect.
 *
 * It seeds `admin`, the account the E2E logs in as, so it leaves the fleet the suite would
 * otherwise have to put back (docs/development.md#testing).
 */
class SeedTest extends TestCase {
	private const USER = 'admin';

	/** @var array<string, array<string, mixed>> the header's figures over the last year, by plate */
	private static array $kpis = [];
	/** @var array<string, int> flagged Readings, by plate */
	private static array $flagged = [];

	public static function setUpBeforeClass(): void {
		$container = (new Application())->getContainer();
		$tester = new CommandTester($container->get(SeedCommand::class));
		self::assertSame(0, $tester->execute(['user' => self::USER]), $tester->getDisplay());

		$vehicles = $container->get(VehicleService::class);
		$kpis = $container->get(KpiService::class);
		$odometer = $container->get(OdometerService::class);
		$now = $container->get(ITimeFactory::class)->getTime();
		foreach ($vehicles->list(self::USER) as $vehicle) {
			$plate = (string)$vehicle->getPlate();
			if (!str_starts_with($plate, 'NF-')) {
				continue;
			}
			self::$kpis[$plate] = $kpis->of(self::USER, $vehicle->getUuid(), ['from' => $now - 365 * 86400, 'to' => $now + 1, 'net' => 'true']);
			self::$flagged[$plate] = count(array_filter(
				$odometer->list(self::USER, $vehicle->getUuid()),
				static fn ($reading): bool => $reading->getFlagged(),
			));
		}
	}

	/**
	 * The only flags are the two the fleet definition promises: the derived row the Passat's last
	 * reading contradicts, and the hybrid's cluster swap. A cost row's counter that broke a chain
	 * would show up here.
	 */
	public function testNoCostRowFlagsAReading(): void {
		$this->assertSame(1, self::$flagged['NF-DE 100']);
		$this->assertSame(1, self::$flagged['NF-PH 200']);
		$this->assertSame(0, self::$flagged['NF-LW 300']);
		$this->assertSame(0, self::$flagged['NF-LK 700']);
	}

	/** Consumption per energy, in the unit the counter counts. */
	public function testEachFuelledVehicleStatesItsConsumption(): void {
		$this->assertSame(['diesel'], $this->energies('NF-DE 100'));
		$this->assertSame(['electric', 'petrol'], $this->energies('NF-PH 200'));
		$this->assertSame(['diesel'], $this->energies('NF-LW 300'));
		$this->assertSame('h', self::$kpis['NF-LW 300']['consumption'][0]['per']);
		$this->assertSame(['diesel'], $this->energies('NF-LK 700'));
		$this->assertSame('km', self::$kpis['NF-LK 700']['consumption'][0]['per']);
	}

	/** The partial and the missed previous leave the Passat at a plausible diesel figure. */
	public function testThePassatBurnsWhatAPassatBurns(): void {
		$value = self::$kpis['NF-DE 100']['consumption'][0]['value'];

		$this->assertGreaterThan(5.5, $value);
		$this->assertLessThan(6.5, $value);
	}

	public function testTheHybridHasAWallSideFigure(): void {
		$this->assertNotNull(self::$kpis['NF-PH 200']['wall_side']);
	}

	/** Net of VAT, with the rows nobody stated a rate for counted gross and said so. */
	public function testCostPerDistanceIsNetAndSaysWhereItIsNot(): void {
		$cost = self::$kpis['NF-DE 100']['cost'];

		$this->assertTrue($cost['net']);
		$this->assertTrue($cost['unstated']);
		$this->assertFalse($cost['incomplete']);
		$this->assertNotNull($cost['value']);
		$this->assertNotNull($cost['energy_value']);
		$this->assertNotNull($cost['tco']);
	}

	public function testTheTrailerStatesItsCostsAsATotal(): void {
		$cost = self::$kpis['NF-AH 400']['cost'];

		$this->assertNull($cost['distance']);
		$this->assertSame(16130, $cost['total']);
	}

	public function testTheTruckStatesTheHoursItRan(): void {
		$this->assertSame(248, self::$kpis['NF-LK 700']['hours']);
	}

	/** @return list<string> */
	private function energies(string $plate): array {
		$energies = array_values(array_unique(array_column(self::$kpis[$plate]['consumption'], 'energy')));
		sort($energies);

		return $energies;
	}
}
