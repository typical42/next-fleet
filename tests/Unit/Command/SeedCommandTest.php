<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\SeedCommand;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The demo fleet, written through the same service the sheet writes through: what it invents has
 * to be a fleet the app itself could have been given.
 */
class SeedCommandTest extends TestCase {
	private const OWNER = 'alice';

	/** The vehicles the mapper was handed, in the order the command wrote them. @var list<Vehicle> */
	private array $written = [];
	/** The ones a read still reaches, by uuid. @var array<string, Vehicle> */
	private array $live = [];
	/** Every reading the odometer was asked to record, by vehicle uuid. @var array<string, list<array<string, int>>> */
	private array $recorded = [];
	private int $nextId = 1;

	private IUserManager&MockObject $users;
	private VehicleMapper&MockObject $mapper;
	private OdometerService&MockObject $odometer;

	protected function setUp(): void {
		$this->written = [];
		$this->nextId = 1;

		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(
			static fn (string $userId): bool => $userId === self::OWNER,
		);

		$this->mapper = $this->createMock(VehicleMapper::class);
		$this->mapper->method('insert')->willReturnCallback(function (Vehicle $vehicle): Vehicle {
			// The identity is the base mapper's (BaseMapperTest); without it the command has
			// no uuid to hang the readings off.
			$vehicle->setId($this->nextId);
			$vehicle->setUuid('0195e2f1-0000-4000-8000-00000000000' . $this->nextId++);
			$this->written[] = $vehicle;

			return $vehicle;
		});
		$this->mapper->method('findAllVisible')->willReturnCallback(fn (): array => $this->written);

		$this->odometer = $this->createMock(OdometerService::class);
	}

	/** What the command wrote, by plate. @return array<string, Vehicle> */
	private function fleet(): array {
		$byPlate = [];
		foreach ($this->written as $vehicle) {
			$byPlate[(string)$vehicle->getPlate()] = $vehicle;
		}

		return $byPlate;
	}

	private function tester(): CommandTester {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnArgument(3);

		$access = $this->createMock(VehicleAccess::class);
		$access->method('may')->willReturn(true);
		$access->method('reachableVehicleIds')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);

		// The real service, because what this command is worth is that the fleet it invents
		// passes the validation every other write passes.
		$fleet = new VehicleService($this->mapper, $access, $config);

		return new CommandTester(new SeedCommand($this->users, $fleet, $this->odometer, $time));
	}

	/** The name `occ` lists it under, which docs/development.md#testing names too. */
	public function testIsCalledNextfleetSeed(): void {
		$command = new SeedCommand(
			$this->users,
			$this->createMock(VehicleService::class),
			$this->odometer,
			$this->createMock(ITimeFactory::class),
		);

		$this->assertSame('nextfleet:seed', $command->getName());
	}

	/**
	 * A uid nobody has is a typo, and seeding it would write a demo fleet no screen can reach.
	 */
	public function testAUserThatDoesNotExistIsRefusedBeforeAnythingIsWritten(): void {
		$this->mapper->expects($this->never())->method('insert');

		$tester = $this->tester();

		$this->assertSame(1, $tester->execute(['user' => 'nobody']));
		$this->assertStringContainsString('nobody', $tester->getDisplay());
	}

	/**
	 * The fleet is one user's, and every row of it passes the validation a request passes -
	 * the service is the real one, so a vehicle_type or a plate the app would refuse fails here.
	 */
	public function testTheFleetIsWrittenForTheUserItWasAskedFor(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['user' => self::OWNER]));
		$this->assertNotEmpty($this->written);
		foreach ($this->written as $vehicle) {
			$this->assertSame(self::OWNER, $vehicle->getUserId());
			$this->assertSame(self::OWNER, $vehicle->getCreatedBy());
			$this->assertNotEmpty((string)$vehicle->getPlate(), 'a demo vehicle without a plate');
			$this->assertStringContainsString((string)$vehicle->getPlate(), $tester->getDisplay());
		}
	}

	/**
	 * The rows that are awkward on purpose (docs/development.md#testing): a counter that is not
	 * kilometres, and a plug-in hybrid, which is the vehicle `energy_types` exists for
	 * (docs/architecture.md#data-model).
	 */
	public function testTheFleetHoldsTheVehiclesThatAreAwkwardOnPurpose(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$units = [];
		$hybrids = [];
		foreach ($this->written as $vehicle) {
			$units[] = $vehicle->getOdoUnit();
			if ($vehicle->getEngine() === 'hybrid') {
				$hybrids[] = $vehicle->getEnergyTypes();
			}
		}

		$this->assertContains('h', $units, 'nothing in the demo fleet is counted in hours');
		$this->assertContains(['petrol', 'electric'], $hybrids);
	}

	/** All three of docs/architecture.md's lifecycles, because the overview treats each one differently. */
	public function testTheFleetHoldsOneVehicleOfEachLifecycle(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$lifecycles = array_map(
			static fn (Vehicle $vehicle): string => $vehicle->getLifecycle(),
			$this->written,
		);

		$this->assertSame(['active', 'disposed', 'laid_up'], array_values(array_unique(
			$this->sorted($lifecycles),
		)));
	}

	/**
	 * @param list<string> $values
	 * @return list<string>
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}
}
