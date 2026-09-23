<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The demo fleet of docs/development.md#testing: what E2E runs against, what the app store's
 * screenshots show, and what there is to click on a fresh install.
 *
 * It writes through the services the sheet writes through, so a fleet it cannot produce is a
 * fleet the app cannot hold - and the odometer rules decide the flags here as they do anywhere
 * else.
 *
 * @psalm-type Seeded = array{vehicle: array<string, mixed>, disposed?: int, readings: list<array<string, int|string>>, energy?: list<array<string, mixed>>, maintenance?: list<array<string, mixed>>, expenses?: list<array<string, mixed>>, reminders?: list<array<string, mixed>>}
 */
class SeedCommand extends Command {
	/**
	 * Where the demo fleet is registered. Real enough to screenshot, distinctive enough that a
	 * re-run recognises its own rows - and not the `E2E-` the Playwright specs clear out
	 * (tests/e2e/app.js), so the two can share an instance.
	 */
	private const DISTRICT = 'NF-';

	/**
	 * Two years of readings and one of costs before the moment it runs, so the demo is never
	 * dated. A reading is days back plus either the counter as it was read or the distance driven
	 * since the one before it - which is what makes half of them `derived`. `disposed` counts back
	 * the same way, and is a day rather than an instant.
	 *
	 * A cost row is days back plus what the sheet would post; a fill-up adds what it leaves out
	 * to self::FILL. Every counter a cost row reads sits between the Readings around it, so the
	 * only flags are the ones the comments promise - a seeded fill-up that broke a chain would
	 * teach the screenshots the wrong thing.
	 *
	 * A reminder is days or months ahead instead, since what a reminder is worth is that it has
	 * not fallen due yet. Its interval is the template's, never written down here.
	 *
	 * @var list<Seeded>
	 */
	private const FLEET = [
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'DE 100',
				'manufacturer' => 'Volkswagen',
				'model' => 'Passat Variant',
				'vehicle_type' => 'car',
				'engine' => 'diesel',
				'energy_types' => ['diesel'],
				'tank_ml' => 66000,
				'first_reg' => '2019-03-14',
				'vin' => 'WVWZZZ3CZKE000100',
				'odo_unit' => 'km',
				'purchase_price' => 2850000,
				'residual_est' => 2200000,
				'currency' => 'EUR',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'active',
			],
			// A reading below the one derived before it, so the derived row is the one in question
			// and the number somebody read stands (rule 6). The last three fall inside the 45 days
			// before the run and span more than 30, which is the pace the oil change below is
			// estimated from (rule 5) - and still is 45 days later, when the first of them leaves
			// the 90-day window and the two left are 25 days apart.
			'readings' => [
				['days' => 730, 'value' => 84210],
				['days' => 640, 'value' => 88900],
				['days' => 545, 'distance' => 5100],
				['days' => 450, 'distance' => 4800],
				['days' => 360, 'value' => 101250],
				['days' => 270, 'distance' => 3900],
				['days' => 180, 'value' => 108400],
				['days' => 90, 'distance' => 4100],
				['days' => 45, 'value' => 111600],
				['days' => 30, 'value' => 111900],
				['days' => 5, 'value' => 112180],
			],
			// One partial, summed into the segment it falls in, and one after a fill-up nobody
			// recorded, whose segment yields no number (docs/architecture.md#numbers-consumption-cost-emissions).
			'energy' => [
				['days' => 350, 'odo' => 101700, 'amount' => 45200, 'total' => 7639, 'station' => 'Aral Hauptstraße'],
				['days' => 335, 'odo' => 102300, 'amount' => 18000, 'total' => 3060, 'station' => 'Shell Ringstraße', 'full_tank' => false],
				['days' => 320, 'odo' => 103000, 'amount' => 60500, 'total' => 10346, 'station' => 'Aral Hauptstraße'],
				['days' => 300, 'odo' => 104000, 'amount' => 41000, 'total' => 7011, 'station' => 'Aral Hauptstraße', 'missed_previous' => true],
				['days' => 280, 'odo' => 104900, 'amount' => 53100, 'total' => 9133, 'station' => 'Shell Ringstraße'],
				['days' => 250, 'odo' => 105800, 'amount' => 52200, 'total' => 8874, 'station' => 'Aral Hauptstraße'],
				['days' => 225, 'odo' => 106700, 'amount' => 54000, 'total' => 9234, 'station' => 'Aral Hauptstraße'],
				['days' => 200, 'odo' => 107600, 'amount' => 51300, 'total' => 8618, 'station' => 'Shell Ringstraße'],
				['days' => 175, 'odo' => 108500, 'amount' => 53100, 'total' => 9027, 'station' => 'Aral Hauptstraße'],
				['days' => 150, 'odo' => 109400, 'amount' => 52200, 'total' => 8770, 'station' => 'Aral Hauptstraße'],
				['days' => 125, 'odo' => 110300, 'amount' => 53900, 'total' => 9163, 'station' => 'Shell Ringstraße'],
				['days' => 100, 'odo' => 111100, 'amount' => 46400, 'total' => 7888, 'station' => 'Aral Hauptstraße'],
			],
			'maintenance' => [
				['days' => 240, 'type' => 'service', 'title' => 'Oil change and inspection', 'vendor' => 'Autohaus Becker', 'cost' => 38950, 'vat_rate' => 1900, 'odo' => 106100],
				['days' => 160, 'type' => 'tyres', 'title' => 'Winter tyres fitted', 'vendor' => 'Reifen Müller', 'cost' => 8900, 'vat_rate' => 1900],
				['days' => 45, 'type' => 'inspection', 'title' => 'HU/AU', 'vendor' => 'TÜV Süd', 'cost' => 14700, 'vat_rate' => 1900],
			],
			// Insurance, tax and the fine carry no VAT and state no rate, as the sheet prefills them;
			// neither does the toll. The net figure counts all four gross and says so.
			'expenses' => [
				['days' => 340, 'category' => 'insurance', 'amount' => 68400, 'notes' => 'Liability and partial cover'],
				['days' => 330, 'category' => 'tax', 'amount' => 21400],
				['days' => 200, 'category' => 'parking', 'amount' => 1850, 'vat_rate' => 1900],
				['days' => 140, 'category' => 'toll', 'amount' => 1150, 'notes' => 'Brenner motorway'],
				['days' => 60, 'category' => 'fine', 'amount' => 3000, 'notes' => '11 km/h too fast'],
			],
			// 720 km ahead of the newest Reading, so it stands inside the template's lead: the
			// banner shows it warned, with the day the pace above reaches it, and the maintenance
			// sheet has an open reminder to close.
			'reminders' => [
				['template_key' => 'oil_change', 'mode' => 'odo', 'due_odo' => 112900],
			],
		],
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'PH 200',
				'manufacturer' => 'Volvo',
				'model' => 'XC60 Recharge',
				'vehicle_type' => 'car',
				'engine' => 'hybrid',
				'energy_types' => ['petrol', 'electric'],
				'tank_ml' => 60000,
				'battery_wh' => 18800,
				'first_reg' => '2022-06-01',
				'vin' => 'YV1UZK5V9N1000200',
				'odo_unit' => 'km',
				'purchase_price' => 4990000,
				'currency' => 'EUR',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'active',
				'notes' => 'Cluster replaced under warranty; the counter starts over.',
			],
			// A counter that goes backwards because the instrument cluster was swapped: the row
			// is kept and flagged, and the timeline asks about it (rule 3). The swap is more than
			// a year back, so the header's default period has a distance to divide by.
			'readings' => [
				['days' => 700, 'value' => 18400],
				['days' => 600, 'value' => 24950],
				['days' => 510, 'distance' => 3200],
				['days' => 450, 'value' => 31020],
				['days' => 390, 'value' => 9800],
				['days' => 300, 'value' => 12400],
				['days' => 210, 'distance' => 3600],
				['days' => 120, 'value' => 19700],
				['days' => 30, 'value' => 23100],
				['days' => 5, 'distance' => 640],
			],
			// Both energies, so the header has two figures and never a blended one. Charges come
			// in pairs a day or two apart, each pair a segment; the charge between pairs has no
			// counter, as a charge at home usually has not, and closes no segment.
			'energy' => [
				['days' => 298, 'energy' => 'electric', 'odo' => 12480, 'amount' => 15600, 'total' => 499, 'location_kind' => 'home'],
				['days' => 296, 'energy' => 'electric', 'odo' => 12590, 'amount' => 14300, 'total' => 458, 'location_kind' => 'home'],
				['days' => 290, 'energy' => 'petrol', 'odo' => 12800, 'amount' => 38000, 'total' => 6650, 'station' => 'Esso Leopoldstraße'],
				['days' => 284, 'energy' => 'electric', 'amount' => 16200, 'total' => 518, 'location_kind' => 'home'],
				['days' => 270, 'energy' => 'electric', 'odo' => 13600, 'amount' => 12800, 'total' => 704, 'location_kind' => 'public', 'station' => 'EnBW Marienplatz'],
				['days' => 268, 'energy' => 'electric', 'odo' => 13700, 'amount' => 14200, 'total' => 966, 'location_kind' => 'public', 'is_dc' => true, 'station' => 'Ionity Irschenberg'],
				['days' => 262, 'energy' => 'petrol', 'odo' => 13900, 'amount' => 46000, 'total' => 8050, 'station' => 'Esso Leopoldstraße'],
				['days' => 250, 'energy' => 'electric', 'amount' => 16000, 'total' => 512, 'location_kind' => 'home'],
				['days' => 230, 'energy' => 'petrol', 'odo' => 15200, 'amount' => 20000, 'total' => 3560, 'station' => 'Aral Kreuzstraße', 'full_tank' => false],
				['days' => 222, 'energy' => 'electric', 'odo' => 15500, 'amount' => 15800, 'total' => 506, 'location_kind' => 'home'],
				['days' => 220, 'energy' => 'electric', 'odo' => 15620, 'amount' => 16400, 'total' => 525, 'location_kind' => 'home'],
				['days' => 200, 'energy' => 'petrol', 'odo' => 16400, 'amount' => 40000, 'total' => 7000, 'station' => 'Esso Leopoldstraße'],
				['days' => 185, 'energy' => 'electric', 'amount' => 18000, 'total' => 1224, 'location_kind' => 'public', 'is_dc' => true, 'station' => 'Ionity Irschenberg'],
				['days' => 160, 'energy' => 'electric', 'odo' => 17800, 'amount' => 15000, 'total' => 480, 'location_kind' => 'home'],
				['days' => 158, 'energy' => 'electric', 'odo' => 17900, 'amount' => 13600, 'total' => 435, 'location_kind' => 'home'],
				['days' => 150, 'energy' => 'petrol', 'odo' => 18200, 'amount' => 47000, 'total' => 8225, 'station' => 'Esso Leopoldstraße'],
				['days' => 95, 'energy' => 'petrol', 'odo' => 20000, 'amount' => 50000, 'total' => 8800, 'station' => 'Esso Leopoldstraße'],
				['days' => 60, 'energy' => 'electric', 'amount' => 15500, 'total' => 496, 'location_kind' => 'home'],
				['days' => 45, 'energy' => 'electric', 'odo' => 21900, 'amount' => 15200, 'total' => 486, 'location_kind' => 'home'],
				['days' => 43, 'energy' => 'electric', 'odo' => 22000, 'amount' => 14000, 'total' => 448, 'location_kind' => 'home'],
			],
			'maintenance' => [
				['days' => 175, 'type' => 'service', 'title' => 'Annual service', 'vendor' => 'Volvo Car Center', 'cost' => 42000, 'vat_rate' => 1900, 'odo' => 17200],
			],
			'expenses' => [
				['days' => 320, 'category' => 'insurance', 'amount' => 92000],
				['days' => 110, 'category' => 'parking', 'amount' => 2400, 'notes' => 'Airport, three days'],
			],
			// The sticker question answered, three weeks out: a month past its first warning
			// point, so the banner is amber and the job has a notification to send. Not a month's
			// end, which is what a sticker names: the demo has to stand three weeks out whichever
			// day it is seeded on, and a due date the sheet writes - or a recurrence counts from
			// a maintenance record - falls on any day anyway.
			'reminders' => [
				['template_key' => 'hu_au', 'due_in_days' => 21],
			],
		],
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'LW 300',
				'manufacturer' => 'Fendt',
				'model' => '313 Vario',
				'vehicle_type' => 'tractor',
				'engine' => 'diesel',
				'energy_types' => ['diesel'],
				'tank_ml' => 200000,
				'first_reg' => '2017-08-09',
				'vin' => 'WFFG313V0H1000300',
				'odo_unit' => 'h',
				'currency' => 'EUR',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'active',
			],
			// Engine hours, not kilometres: no column is named after a unit it might not hold
			// (docs/architecture.md#data-model).
			'readings' => [
				['days' => 700, 'value' => 1180],
				['days' => 500, 'value' => 1355],
				['days' => 300, 'distance' => 120],
				['days' => 120, 'value' => 1602],
				['days' => 20, 'value' => 1668],
			],
			// Counted in hours, so its figures are l/h and €/h.
			'energy' => [
				['days' => 260, 'odo' => 1500, 'amount' => 150000, 'total' => 20250, 'station' => 'Raiffeisen Agrar'],
				['days' => 230, 'odo' => 1520, 'amount' => 190000, 'total' => 25460, 'station' => 'Raiffeisen Agrar'],
				['days' => 200, 'odo' => 1538, 'amount' => 172000, 'total' => 23220, 'station' => 'Raiffeisen Agrar'],
				['days' => 160, 'odo' => 1557, 'amount' => 185000, 'total' => 24790, 'station' => 'Raiffeisen Agrar'],
				['days' => 130, 'odo' => 1575, 'amount' => 176000, 'total' => 23410, 'station' => 'Raiffeisen Agrar'],
			],
			'maintenance' => [
				['days' => 140, 'type' => 'service', 'title' => '500-hour service', 'vendor' => 'Landtechnik Huber', 'cost' => 86000, 'vat_rate' => 1900, 'odo' => 1566],
			],
			'expenses' => [
				['days' => 300, 'category' => 'insurance', 'amount' => 41000],
			],
		],
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'AH 400',
				'manufacturer' => 'Humbaur',
				'model' => 'HA 752513',
				'vehicle_type' => 'trailer',
				'odo_unit' => 'km',
				'first_reg' => '2015-04-20',
				'vin' => 'W09HA752513F00400',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'laid_up',
				'notes' => 'Off the road for the winter.',
			],
			// A trailer counts neither kilometres nor hours (rule 4), so its odometer is empty
			// and the vehicle screen has to say so rather than show a zero - and its costs are a
			// total for the period, with no distance to divide them by.
			'readings' => [],
			'expenses' => [
				['days' => 330, 'category' => 'insurance', 'amount' => 12400],
				['days' => 325, 'category' => 'tax', 'amount' => 3730],
			],
		],
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'XY 500',
				'manufacturer' => 'Opel',
				'model' => 'Astra Caravan',
				'vehicle_type' => 'car',
				'engine' => 'petrol',
				'energy_types' => ['petrol'],
				'tank_ml' => 52000,
				'first_reg' => '2008-05-02',
				'vin' => 'W0L0AHL3585000500',
				'odo_unit' => 'km',
				'purchase_price' => 190000,
				'currency' => 'EUR',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'disposed',
				'notes' => 'Sold; kept for the retention period.',
			],
			'disposed' => 120,
			'readings' => [
				['days' => 900, 'value' => 214800],
				['days' => 400, 'value' => 231500],
				['days' => 130, 'value' => 238900],
			],
		],
		[
			// The vehicle somebody added last week and has not finished: no VIN, no first
			// registration, and no capacity for the energy it was told it takes - the three
			// things the "complete this vehicle" hint asks about (src/utils/complete.js), and the
			// only vehicle here it has a question for. Every other one answers them.
			'vehicle' => [
				'plate' => self::DISTRICT . 'NE 600',
				'manufacturer' => 'Renault',
				'model' => 'Kangoo',
				'vehicle_type' => 'van',
				'engine' => 'petrol',
				'energy_types' => ['petrol'],
				'odo_unit' => 'km',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'active',
			],
			'readings' => [
				['days' => 40, 'value' => 62310],
			],
		],
		[
			// Kilometres and engine hours on two chains that never mix (rule 4): a fill-up reads
			// both, and the header states the hours beside the kilometres.
			'vehicle' => [
				'plate' => self::DISTRICT . 'LK 700',
				'manufacturer' => 'MAN',
				'model' => 'TGL 12.250',
				'vehicle_type' => 'truck',
				'engine' => 'diesel',
				'energy_types' => ['diesel'],
				'tank_ml' => 150000,
				'first_reg' => '2020-02-11',
				'vin' => 'WMA08ZZZ0LY000700',
				'odo_unit' => 'km',
				'second_unit' => 'h',
				'currency' => 'EUR',
				'jurisdiction' => self::HOME_COUNTRY,
				'lifecycle' => 'active',
			],
			'readings' => [
				['days' => 360, 'value' => 182000],
				['days' => 360, 'value' => 6120, 'counter' => 'second'],
				['days' => 20, 'value' => 189300],
				['days' => 20, 'value' => 6368, 'counter' => 'second'],
			],
			'energy' => [
				['days' => 340, 'odo' => 183200, 'second_odo' => 6155, 'amount' => 110000, 'total' => 18150, 'station' => 'Aral Autohof Nord'],
				['days' => 325, 'odo' => 183900, 'second_odo' => 6178, 'amount' => 128000, 'total' => 21120, 'station' => 'Aral Autohof Nord'],
				['days' => 300, 'odo' => 184650, 'second_odo' => 6204, 'amount' => 60000, 'total' => 9900, 'station' => 'Aral Autohof Nord', 'full_tank' => false],
				['days' => 290, 'odo' => 185000, 'second_odo' => 6216, 'amount' => 135000, 'total' => 22275, 'station' => 'Aral Autohof Nord'],
				['days' => 250, 'odo' => 185700, 'second_odo' => 6240, 'amount' => 126000, 'total' => 20790, 'station' => 'Aral Autohof Nord'],
				['days' => 200, 'odo' => 186500, 'second_odo' => 6268, 'amount' => 142000, 'total' => 23430, 'station' => 'Aral Autohof Nord'],
				['days' => 150, 'odo' => 187300, 'second_odo' => 6297, 'amount' => 146000, 'total' => 24090, 'station' => 'Aral Autohof Nord'],
				['days' => 100, 'odo' => 188000, 'second_odo' => 6322, 'amount' => 128000, 'total' => 21120, 'station' => 'Aral Autohof Nord'],
				['days' => 50, 'odo' => 188800, 'second_odo' => 6350, 'amount' => 140000, 'total' => 23100, 'station' => 'Aral Autohof Nord'],
			],
			'maintenance' => [
				['days' => 180, 'type' => 'service', 'title' => 'Oil and filter change', 'vendor' => 'MAN Truck & Bus Service', 'cost' => 124000, 'vat_rate' => 1900, 'odo' => 186900, 'second_odo' => 6283],
			],
			// Whether a toll carries VAT depends on who levies it, so nobody stated a rate.
			'expenses' => [
				['days' => 335, 'category' => 'insurance', 'amount' => 245000],
				['days' => 275, 'category' => 'toll', 'amount' => 38450, 'notes' => 'Truck toll, one quarter'],
			],
			// A truck is inspected every 12 months where a car has 24, which the scheme decides
			// and this reminder carries: the interval is a vehicle's, not a country's constant.
			'reminders' => [
				['template_key' => 'hu_au', 'due_in_months' => 5],
			],
		],
	];

	/**
	 * What a fill-up row leaves out: full, and the German standard rate every day of the year
	 * the costs span (De\RateProvider).
	 */
	private const FILL = ['full_tank' => true, 'vat_rate' => 1900];

	private const DAY = 86400;

	/**
	 * The demo fleet is German (plan.md), so its readings carry the offset Berlin was on when
	 * each one was taken - which is what `read_at_off` is for and what a constant would hide.
	 */
	private const HOME = 'Europe/Berlin';

	/**
	 * And every vehicle says so rather than taking the seeding account's own setting: the plates,
	 * the VAT rates and the HU/AU the reminders name are one country's, and a profile with no
	 * inspection scheme offers no `hu_au` to write.
	 */
	private const HOME_COUNTRY = 'de';

	public function __construct(
		private IUserManager $users,
		private VehicleService $fleet,
		private OdometerService $odometer,
		private EnergyService $energy,
		private MaintenanceService $maintenance,
		private ExpenseService $expenses,
		private ReminderService $reminders,
		private ITimeFactory $time,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:seed')
			->setDescription('Write a demo fleet into one user\'s account')
			->addArgument('user', InputArgument::REQUIRED, 'the uid the fleet belongs to');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if (!$this->users->userExists($userId)) {
			$output->writeln('<error>No such user: ' . $userId . '</error>');

			return self::FAILURE;
		}

		$retired = $this->retire($userId);
		if ($retired > 0) {
			$output->writeln('Retired ' . $retired . ' vehicle(s) from an earlier run.');
		}

		$readings = 0;
		$costs = 0;
		$reminders = 0;
		foreach (self::FLEET as $entry) {
			$vehicle = $this->fleet->create($userId, $this->fields($entry));
			$uuid = $vehicle->getUuid();
			// Readings first: a cost row's counter lands between them, and a derived Reading
			// counts from the ones already there when it is written.
			foreach ($entry['readings'] as $reading) {
				$this->odometer->record($userId, $uuid, $this->dated($reading, 'read_at'));
			}
			$fill = self::FILL + ['energy' => ($entry['vehicle']['energy_types'] ?? [null])[0]];
			foreach ($entry['energy'] ?? [] as $row) {
				$this->energy->record($userId, $uuid, $this->dated($row, 'filled_at') + $fill);
			}
			foreach ($entry['maintenance'] ?? [] as $row) {
				$this->maintenance->record($userId, $uuid, $this->dated($row, 'done_at'));
			}
			foreach ($entry['expenses'] ?? [] as $row) {
				$this->expenses->record($userId, $uuid, $this->dated($row, 'spent_at'));
			}
			// Last, so a reminder by kilometres is planned against the counter as it now reads.
			foreach ($entry['reminders'] ?? [] as $row) {
				$this->reminders->create($userId, $uuid, $this->planned($row));
			}
			$written = count($entry['energy'] ?? []) + count($entry['maintenance'] ?? []) + count($entry['expenses'] ?? []);
			$due = count($entry['reminders'] ?? []);
			$readings += count($entry['readings']);
			$costs += $written;
			$reminders += $due;
			$output->writeln($this->describe($vehicle, count($entry['readings']), $written, $due));
		}

		$output->writeln(sprintf(
			'Seeded %d vehicles, %d readings, %d costs and %d reminders for %s.',
			count(self::FLEET),
			$readings,
			$costs,
			$reminders,
			$userId,
		));

		return self::SUCCESS;
	}

	/**
	 * The fleet an earlier run left, deleted the way the app deletes: the rows stay for the
	 * trash, and only the plates this command writes are touched, so a real vehicle parked next
	 * to the demo one survives.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function retire(string $userId): int {
		$plates = array_column(array_column(self::FLEET, 'vehicle'), 'plate');

		$retired = 0;
		foreach ($this->fleet->list($userId) as $vehicle) {
			if (in_array($vehicle->getPlate(), $plates, true)) {
				$this->fleet->delete($userId, $vehicle->getUuid(), $vehicle->getUpdatedAt());
				$retired++;
			}
		}

		return $retired;
	}

	/**
	 * The vehicle as the create sheet would send it, with the one date the fleet definition
	 * cannot hold: a demo whose dates never move stops looking like a fleet.
	 *
	 * @param Seeded $entry
	 * @return array<string, mixed>
	 */
	private function fields(array $entry): array {
		if (!isset($entry['disposed'])) {
			return $entry['vehicle'];
		}

		return $entry['vehicle'] + ['disposed_at' => $this->dayOf($this->instant($entry['disposed']))];
	}

	/**
	 * One row as the entry sheet would send it: its days back become the moment it names in
	 * `$column`, and the offset that moment had in `<column>_off`.
	 *
	 * @param array<string, mixed> $row with `days`
	 * @return array<string, mixed>
	 */
	private function dated(array $row, string $column): array {
		$at = $this->instant((int)$row['days']);
		unset($row['days']);

		return $row + [
			$column => $at,
			$column . '_off' => $this->offsetAt($at),
		];
	}

	/**
	 * One reminder as the sheet would send it: what the fleet states is when it is due, counted
	 * from the moment the command runs - in days, or to the end of the month a sticker would
	 * name (docs/ui.md).
	 *
	 * @param array<string, mixed> $row with `due_in_days` or `due_in_months`, or a due counter
	 * @return array<string, mixed>
	 */
	private function planned(array $row): array {
		$days = $row['due_in_days'] ?? null;
		$months = $row['due_in_months'] ?? null;
		unset($row['due_in_days'], $row['due_in_months']);

		if ($days !== null) {
			return $row + ['due_date' => $this->dayOf($this->instant(-(int)$days))];
		}
		if ($months !== null) {
			return $row + ['due_date' => $this->monthEnd((int)$months)];
		}

		return $row;
	}

	/** The last day of the month that many months after this one, where a HU/AU falls due. */
	private function monthEnd(int $monthsAhead): string {
		$now = $this->berlin($this->time->getTime());
		// setDate carries a month past December into the next year, and `t` is the length of
		// whichever month the first lands in.
		$first = $now->setDate((int)$now->format('Y'), (int)$now->format('n') + $monthsAhead, 1);

		return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), (int)$first->format('t'))
			->format('Y-m-d');
	}

	private function instant(int $daysAgo): int {
		return $this->time->getTime() - $daysAgo * self::DAY;
	}

	private function dayOf(int $instant): string {
		return $this->berlin($instant)->format('Y-m-d');
	}

	/** Minutes east of UTC at that instant, so a summer reading differs from a winter one. */
	private function offsetAt(int $instant): int {
		return intdiv((int)$this->berlin($instant)->format('Z'), 60);
	}

	private function berlin(int $instant): \DateTimeImmutable {
		return (new \DateTimeImmutable('@' . $instant))->setTimezone(new \DateTimeZone(self::HOME));
	}

	private function describe(Vehicle $vehicle, int $readings, int $costs, int $reminders): string {
		return sprintf(
			'  %-12s %-28s %2d reading(s) %2d cost(s) %d reminder(s)',
			(string)$vehicle->getPlate(),
			trim($vehicle->getManufacturer() . ' ' . $vehicle->getModel()),
			$readings,
			$costs,
			$reminders,
		);
	}
}
