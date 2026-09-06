<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\OdometerService;
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
 */
class SeedCommand extends Command {
	/**
	 * Where the demo fleet is registered. Real enough to screenshot, distinctive enough that a
	 * re-run recognises its own rows - and not the `E2E-` the Playwright specs clear out
	 * (tests/e2e/app.js), so the two can share an instance.
	 */
	private const DISTRICT = 'NF-';

	/**
	 * Two years of readings before the moment it runs, so the demo is never dated. A reading is
	 * days back plus either the counter as it was read or the distance driven since the one
	 * before it - which is what makes half of them `derived`. `disposed` counts back the same
	 * way, and is a day rather than an instant.
	 *
	 * @var list<array{vehicle: array<string, mixed>, disposed?: int, readings: list<array<string, int>>}>
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
				'currency' => 'EUR',
				'lifecycle' => 'active',
			],
			// The last reading is below the one derived before it, so the derived row is the
			// one in question and the number somebody read stands (rule 6).
			'readings' => [
				['days' => 730, 'value' => 84210],
				['days' => 640, 'value' => 88900],
				['days' => 545, 'distance' => 5100],
				['days' => 450, 'distance' => 4800],
				['days' => 360, 'value' => 101250],
				['days' => 270, 'distance' => 3900],
				['days' => 180, 'value' => 108400],
				['days' => 90, 'distance' => 4100],
				['days' => 30, 'value' => 111900],
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
				'odo_unit' => 'km',
				'purchase_price' => 4990000,
				'currency' => 'EUR',
				'lifecycle' => 'active',
				'notes' => 'Cluster replaced under warranty; the counter starts over.',
			],
			// A counter that goes backwards because the instrument cluster was swapped: the row
			// is kept and flagged, and the timeline asks about it (rule 3).
			'readings' => [
				['days' => 400, 'value' => 18400],
				['days' => 300, 'value' => 24950],
				['days' => 210, 'distance' => 3200],
				['days' => 150, 'value' => 31020],
				['days' => 90, 'value' => 9800],
				['days' => 30, 'value' => 12400],
				['days' => 5, 'distance' => 640],
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
				'odo_unit' => 'h',
				'currency' => 'EUR',
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
		],
		[
			'vehicle' => [
				'plate' => self::DISTRICT . 'AH 400',
				'manufacturer' => 'Humbaur',
				'model' => 'HA 752513',
				'vehicle_type' => 'trailer',
				'odo_unit' => 'km',
				'first_reg' => '2015-04-20',
				'lifecycle' => 'laid_up',
				'notes' => 'Off the road for the winter.',
			],
			// A trailer counts neither kilometres nor hours (rule 4), so its odometer is empty
			// and the vehicle screen has to say so rather than show a zero.
			'readings' => [],
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
				'odo_unit' => 'km',
				'purchase_price' => 190000,
				'currency' => 'EUR',
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
	];

	private const DAY = 86400;

	/**
	 * The demo fleet is German (plan.md), so its readings carry the offset Berlin was on when
	 * each one was taken - which is what `read_at_off` is for and what a constant would hide.
	 */
	private const HOME = 'Europe/Berlin';

	public function __construct(
		private IUserManager $users,
		private VehicleService $fleet,
		private OdometerService $odometer,
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
		foreach (self::FLEET as $entry) {
			$vehicle = $this->fleet->create($userId, $this->fields($entry));
			foreach ($entry['readings'] as $reading) {
				$this->odometer->record($userId, $vehicle->getUuid(), $this->moment($reading));
				$readings++;
			}
			$output->writeln($this->describe($vehicle, count($entry['readings'])));
		}

		$output->writeln(sprintf(
			'Seeded %d vehicles and %d readings for %s.',
			count(self::FLEET),
			$readings,
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
	 * @param array{vehicle: array<string, mixed>, disposed?: int, readings: list<array<string, int>>} $entry
	 * @return array<string, mixed>
	 */
	private function fields(array $entry): array {
		if (!isset($entry['disposed'])) {
			return $entry['vehicle'];
		}

		return $entry['vehicle'] + ['disposed_at' => $this->dayOf($this->instant($entry['disposed']))];
	}

	/**
	 * One reading as the entry sheet would send it: the moment it was read, the offset it was
	 * read at, and either the counter or the distance since.
	 *
	 * @param array<string, int> $reading
	 * @return array<string, int>
	 */
	private function moment(array $reading): array {
		$readAt = $this->instant($reading['days']);
		unset($reading['days']);

		return $reading + [
			'read_at' => $readAt,
			'read_at_off' => $this->offsetAt($readAt),
		];
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

	private function describe(Vehicle $vehicle, int $readings): string {
		return sprintf(
			'  %-12s %-28s %2d reading(s)',
			(string)$vehicle->getPlate(),
			trim($vehicle->getManufacturer() . ' ' . $vehicle->getModel()),
			$readings,
		);
	}
}
