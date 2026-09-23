<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\IRateProvider;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\MileageClaim;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What the core hands a country to print as a mileage claim: which trips it values, at which rate,
 * and what it leaves out of the total. How it prints is tests/Country's.
 */
class MileageClaimExportTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const SOURCE = 'https://example.org/the-rate';

	/** @var list<Trip> */
	private array $tripRows = [];
	private int $nextId = 1;
	private string $odoUnit = 'km';
	private bool $hasRates = true;
	private bool $hasRenderer = true;
	private ?MileageClaim $printed = null;
	/** @var list<string> the local days a rate was asked for */
	private array $asked = [];
	/** @var list<array<string, mixed>> */
	private array $logged = [];

	private function service(): MileageClaimExport {
		$trips = $this->createMock(TripMapper::class);
		$trips->method('findAnyStartedBetween')->willReturnCallback(
			fn (int $vehicleId, int $from, int $to): array => array_values(array_filter(
				$this->tripRows,
				static fn (Trip $trip): bool => $trip->getVehicleId() === $vehicleId
					&& $trip->getStartedAt() >= $from && $trip->getStartedAt() < $to,
			)),
		);

		$fleet = $this->createMock(VehicleService::class);
		$fleet->method('reach')->willReturnCallback(
			function (string $userId, string $operation): Vehicle {
				if ($userId !== self::OWNER && !($userId === self::DRIVER && $operation === VehicleAccess::VIEW)) {
					throw new AccessDeniedException();
				}

				return Vehicle::fromRow([
					'id' => self::VEHICLE_ID,
					'uuid' => self::VEHICLE,
					'plate' => 'B-XY 123',
					'vehicle_type' => 'car',
					'odo_unit' => $this->odoUnit,
					'jurisdiction' => 'somewhere',
				]);
			},
		);

		// Nothing stated before 2 January, 30 ct from then, 35 ct from July: enough steps to see
		// which day each trip was valued on.
		$rates = $this->createMock(IRateProvider::class);
		$rates->method('mileageRateAt')->willReturnCallback(function (string $type, \DateTimeInterface $when): ?int {
			$day = $when->format('Y-m-d');
			$this->asked[] = $type . ' ' . $day;

			return match (true) {
				$day >= '2026-07-01' => 350,
				$day >= '2026-01-02' => 300,
				default => null,
			};
		});
		$rates->method('mileageSourceUrl')->willReturn(self::SOURCE);

		$renderer = $this->createMock(IClaimRenderer::class);
		$renderer->method('render')->willReturnCallback(function (MileageClaim $claim): string {
			$this->printed = $claim;

			return '<!DOCTYPE html>printed';
		});

		$profile = $this->createMock(IJurisdiction::class);
		$profile->method('rates')->willReturnCallback(fn (): ?IRateProvider => $this->hasRates ? $rates : null);
		$profile->method('claimRenderer')->willReturnCallback(fn (): ?IClaimRenderer => $this->hasRenderer ? $renderer : null);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($profile);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = $context;
		});

		return new MileageClaimExport($fleet, $trips, new Jurisdictions($container), $logger);
	}

	/** @param array<string, mixed> $row */
	private function trip(string $local, array $row = []): Trip {
		$id = $this->nextId++;
		$startedAt = (new \DateTimeImmutable($local . '+01:00'))->getTimestamp();
		$trip = Trip::fromRow($row + [
			'id' => $id,
			'uuid' => sprintf('0195e2f1-0000-4000-8000-%012d', $id),
			'vehicle_id' => self::VEHICLE_ID,
			'started_at' => $startedAt,
			'started_at_off' => 60,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => 60,
			'distance' => 100,
			'purpose' => 'Abnahme',
			'category' => Trip::BUSINESS,
		]);
		$this->tripRows[] = $trip;

		return $trip;
	}

	private function claim(string $userId = self::OWNER): MileageClaim {
		$this->assertSame('<!DOCTYPE html>printed', $this->service()->year($userId, self::VEHICLE, 2026));
		$this->assertNotNull($this->printed);

		return $this->printed;
	}

	/** @return list<?int> */
	private static function amounts(MileageClaim $claim): array {
		return array_map(static fn (array $line): ?int => $line['amount'], $claim->lines);
	}

	/**
	 * Business trips only: a commute is a different deduction, a private trip is none, and a
	 * voided trip was never driven as far as the claim is concerned.
	 */
	public function testItValuesTheYearsBusinessTripsAndNothingElse(): void {
		$this->trip('2026-03-02T08:00:00', ['distance' => 120]);
		$this->trip('2026-03-03T08:00:00', ['category' => Trip::COMMUTE]);
		$this->trip('2026-03-04T08:00:00', ['category' => Trip::PRIVATE]);
		$this->trip('2026-03-05T08:00:00', ['deleted_at' => 1772700000]);
		$this->trip('2026-03-06T08:00:00', ['distance' => null, 'start_odo' => 50000, 'end_odo' => 50033]);

		$claim = $this->claim();

		$this->assertSame(2026, $claim->year);
		$this->assertSame(self::SOURCE, $claim->sourceUrl);
		$this->assertSame([1, 5], array_map(static fn (array $line): int => (int)$line['trip']->getId(), $claim->lines));
		$this->assertSame([120, 33], array_map(static fn (array $line): ?int => $line['kilometres'], $claim->lines));
		// 120 km × 30,0 ct and 33 km × 30,0 ct, in cents.
		$this->assertSame([3600, 990], self::amounts($claim));
		$this->assertSame(4590, $claim->total);
		$this->assertSame(153, $claim->kilometres);
	}

	/**
	 * A trip is valued at the rate on the day it set off there, not in UTC: 00:30 on 1 July in
	 * Berlin is still 30 June in UTC, and takes July's rate.
	 */
	public function testEachTripTakesTheRateOnItsOwnDay(): void {
		$this->trip('2026-06-30T12:00:00');
		$this->trip('2026-07-01T00:30:00', ['started_at_off' => 120, 'started_at' => gmmktime(22, 30, 0, 6, 30, 2026)]);

		$claim = $this->claim();

		$this->assertSame([300, 350], array_map(static fn (array $line): ?int => $line['rate'], $claim->lines));
		$this->assertSame(['car 2026-06-30', 'car 2026-07-01'], $this->asked);
		$this->assertSame(6500, $claim->total);
	}

	/**
	 * A day the table does not cover, or a trip whose kilometres nobody stated, prints with no
	 * amount and stays out of the total: a sum that silently counts it as nothing is wrong.
	 */
	public function testWhatCannotBeValuedIsLeftOutOfTheTotal(): void {
		$this->trip('2026-01-01T10:00:00');
		$this->trip('2026-01-02T10:00:00', ['distance' => null, 'end_odo' => 50000]);
		$this->trip('2026-01-03T10:00:00', ['distance' => 40]);

		$claim = $this->claim();

		$this->assertSame([null, null, 1200], self::amounts($claim));
		$this->assertNull($claim->lines[0]['rate']);
		$this->assertNull($claim->lines[1]['kilometres']);
		$this->assertSame(1200, $claim->total);
		$this->assertSame(40, $claim->kilometres);
	}

	/** Nothing valued is "not stated", never a claim of 0,00 €. */
	public function testAYearWithNothingValuedHasNoTotal(): void {
		$this->trip('2026-01-01T10:00:00');

		$claim = $this->claim();

		$this->assertCount(1, $claim->lines);
		$this->assertNull($claim->total);
		$this->assertNull($claim->kilometres);
	}

	/** A trip belongs to the year it set off in where it set off, as in the Fahrtenbuch. */
	public function testTheYearIsTheTripsOwn(): void {
		$this->trip('2026-01-01T00:30:00');
		$this->trip('2027-01-01T00:30:00');

		$claim = $this->claim();

		$this->assertCount(1, $claim->lines);
		$this->assertSame('car 2026-01-01', $this->asked[0]);
	}

	public function testADriverMayReadItAndAStrangerMayNot(): void {
		$this->claim(self::DRIVER);

		$this->expectException(AccessDeniedException::class);
		$this->service()->year('mallory', self::VEHICLE, 2026);
	}

	/** Who read which vehicle's claim is logged by ids, as the logbook is (docs/security.md). */
	public function testReadingItIsLogged(): void {
		$this->claim();

		$this->assertSame([['app' => 'nextfleet', 'user' => self::OWNER, 'vehicle' => self::VEHICLE, 'year' => 2026]], $this->logged);
	}

	/**
	 * Unavailable, not empty: where the country states no rate, prints no claim, or the vehicle
	 * counts hours, which no kilometre rate values.
	 */
	public function testThereIsNoClaimWithoutARateAPageOrKilometres(): void {
		$this->trip('2026-03-02T08:00:00');

		$this->hasRates = false;
		$this->assertNull($this->service()->year(self::OWNER, self::VEHICLE, 2026));

		$this->hasRates = true;
		$this->hasRenderer = false;
		$this->assertNull($this->service()->year(self::OWNER, self::VEHICLE, 2026));

		$this->hasRenderer = true;
		$this->odoUnit = 'h';
		$this->assertNull($this->service()->year(self::OWNER, self::VEHICLE, 2026));

		$this->assertNull($this->printed);
		$this->assertSame([], $this->logged);
	}
}
