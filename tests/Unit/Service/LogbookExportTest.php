<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\IReportRenderer;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use OCA\NextFleet\Service\Completeness;
use OCA\NextFleet\Service\LogbookExport;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What the core hands a country to print for one vehicle and one year: which trips, what each one
 * lacks, and when Logbook Mode was on. How it prints is tests/Country's; the renderer here only
 * keeps what it was given.
 *
 * The mappers are stores, the way TimelineServiceTest keeps them. What the trips query selects
 * against a real database is tests/Integration/LogbookExportTest.php's.
 */
class LogbookExportTest extends TestCase {
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const VEHICLE_ID = 7;
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const SOURCE = 'https://example.org/the-requirement';

	/** 2025-06-01 00:00 UTC, when the vehicle was created. */
	private const CREATED_AT = 1748736000;
	/** 2026-01-01 00:00 UTC. */
	private const NEW_YEAR = 1767225600;
	/** 2027-01-01 00:00 UTC. */
	private const NEXT_NEW_YEAR = 1798761600;

	/** @var list<Trip> */
	private array $tripRows = [];
	/** @var list<Audit> */
	private array $auditRows = [];
	private int $nextId = 1;
	private ?bool $logbookMode = null;
	private bool $hasRules = true;
	private bool $hasRenderer = true;
	private ?LogbookReport $printed = null;
	/** @var list<array{string, array<string, mixed>}> */
	private array $logged = [];

	protected function setUp(): void {
		$this->tripRows = [];
		$this->auditRows = [];
		$this->nextId = 1;
		$this->logbookMode = null;
		$this->hasRules = true;
		$this->hasRenderer = true;
		$this->printed = null;
		$this->logged = [];
	}

	private function service(): LogbookExport {
		$trips = $this->createMock(TripMapper::class);
		$trips->method('findAnyStartedBetween')->willReturnCallback(
			fn (int $vehicleId, int $from, int $to): array => array_values(array_filter(
				$this->tripRows,
				static fn (Trip $trip): bool => $trip->getVehicleId() === $vehicleId
					&& $trip->getStartedAt() >= $from && $trip->getStartedAt() < $to,
			)),
		);

		$audit = $this->createMock(AuditMapper::class);
		$audit->method('findForEntity')->willReturnCallback(
			fn (string $entity, int $id): array => array_values(array_filter(
				$this->auditRows,
				static fn (Audit $row): bool => $row->getEntity() === $entity && $row->getEntityId() === $id,
			)),
		);
		$audit->method('findForEntities')->willReturnCallback(
			fn (string $entity, array $ids): array => array_values(array_filter(
				$this->auditRows,
				static fn (Audit $row): bool => $row->getEntity() === $entity && in_array($row->getEntityId(), $ids, true),
			)),
		);

		// Who reaches which vehicle is VehicleAccessTest's; what this states is which operation an
		// export asks the gate for.
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
					'jurisdiction' => 'somewhere',
					'logbook_mode' => $this->logbookMode,
					'created_at' => self::CREATED_AT,
				]);
			},
		);

		$rules = $this->createMock(ILogbookRules::class);
		$rules->method('mandatoryFields')->willReturnCallback(
			static fn (string $category): array => $category === Trip::BUSINESS ? ['purpose', 'partner'] : [],
		);
		$rules->method('sourceUrl')->willReturn(self::SOURCE);

		$renderer = $this->createMock(IReportRenderer::class);
		$renderer->method('render')->willReturnCallback(function (LogbookReport $report): string {
			$this->printed = $report;

			return '<!DOCTYPE html>printed';
		});

		$profile = $this->createMock(IJurisdiction::class);
		$profile->method('logbookRules')->willReturnCallback(fn (): ?ILogbookRules => $this->hasRules ? $rules : null);
		$profile->method('logbookRenderer')->willReturnCallback(fn (): ?IReportRenderer => $this->hasRenderer ? $renderer : null);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($profile);
		$jurisdictions = new Jurisdictions($container);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = [(string)$message, $context];
		});

		return new LogbookExport($fleet, $trips, $audit, new Completeness($jurisdictions), $jurisdictions, $logger);
	}

	/** @param array<string, mixed> $row */
	private function trip(int $startedAt, int $offset = 60, array $row = []): Trip {
		$id = $this->nextId++;
		$trip = Trip::fromRow($row + [
			'id' => $id,
			'uuid' => sprintf('0195e2f1-0000-4000-8000-%012d', $id),
			'vehicle_id' => self::VEHICLE_ID,
			'started_at' => $startedAt,
			'started_at_off' => $offset,
			'ended_at' => $startedAt + 3600,
			'ended_at_off' => $offset,
			'end_odo' => 120000 + $id,
			'purpose' => 'Abnahme',
			'partner' => 'Muster GmbH',
			'category' => Trip::BUSINESS,
		]);
		$this->tripRows[] = $trip;

		return $trip;
	}

	/** One flip of the mode, as VehicleService writes it. */
	private function switched(bool $to, int $at): void {
		$this->auditRows[] = Audit::fromRow([
			'id' => $this->nextId++,
			'entity' => Audit::VEHICLE,
			'entity_id' => self::VEHICLE_ID,
			'diff_json' => json_encode(['change' => 'switched', 'fields' => ['logbook_mode' => [!$to, $to]]]),
			'created_at' => $at,
			'created_by' => self::OWNER,
		]);
	}

	/**
	 * One change to a trip, as TripService writes it.
	 *
	 * @param array<string, mixed> $diff
	 */
	private function changed(Trip $trip, int $at, array $diff): void {
		$this->auditRows[] = Audit::fromRow([
			'id' => $this->nextId++,
			'entity' => Audit::TRIP,
			'entity_id' => $trip->getId(),
			'diff_json' => json_encode($diff),
			'created_at' => $at,
			'created_by' => self::OWNER,
		]);
	}

	private function export(int $year = 2026, string $userId = self::OWNER): ?string {
		return $this->service()->year($userId, self::VEHICLE, $year);
	}

	/** @return list<string> */
	private function printedTrips(): array {
		$this->assertNotNull($this->printed, 'nothing was printed');

		return array_map(static fn (array $line): string => $line['trip']->getUuid(), $this->printed->trips);
	}

	public function testTheYearIsPrintedByTheVehiclesJurisdiction(): void {
		$this->assertSame('<!DOCTYPE html>printed', $this->export());
		$this->assertNotNull($this->printed);
		$this->assertSame(2026, $this->printed->year);
		$this->assertSame(self::VEHICLE, $this->printed->vehicle->getUuid());
		$this->assertSame(self::SOURCE, $this->printed->sourceUrl);
	}

	/**
	 * A year is the local calendar year the trip set off in (docs/architecture.md#time): New Year's
	 * Eve at half past midnight in Berlin is already the new year, and its UTC instant is not.
	 */
	public function testTheYearIsTheOneTheTripsSetOffInWhereTheySetOff(): void {
		$lastOfOld = $this->trip(self::NEW_YEAR - 3600, 0); // 31.12.2025 23:00 in London
		$firstOfNew = $this->trip(self::NEW_YEAR - 1800, 60); // 01.01.2026 00:30 in Berlin
		$lastOfNew = $this->trip(self::NEXT_NEW_YEAR - 5400, 60); // 31.12.2026 23:30 in Berlin
		$firstOfNext = $this->trip(self::NEXT_NEW_YEAR - 1800, 60); // 01.01.2027 00:30 in Berlin

		$this->export();

		$this->assertSame([$firstOfNew->getUuid(), $lastOfNew->getUuid()], $this->printedTrips());
		$this->assertNotContains($lastOfOld->getUuid(), $this->printedTrips());
		$this->assertNotContains($firstOfNext->getUuid(), $this->printedTrips());
	}

	/** A voided trip is in the logbook, where it happened - listing it as voided is the renderer's. */
	public function testAVoidedTripIsPrintedWhereItHappened(): void {
		$first = $this->trip(self::NEW_YEAR + 86400);
		$voided = $this->trip(self::NEW_YEAR + 2 * 86400, 60, ['deleted_at' => self::NEW_YEAR + 3 * 86400]);
		$last = $this->trip(self::NEW_YEAR + 4 * 86400);

		$this->export();

		$this->assertSame([$first->getUuid(), $voided->getUuid(), $last->getUuid()], $this->printedTrips());
		$this->assertNotNull($this->printed?->trips[1]['trip']->getDeletedAt());
	}

	/** Each line carries what its ruleset requires and it leaves unstated - the timeline's measure. */
	public function testEachTripCarriesWhatItLacks(): void {
		$this->logbookMode = true;
		$this->trip(self::NEW_YEAR + 86400, 60, ['partner' => null]);
		$this->trip(self::NEW_YEAR + 2 * 86400, 60, ['partner' => null, 'category' => Trip::PRIVATE]);

		$this->export();

		$this->assertSame([['partner'], []], array_column($this->printed?->trips ?? [], 'missing'));
	}

	/**
	 * What the logbook asks is said only under the mode (docs/features.md#logbook-mode), so a trip is
	 * marked only when it set off inside a period: from the flip on, up to but not at the flip off.
	 */
	public function testOnlyATripSetOffUnderTheModeLacksAnything(): void {
		$this->logbookMode = false;
		$this->switched(true, self::NEW_YEAR + 10 * 86400);
		$this->switched(false, self::NEW_YEAR + 20 * 86400);
		foreach ([9, 10, 15, 20, 21] as $day) {
			$this->trip(self::NEW_YEAR + $day * 86400, 60, ['partner' => null]);
		}

		$this->export();

		$this->assertSame([[], ['partner'], ['partner'], [], []], array_column($this->printed?->trips ?? [], 'missing'));
	}

	/**
	 * A change the auditor cannot see is not documented, so each line carries its trip's late
	 * changes, oldest first: what kind, when, and what each field was before. A void and a restore
	 * are carried too: a restore months later would otherwise erase a void without a trace. A change
	 * inside the lock delay is still the entry being made and is not carried.
	 */
	public function testEachTripCarriesItsLateChangesAndNoOtherChange(): void {
		$this->logbookMode = true;
		$edited = $this->trip(self::NEW_YEAR + 86400);
		$untouched = $this->trip(self::NEW_YEAR + 2 * 86400);
		$later = self::NEW_YEAR + 30 * 86400;
		$this->changed($edited, self::NEW_YEAR + 86400, ['change' => 'created', 'fields' => ['purpose' => [null, 'Abnahme']]]);
		$this->changed($edited, self::NEW_YEAR + 2 * 86400, ['change' => 'edited', 'fields' => ['partner' => [null, 'Muster GmbH']], 'late' => false]);
		$this->changed($edited, self::NEW_YEAR + 2 * 86400 + 60, ['change' => 'voided', 'fields' => ['deleted_at' => [null, self::NEW_YEAR + 2 * 86400 + 60]], 'late' => false]);
		$this->changed($edited, $later, ['change' => 'restored', 'fields' => ['deleted_at' => [self::NEW_YEAR + 2 * 86400 + 60, null]], 'late' => true]);
		$this->changed($edited, $later + 60, ['change' => 'edited', 'fields' => ['purpose' => ['Besuch', 'Abnahme'], 'end_odo' => [120100, 120001]], 'late' => true]);
		$this->changed($edited, $later + 120, ['change' => 'voided', 'fields' => ['deleted_at' => [null, $later + 120]], 'late' => true]);

		$this->export();

		$offsets = ['started_at_off' => 60, 'ended_at_off' => 60];
		$this->assertSame([
			[
				['change' => 'restored', 'at' => $later, 'fields' => ['deleted_at' => [self::NEW_YEAR + 2 * 86400 + 60, null]], 'offsets' => $offsets],
				['change' => 'edited', 'at' => $later + 60, 'fields' => ['purpose' => ['Besuch', 'Abnahme'], 'end_odo' => [120100, 120001]], 'offsets' => $offsets],
				['change' => 'voided', 'at' => $later + 120, 'fields' => ['deleted_at' => [null, $later + 120]], 'offsets' => $offsets],
			],
			[],
		], array_column($this->printed?->trips ?? [], 'late'));
		$this->assertSame($untouched->getUuid(), $this->printedTrips()[1]);
	}

	/**
	 * A time before a change is only readable with the offset in force then, and a later edit may
	 * have moved it since - a timely one too. So each late change carries the offsets as they stood
	 * before it, read back from the trip as it is now through every row after.
	 */
	public function testALateChangeCarriesTheOffsetsInForceBeforeIt(): void {
		$this->logbookMode = true;
		$trip = $this->trip(self::NEW_YEAR + 86400, 120);
		$later = self::NEW_YEAR + 30 * 86400;
		$this->changed($trip, $later, ['change' => 'edited', 'fields' => ['started_at' => [self::NEW_YEAR + 82800, self::NEW_YEAR + 86400]], 'late' => true]);
		$this->changed($trip, $later + 60, ['change' => 'edited', 'fields' => ['started_at_off' => [60, 120]], 'late' => false]);
		$this->changed($trip, $later + 120, ['change' => 'edited', 'fields' => ['ended_at_off' => [0, 120], 'purpose' => ['Besuch', 'Abnahme']], 'late' => true]);

		$this->export();

		$this->assertSame(
			[['started_at_off' => 60, 'ended_at_off' => 0], ['started_at_off' => 120, 'ended_at_off' => 0]],
			array_column($this->printed?->trips[0]['late'] ?? [], 'offsets'),
		);
	}

	/** A vehicle whose jurisdiction states no requirement cites none. */
	public function testNoRulesetCitesNoSource(): void {
		$this->hasRules = false;

		$this->export();

		$this->assertNull($this->printed?->sourceUrl);
	}

	/** A jurisdiction with no export has none - not an empty page that would pass for one. */
	public function testAJurisdictionWithoutAnExportPrintsNothing(): void {
		$this->hasRenderer = false;

		$this->assertNull($this->export());
		$this->assertNull($this->printed);
	}

	/**
	 * A vehicle created with the mode on and never switched has been under it since it was created
	 * (docs/features.md#logbook-mode): that period has no row of its own to begin.
	 */
	public function testAModeOnSinceCreationIsOnePeriodFromCreation(): void {
		$this->logbookMode = true;

		$this->export();

		$this->assertSame([['from' => self::CREATED_AT, 'to' => null]], $this->printed?->periods);
	}

	/** A vehicle nobody ever switched on was never under the mode. */
	public function testAModeNeverSwitchedOnHasNoPeriod(): void {
		$this->export();

		$this->assertSame([], $this->printed?->periods);
	}

	/**
	 * Every flip is read off the trail: on at creation, off, on again, off again. The first row's
	 * `before` says the vehicle was created under the mode, whatever the column says now.
	 */
	public function testThePeriodsAreReadOffEveryFlip(): void {
		$this->logbookMode = false;
		$this->switched(false, self::NEW_YEAR + 10 * 86400);
		$this->switched(true, self::NEW_YEAR + 20 * 86400);
		$this->switched(false, self::NEW_YEAR + 30 * 86400);

		$this->export();

		$this->assertSame([
			['from' => self::CREATED_AT, 'to' => self::NEW_YEAR + 10 * 86400],
			['from' => self::NEW_YEAR + 20 * 86400, 'to' => self::NEW_YEAR + 30 * 86400],
		], $this->printed?->periods);
	}

	/**
	 * The trips are the local year's, so the periods reach as far: a mode switched off at half past
	 * midnight in Berlin on New Year's Day covered trips this export lists, although its UTC
	 * instant is still in the old year.
	 */
	public function testAPeriodEndingInTheYearsFirstLocalHourIsStated(): void {
		$this->logbookMode = false;
		$this->switched(false, self::NEW_YEAR - 1800);

		$this->export();

		$this->assertSame([['from' => self::CREATED_AT, 'to' => self::NEW_YEAR - 1800]], $this->printed?->periods);
	}

	/**
	 * No offset reaches further than fourteen hours east or twelve west (TripService), so a period
	 * that ended fifteen hours before the UTC year, or begins thirteen after it, holds none of its
	 * trips - and the year was not under the mode.
	 */
	public function testAPeriodNoLocalHourOfTheYearFallsInIsNotStated(): void {
		$this->logbookMode = false;
		$this->switched(false, self::NEW_YEAR - 15 * 3600);
		$this->switched(true, self::NEXT_NEW_YEAR + 13 * 3600);

		$this->export();

		$this->assertSame([], $this->printed?->periods);
	}

	/** A period that ended before the year or began after it is not the year's to state. */
	public function testOnlyThePeriodsThatReachIntoTheYearAreStated(): void {
		$this->logbookMode = true;
		$this->switched(true, self::NEW_YEAR - 200 * 86400);
		$this->switched(false, self::NEW_YEAR - 100 * 86400);
		$this->switched(true, self::NEW_YEAR - 50 * 86400);
		$this->switched(false, self::NEXT_NEW_YEAR + 10 * 86400);
		$this->switched(true, self::NEXT_NEW_YEAR + 20 * 86400);

		$this->export();

		$this->assertSame([
			['from' => self::NEW_YEAR - 50 * 86400, 'to' => self::NEXT_NEW_YEAR + 10 * 86400],
		], $this->printed?->periods);
	}

	/**
	 * Anyone who may see the vehicle may print its logbook - it shows nothing the timeline does
	 * not - and nobody else.
	 */
	public function testReadingTheVehicleIsWhatAnExportTakes(): void {
		$this->assertNotNull($this->export(2026, self::DRIVER));

		$this->expectException(AccessDeniedException::class);
		$this->export(2026, 'bob');
	}

	/**
	 * docs/security.md: an export is logged, by ids - never with a destination, a purpose or a plate.
	 */
	public function testAnExportIsLoggedByIds(): void {
		$this->trip(self::NEW_YEAR + 86400);

		$this->export();

		$this->assertCount(1, $this->logged);
		[, $context] = $this->logged[0];
		$this->assertSame(['user' => self::OWNER, 'vehicle' => self::VEHICLE, 'year' => 2026], array_diff_key($context, ['app' => true]));
		$this->assertStringNotContainsString('B-XY', json_encode($this->logged, JSON_THROW_ON_ERROR));
	}
}
