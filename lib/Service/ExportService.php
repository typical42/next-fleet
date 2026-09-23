<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use Psr\Log\LoggerInterface;

/**
 * One vehicle's year as CSV, a file per table (docs/architecture.md#csv-export): the
 * machine-readable path beside the printed one (docs/adr/0005-no-pdf-library.md).
 */
class ExportService {
	/** Each file's header. An instant is two columns: its wall clock and its offset in minutes. */
	private const HEADERS = [
		'trips' => ['uuid', 'started', 'started_offset_min', 'ended', 'ended_offset_min', 'start_odo', 'end_odo', 'distance', 'odo_unit', 'from', 'to', 'purpose', 'partner', 'category', 'reconciled', 'voided'],
		'energy' => ['uuid', 'filled', 'filled_offset_min', 'odo', 'second_odo', 'odo_unit', 'energy', 'amount', 'unit_price', 'total', 'currency', 'vat_rate', 'full_tank', 'missed_previous', 'station', 'is_dc', 'location_kind'],
		'maintenance' => ['uuid', 'done', 'done_offset_min', 'odo', 'second_odo', 'odo_unit', 'type', 'title', 'vendor', 'cost', 'currency', 'vat_rate', 'notes'],
		'expenses' => ['uuid', 'spent', 'spent_offset_min', 'category', 'amount', 'currency', 'vat_rate', 'notes'],
	];

	public function __construct(
		private VehicleService $fleet,
		private TripMapper $trips,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The rows of one table dated in one local year (`LocalYear`), so no zone is asked for. Voided
	 * trips are in it and marked; a deleted row of any other table is not, since only a trip is
	 * voided rather than deleted.
	 *
	 * @param string $table `trips`, `energy`, `maintenance` or `expenses`
	 * @return array{name: string, body: string}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if there is no such table
	 * @throws \OCP\DB\Exception
	 */
	public function csv(string $userId, string $vehicleUuid, int $year, string $table): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		if (!isset(self::HEADERS[$table])) {
			throw new \InvalidArgumentException('No such export');
		}

		// docs/security.md: the trip file is the most sensitive thing this app hands out.
		$this->logger->info('CSV export', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => $vehicleUuid,
			'year' => $year,
			'table' => $table,
		]);

		$id = (int)$vehicle->getId();
		[$start, $end] = LocalYear::window($year);
		$rows = match ($table) {
			'trips' => array_map(static fn (Trip $row): ?array => self::trip($vehicle, $year, $row), $this->trips->findAnyStartedBetween($id, $start, $end)),
			'energy' => array_map(static fn (Energy $row): ?array => self::fillUp($vehicle, $year, $row), $this->energy->findBetween($id, $start, $end)),
			'maintenance' => array_map(static fn (Maintenance $row): ?array => self::record($vehicle, $year, $row), $this->maintenance->findBetween($id, $start, $end)),
			'expenses' => array_map(static fn (Expense $row): ?array => self::expense($vehicle, $year, $row), $this->expenses->findBetween($id, $start, $end)),
		};

		return [
			'name' => self::fileName($vehicle, $year, $table),
			'body' => Csv::of(self::HEADERS[$table], array_values(array_filter($rows, static fn (?array $row): bool => $row !== null))),
		];
	}

	/**
	 * Each row function answers null for a row the window caught that belongs to the next or the
	 * last year where it happened.
	 *
	 * @return ?list<int|string|null>
	 */
	private static function trip(Vehicle $vehicle, int $year, Trip $trip): ?array {
		if (!LocalYear::holds($year, $trip->getStartedAt(), $trip->getStartedAtOff())) {
			return null;
		}

		return [
			$trip->getUuid(),
			self::local($trip->getStartedAt(), $trip->getStartedAtOff()),
			$trip->getStartedAtOff(),
			self::local($trip->getEndedAt(), $trip->getEndedAtOff()),
			$trip->getEndedAtOff(),
			$trip->getStartOdo(),
			$trip->getEndOdo(),
			$trip->getDistance(),
			$vehicle->getOdoUnit(),
			$trip->getFromLabel(),
			$trip->getToLabel(),
			$trip->getPurpose(),
			$trip->getPartner(),
			$trip->getCategory(),
			(int)$trip->getReconciled(),
			(int)($trip->getDeletedAt() !== null),
		];
	}

	/** @return ?list<int|string|null> */
	private static function fillUp(Vehicle $vehicle, int $year, Energy $fill): ?array {
		if (!LocalYear::holds($year, $fill->getFilledAt(), $fill->getFilledAtOff())) {
			return null;
		}

		return [
			$fill->getUuid(),
			self::local($fill->getFilledAt(), $fill->getFilledAtOff()),
			$fill->getFilledAtOff(),
			$fill->getOdo(),
			$fill->getSecondOdo(),
			$vehicle->getOdoUnit(),
			$fill->getEnergy(),
			$fill->getAmount(),
			$fill->getUnitPrice(),
			$fill->getTotal(),
			$vehicle->getCurrency(),
			$fill->getVatRate(),
			(int)$fill->getFullTank(),
			(int)$fill->getMissedPrevious(),
			$fill->getStation(),
			(int)$fill->getIsDc(),
			$fill->getLocationKind(),
		];
	}

	/** @return ?list<int|string|null> */
	private static function record(Vehicle $vehicle, int $year, Maintenance $record): ?array {
		if (!LocalYear::holds($year, $record->getDoneAt(), $record->getDoneAtOff())) {
			return null;
		}

		return [
			$record->getUuid(),
			self::local($record->getDoneAt(), $record->getDoneAtOff()),
			$record->getDoneAtOff(),
			$record->getOdo(),
			$record->getSecondOdo(),
			$vehicle->getOdoUnit(),
			$record->getType(),
			$record->getTitle(),
			$record->getVendor(),
			$record->getCost(),
			$vehicle->getCurrency(),
			$record->getVatRate(),
			$record->getNotes(),
		];
	}

	/** @return ?list<int|string|null> */
	private static function expense(Vehicle $vehicle, int $year, Expense $expense): ?array {
		if (!LocalYear::holds($year, $expense->getSpentAt(), $expense->getSpentAtOff())) {
			return null;
		}

		return [
			$expense->getUuid(),
			self::local($expense->getSpentAt(), $expense->getSpentAtOff()),
			$expense->getSpentAtOff(),
			$expense->getCategory(),
			$expense->getAmount(),
			$vehicle->getCurrency(),
			$expense->getVatRate(),
			$expense->getNotes(),
		];
	}

	/** The wall clock where it happened, in the one form every spreadsheet reads as a date. */
	private static function local(int $at, int $off): string {
		return gmdate('Y-m-d H:i', $at + $off * 60);
	}

	/** The plate if there is one; a filename carries nothing a file system could misread. */
	private static function fileName(Vehicle $vehicle, int $year, string $table): string {
		$label = trim((string)preg_replace('/[^A-Za-z0-9-]+/', '_', $vehicle->getPlate() ?? ''), '_');

		return ($label === '' ? $vehicle->getUuid() : $label) . '-' . $year . '-' . $table . '.csv';
	}
}
