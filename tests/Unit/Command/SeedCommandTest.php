<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\SeedCommand;
use OCA\NextFleet\Command\SeedPapers;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\OdoReading;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\NotificationService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Stub\RegisteredProfiles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
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
	/** Every reminder the command planned, by vehicle uuid. @var array<string, list<array<string, mixed>>> */
	private array $planned = [];
	private int $nextId = 1;
	/**
	 * Every cost Entry the command wrote, as the sheet would have posted it: the kind, the plate
	 * and the fields.
	 *
	 * @var list<array{kind: string, plate: string, fields: array<string, mixed>}>
	 */
	private array $costs = [];

	private IUserManager&MockObject $users;
	private VehicleMapper&MockObject $mapper;
	private OdometerService&MockObject $odometer;
	private EnergyService&MockObject $energy;
	private MaintenanceService&MockObject $maintenance;
	private ExpenseService&MockObject $expenses;
	private ReminderService&MockObject $reminders;

	protected function setUp(): void {
		$this->written = [];
		$this->recorded = [];
		$this->planned = [];
		$this->costs = [];
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
		$this->odometer->method('record')->willReturnCallback(
			function (string $userId, string $uuid, array $fields): OdoReading {
				$this->recorded[$uuid][] = $fields;

				return new OdoReading();
			},
		);
		$this->energy = $this->createMock(EnergyService::class);
		$this->energy->method('record')->willReturnCallback($this->cost('energy'));
		$this->maintenance = $this->createMock(MaintenanceService::class);
		$this->maintenance->method('record')->willReturnCallback($this->cost('maintenance'));
		$this->expenses = $this->createMock(ExpenseService::class);
		$this->expenses->method('record')->willReturnCallback($this->cost('expense'));
		$this->reminders = $this->createMock(ReminderService::class);
		$this->reminders->method('create')->willReturnCallback(
			function (string $userId, string $uuid, array $fields): array {
				$this->planned[$uuid][] = $fields;

				return $fields;
			},
		);
	}

	/** @return \Closure(string, string, array<string, mixed>): array<string, mixed> */
	private function cost(string $kind): \Closure {
		return function (string $userId, string $uuid, array $fields) use ($kind): array {
			$this->costs[] = ['kind' => $kind, 'plate' => $this->plateOf($uuid), 'fields' => $fields];

			// A paper links to a maintenance record by the uuid its write answered.
			return $fields + ['uuid' => '0195e2f1-0000-4000-8000-' . str_pad((string)count($this->costs), 12, '0', STR_PAD_LEFT)];
		};
	}

	private function plateOf(string $uuid): string {
		foreach ($this->written as $vehicle) {
			if ($vehicle->getUuid() === $uuid) {
				return (string)$vehicle->getPlate();
			}
		}
		$this->fail('a cost on a vehicle the command did not write: ' . $uuid);
	}

	/**
	 * The cost Entries of one kind, optionally on one vehicle.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function costsOf(string $kind, ?string $plate = null): array {
		$fields = [];
		foreach ($this->costs as $cost) {
			if ($cost['kind'] === $kind && ($plate === null || $cost['plate'] === $plate)) {
				$fields[] = $cost['fields'];
			}
		}

		return $fields;
	}

	/** What the command wrote, by plate. @return array<string, Vehicle> */
	private function fleet(): array {
		$byPlate = [];
		foreach ($this->written as $vehicle) {
			$byPlate[(string)$vehicle->getPlate()] = $vehicle;
		}

		return $byPlate;
	}

	/** @param string $setting the jurisdiction the seeding account has chosen for itself */
	private function tester(string $setting = Jurisdictions::DEFAULT): CommandTester {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($setting);

		$access = $this->createMock(VehicleAccess::class);
		$access->method('may')->willReturn(true);
		$access->method('reachableVehicleIds')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);

		// The real service and the real registration list, because what this command is worth is
		// that the fleet it invents passes the validation every other write passes - and takes
		// the same country defaults.
		// The seeded fleet is created, never updated, so nothing here reaches the audit trail or
		// the transaction an update is written in.
		$fleet = new VehicleService(
			$this->mapper,
			$access,
			$config,
			RegisteredProfiles::jurisdictions(),
			$this->createMock(AuditMapper::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ReminderRecipientMapper::class),
			$this->createMock(ReminderMapper::class),
			$this->createMock(NotificationService::class),
		);

		return new CommandTester(new SeedCommand(
			$this->users,
			$fleet,
			$this->odometer,
			$this->energy,
			$this->maintenance,
			$this->expenses,
			$this->reminders,
			$this->createMock(TripService::class),
			$this->createMock(DocumentService::class),
			// SeedTest reads the trips and the papers back for real.
			$this->createMock(SeedPapers::class),
			$time,
		));
	}

	/** The name `occ` lists it under, which docs/development.md#testing names too. */
	public function testIsCalledNextfleetSeed(): void {
		$command = new SeedCommand(
			$this->users,
			$this->createMock(VehicleService::class),
			$this->odometer,
			$this->energy,
			$this->maintenance,
			$this->expenses,
			$this->reminders,
			$this->createMock(TripService::class),
			$this->createMock(DocumentService::class),
			$this->createMock(SeedPapers::class),
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

	/**
	 * One vehicle as somebody would have left it after creating it in four fields (docs/ui.md):
	 * no identity, no age, and no capacity for the energy it takes. That is what the "complete
	 * this vehicle" hint asks about (src/utils/complete.js), and the demo fleet is where the
	 * screen and the E2E find one to ask.
	 */
	public function testTheFleetHoldsOneVehicleTheHintHasSomethingToAskAbout(): void {
		$this->tester()->execute(['user' => self::OWNER]);

		$asked = [];
		foreach ($this->written as $vehicle) {
			if ($this->missingFrom($vehicle) !== []) {
				$asked[(string)$vehicle->getPlate()] = $this->missingFrom($vehicle);
			}
		}

		$this->assertSame(
			['NF-NE 600' => ['vin', 'first_reg', 'tank_ml']],
			$asked,
			'the demo fleet asks about the wrong vehicles',
		);
	}

	/**
	 * What the hint would ask this vehicle, by the rule the overview applies (src/utils/complete.js):
	 * its identity, its age, the capacity of whatever it is filled with, and its currency. Written out here
	 * because the rule is the frontend's and the fleet is this command's - the point of the test is
	 * that the two agree.
	 *
	 * @return list<string>
	 */
	private function missingFrom(Vehicle $vehicle): array {
		$capacity = [
			'petrol' => 'tank_ml',
			'diesel' => 'tank_ml',
			'lpg' => 'tank_ml',
			'cng' => 'tank_ml',
			'electric' => 'battery_wh',
		];
		$asked = ['vin' => $vehicle->getVin(), 'first_reg' => $vehicle->getFirstReg()];
		foreach ($vehicle->getEnergyTypes() ?? [] as $energy) {
			$asked[$capacity[$energy]] = $capacity[$energy] === 'tank_ml' ? $vehicle->getTankMl() : $vehicle->getBatteryWh();
		}
		$asked['currency'] = $vehicle->getCurrency();

		return array_keys(array_filter($asked, static fn (mixed $value): bool => $value === null));
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
	 * The fill-ups the consumption rules exist for (docs/architecture.md#numbers-consumption-cost-emissions):
	 * a partial one that is summed into its segment, and one after a fill-up nobody recorded,
	 * whose segment yields no number.
	 */
	public function testTheFillUpsHoldAPartialAndAMissedPrevious(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$fills = $this->costsOf('energy');

		$this->assertNotEmpty(array_filter($fills, static fn (array $fill): bool => $fill['full_tank'] === false));
		$this->assertNotEmpty(array_filter($fills, static fn (array $fill): bool => ($fill['missed_previous'] ?? false) === true));
		foreach ($fills as $fill) {
			// The service stores an absent flag as false; "on" is the sheet's default, not the DB's.
			$this->assertIsBool($fill['full_tank'], 'a fill-up that does not say whether it was full');
		}
	}

	/**
	 * The plug-in hybrid takes both its energies, and charges at home and in public - DC among the
	 * public ones - so its header has two figures and the wall-side one to show.
	 */
	public function testTheHybridFillsBothEnergiesAndChargesAtHomeAndInPublic(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$fills = $this->costsOf('energy', 'NF-PH 200');

		$this->assertSame(['electric', 'petrol'], $this->sorted(array_values(array_unique(array_column($fills, 'energy')))));
		$charges = array_filter($fills, static fn (array $fill): bool => $fill['energy'] === 'electric');
		$this->assertSame(['home', 'public'], $this->sorted(array_values(array_unique(array_column($charges, 'location_kind')))));
		$this->assertContains(true, array_column($charges, 'is_dc'));
		// A charge whose counter nobody read is the common case at home, and the sheet says what
		// it costs (docs/ui.md): a segment that ends on it yields no number.
		$this->assertNotEmpty(array_filter($charges, static fn (array $fill): bool => !isset($fill['odo'])));
	}

	/** A year of them, before the moment it runs - the period the header opens on. */
	public function testEveryCostFallsInTheYearBeforeItRuns(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$instants = ['energy' => 'filled_at', 'maintenance' => 'done_at', 'expense' => 'spent_at'];

		$this->assertNotEmpty($this->costsOf('maintenance'));
		$this->assertNotEmpty($this->costsOf('expense'));
		foreach ($this->costs as $cost) {
			$at = $cost['fields'][$instants[$cost['kind']]];
			$this->assertGreaterThan(1767225600 - 365 * 86400, $at);
			$this->assertLessThan(1767225600, $at);
			$this->assertIsInt($cost['fields'][$instants[$cost['kind']] . '_off']);
		}
	}

	/**
	 * Both answers to "which VAT rate": one stated, and one nobody stated, which the net figures
	 * count gross and say so. An insurance premium carries no VAT and states no rate, as the sheet
	 * prefills it (lib/Jurisdiction/De/RateProvider.php): a stated rate is never zero.
	 */
	public function testTheExpensesStateARateOrLeaveItUnstated(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$rates = array_map(static fn (array $expense): mixed => $expense['vat_rate'] ?? null, $this->costsOf('expense'));
		$insurance = array_filter($this->costsOf('expense'), static fn (array $expense): bool => $expense['category'] === 'insurance');

		$this->assertContains(null, $rates);
		$this->assertNotContains(0, $rates);
		$this->assertContains(1900, $rates);
		$this->assertNotEmpty($insurance);
		$this->assertSame([null], array_values(array_unique(array_map(static fn (array $expense): mixed => $expense['vat_rate'] ?? null, $insurance))));
	}

	/**
	 * A truck that also counts engine hours, with Readings on both chains and fill-ups that read
	 * both counters (docs/architecture.md#odometer-rules, rule 4).
	 */
	public function testTheTruckKeepsAnHourChainBesideItsKilometres(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$truck = $this->fleet()['NF-LK 700'] ?? null;

		$this->assertNotNull($truck, 'no truck in the demo fleet');
		$this->assertSame('truck', $truck->getVehicleType());
		$this->assertSame('km', $truck->getOdoUnit());
		$this->assertSame('h', $truck->getSecondUnit());
		$counters = array_map(
			static fn (array $reading): string => (string)($reading['counter'] ?? 'main'),
			$this->recorded[$truck->getUuid()] ?? [],
		);
		$this->assertContains('main', $counters);
		$this->assertContains('second', $counters);
		$this->assertNotEmpty(array_filter(
			$this->costsOf('energy', 'NF-LK 700'),
			static fn (array $fill): bool => isset($fill['odo'], $fill['second_odo']),
		));
	}

	/**
	 * The demo fleet is German (plan.md), whoever seeds it: its plates, its VAT rates and the
	 * HU/AU its reminders name all belong to one country. A profile without an inspection scheme
	 * offers no `hu_au` at all, so an account that had set another one would break the run
	 * halfway through.
	 */
	public function testTheFleetIsGermanWhateverTheAccountSeedingItHasChosen(): void {
		$tester = $this->tester('generic');

		$this->assertSame(0, $tester->execute(['user' => self::OWNER]), $tester->getDisplay());
		foreach ($this->written as $vehicle) {
			$this->assertSame('de', $vehicle->getJurisdiction(), (string)$vehicle->getPlate());
		}
	}

	/**
	 * A due date the fleet definition cannot hold either: a demo whose inspection fell due two
	 * years ago teaches nothing. The clock here reads 2026-01-01, so three weeks out is the 22nd,
	 * and a sticker names a month's end (docs/ui.md).
	 */
	public function testTheInspectionsAreDueThreeWeeksAndFiveMonthsOut(): void {
		$this->tester()->execute(['user' => self::OWNER]);

		$this->assertSame('2026-01-22', $this->plannedOn('NF-PH 200', 'hu_au')['due_date']);
		$this->assertSame('2026-06-30', $this->plannedOn('NF-LK 700', 'hu_au')['due_date']);
	}

	/**
	 * The interval each reminder recurs at is the template's, so the fleet states none of them: a
	 * demo that wrote 24 months down would go on saying 24 when the country changed its mind.
	 */
	public function testAReminderStatesWhenItIsDueAndLeavesTheIntervalToItsTemplate(): void {
		$this->tester()->execute(['user' => self::OWNER]);

		foreach ($this->planned as $reminders) {
			foreach ($reminders as $reminder) {
				$this->assertArrayNotHasKey('recur_months', $reminder);
				$this->assertArrayNotHasKey('recur_odo', $reminder);
				$this->assertArrayNotHasKey('lead_odo', $reminder);
				$this->assertArrayNotHasKey('title', $reminder, 'a template titles itself');
			}
		}
	}

	/**
	 * The oil change the banner estimates a date for: by kilometres, and still ahead of the
	 * counter, since an estimate is the day a pace reaches a km that is not reached yet.
	 */
	public function testTheOilChangeIsDueByKilometresAheadOfTheNewestReading(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$oil = $this->plannedOn('NF-DE 100', 'oil_change');

		$this->assertSame('odo', $oil['mode']);
		$this->assertArrayNotHasKey('due_date', $oil);
		$this->assertGreaterThan(max(array_column($this->readOn('NF-DE 100'), 'value')), $oil['due_odo']);
	}

	/**
	 * The pace that estimate needs (docs/architecture.md#reminder-engine, rule 5): two Readings
	 * somebody took, 30 days apart, inside the 90 days before the day it is read on. Inside the
	 * last 45 here, so the demo still estimates six weeks after it was seeded - the fleet is
	 * written once and clicked through for as long as it stands.
	 */
	public function testTheVehicleWithTheOilChangeKeepsAPaceForWeeksAfterTheRun(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$recent = array_column(array_filter(
			$this->readOn('NF-DE 100'),
			static fn (array $reading): bool => $reading['read_at'] >= 1767225600 - 45 * 86400,
		), 'read_at');

		$this->assertGreaterThanOrEqual(2, count($recent));
		$this->assertGreaterThanOrEqual(30 * 86400, max($recent) - min($recent));
	}

	/**
	 * The Readings on that vehicle somebody read off a counter: a derived one states a distance
	 * and is the row a later reading puts in question (rule 6), so no pace counts on it.
	 *
	 * @return array<int, array<string, int>>
	 */
	private function readOn(string $plate): array {
		return array_filter(
			$this->recorded[$this->fleet()[$plate]->getUuid()] ?? [],
			static fn (array $reading): bool => isset($reading['value']) && !isset($reading['counter']),
		);
	}

	/**
	 * What the command asked ReminderService to write on that vehicle.
	 *
	 * @return array<string, mixed>
	 */
	private function plannedOn(string $plate, string $key): array {
		$uuid = $this->fleet()[$plate]->getUuid();
		foreach ($this->planned[$uuid] ?? [] as $reminder) {
			if (($reminder['template_key'] ?? null) === $key) {
				return $reminder;
			}
		}
		$this->fail('the demo fleet plans no ' . $key . ' on ' . $plate);
	}

	/** TCO shows only when both prices are set, so one vehicle has both. */
	public function testOneVehicleHasAPurchasePriceAndAResidual(): void {
		$this->tester()->execute(['user' => self::OWNER]);
		$priced = array_filter(
			$this->written,
			static fn (Vehicle $vehicle): bool => $vehicle->getPurchasePrice() !== null && $vehicle->getResidualEst() !== null,
		);

		$this->assertNotEmpty($priced);
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
