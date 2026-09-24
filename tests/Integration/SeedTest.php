<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Command\SeedCommand;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\KpiService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
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
	/** @var array<string, list<array<string, mixed>>> the reminders as the banner reads them, by plate */
	private static array $reminders = [];
	/** The day the fleet was seeded on, which every due date is counted from. */
	private static string $today = '';

	public static function setUpBeforeClass(): void {
		$container = (new Application())->getContainer();
		$tester = new CommandTester($container->get(SeedCommand::class));
		self::assertSame(0, $tester->execute(['user' => self::USER]), $tester->getDisplay());

		$vehicles = $container->get(VehicleService::class);
		$kpis = $container->get(KpiService::class);
		$odometer = $container->get(OdometerService::class);
		$reminders = $container->get(ReminderService::class);
		$now = $container->get(ITimeFactory::class)->getTime();
		// The day a due date is read against, which is the server's (docs/architecture.md#time).
		self::$today = $container->get(ITimeFactory::class)->now()->format('Y-m-d');
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
			self::$reminders[$plate] = $reminders->list(self::USER, $vehicle->getUuid());
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

	/**
	 * The inspection the due banner and the job have something to say about: three weeks out, so
	 * it is a month past its first warning point and the demo shows an amber reminder.
	 */
	public function testAnInspectionIsDueInAboutThreeWeeks(): void {
		$inspection = $this->reminder('NF-PH 200', 'hu_au');

		$this->assertSame('date', $inspection['mode']);
		$this->assertGreaterThan(self::$today, $inspection['due_date']);
		$this->assertLessThanOrEqual($this->dayIn(28), $inspection['due_date']);
		$this->assertGreaterThanOrEqual($this->dayIn(14), $inspection['due_date']);
		$this->assertSame('warned', $inspection['state']);
	}

	/**
	 * By kilometres, past its lead and with a pace behind it, so the banner has both a warned
	 * reminder and an estimated date rather than "not enough data yet".
	 */
	public function testTheOilChangeIsByKilometresAndEstimatesADate(): void {
		$oil = $this->reminder('NF-DE 100', 'oil_change');

		$this->assertSame('odo', $oil['mode']);
		$this->assertNull($oil['due_date']);
		$this->assertSame(15000, $oil['recur_odo']);
		$this->assertSame('warned', $oil['state']);
		$this->assertNotNull($oil['estimate'], 'the Passat has no pace to estimate the oil change from');
		$this->assertGreaterThan(self::$today, $oil['estimate']);
	}

	/**
	 * A truck is inspected twice as often as a car (lib/Jurisdiction/De/InspectionScheme.php), and
	 * the interval is the scheme's: the fleet states the due date and nothing else.
	 */
	public function testTheTruckIsInspectedEveryTwelveMonths(): void {
		$this->assertSame(12, $this->reminder('NF-LK 700', 'hu_au')['recur_months']);
		$this->assertSame(24, $this->reminder('NF-PH 200', 'hu_au')['recur_months']);
		// Far enough out to be the green one in the overview's traffic light.
		$this->assertSame('planned', $this->reminder('NF-LK 700', 'hu_au')['state']);
	}

	/**
	 * The Costs screen charts a month: over the year before the run, no 30-day stretch of the
	 * Passat's is empty, so whichever months the seeding date puts on screen have bars.
	 */
	public function testThePassatHasACostInEveryMonthOfItsYear(): void {
		$container = (new Application())->getContainer();
		$kpis = $container->get(KpiService::class);
		$now = $container->get(ITimeFactory::class)->getTime();
		$uuid = $this->uuid('NF-DE 100');

		for ($month = 0; $month < 12; $month++) {
			$to = $now + 1 - $month * 30 * 86400;
			$cost = $kpis->of(self::USER, $uuid, ['from' => $to - 30 * 86400, 'to' => $to, 'net' => 'false'])['cost'];
			$this->assertNotNull($cost['total'], 'nothing to chart ' . $month . ' months back');
		}
	}

	/** The Fahrzeugschein on the vehicle screen, and the HU/AU's invoice behind its paperclip. */
	public function testThePassatCarriesItsPapers(): void {
		$papers = (new Application())->getContainer()->get(DocumentService::class)->list(self::USER, $this->uuid('NF-DE 100'));

		$this->assertSame(['registration', 'receipt'], array_column($papers, 'kind'));
		$this->assertNotContains(null, array_column($papers, 'name'), 'a seeded paper whose file is gone');
		$this->assertNull($papers[0]['linked_type']);
		$this->assertSame('maintenance', $papers[1]['linked_type']);
	}

	/**
	 * A business trip the mileage claim values at the statutory 0,30 €/km (De\RateProvider), and a
	 * private one it leaves out.
	 */
	public function testTheMileageClaimValuesTheBusinessTrip(): void {
		$container = (new Application())->getContainer();
		$trips = $container->get(TripMapper::class)->findAnyStartedBetween(
			(int)$container->get(VehicleService::class)->reach(self::USER, 'view', $this->uuid('NF-DE 100'))->getId(),
			0,
			PHP_INT_MAX,
		);
		$this->assertSame(['business', 'private'], array_map(static fn (Trip $trip): string => (string)$trip->getCategory(), $trips));
		$business = $trips[0];
		$year = (int)(new \DateTimeImmutable('@' . ($business->getStartedAt() + 60 * $business->getStartedAtOff())))->format('Y');

		$page = (string)$container->get(MileageClaimExport::class)->year(self::USER, $this->uuid('NF-DE 100'), $year);

		$this->assertStringContainsString('Kundentermin', $page);
		$this->assertStringNotContainsString('Einkauf', $page);
		// 90 km at 0,30 €.
		$this->assertStringContainsString('27,00', $page);
	}

	private function uuid(string $plate): string {
		foreach ((new Application())->getContainer()->get(VehicleService::class)->list(self::USER) as $vehicle) {
			if ($vehicle->getPlate() === $plate) {
				return $vehicle->getUuid();
			}
		}
		$this->fail('the demo fleet has no ' . $plate);
	}

	/** @return array<string, mixed> */
	private function reminder(string $plate, string $key): array {
		foreach (self::$reminders[$plate] ?? [] as $reminder) {
			if ($reminder['template_key'] === $key) {
				return $reminder;
			}
		}
		$this->fail('the demo fleet has no ' . $key . ' on ' . $plate);
	}

	private function dayIn(int $days): string {
		return (new \DateTimeImmutable(self::$today))->modify('+' . $days . ' day')->format('Y-m-d');
	}

	/** @return list<string> */
	private function energies(string $plate): array {
		$energies = array_values(array_unique(array_column(self::$kpis[$plate]['consumption'], 'energy')));
		sort($energies);

		return $energies;
	}
}
