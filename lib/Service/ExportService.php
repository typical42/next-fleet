<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\BaseEntity;
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
	/**
	 * Each file's header. An instant someone entered is two columns: its wall clock and its offset
	 * in minutes. A trip's `created_at` is the server's clock, one column in UTC.
	 */
	private const HEADERS = [
		'trips' => ['uuid', 'started', 'started_offset_min', 'ended', 'ended_offset_min', 'start_odo', 'end_odo', 'distance', 'odo_unit', 'from', 'to', 'purpose', 'partner', 'category', 'reconciled', 'voided', 'created_at', 'entered_by'],
		'energy' => ['uuid', 'filled', 'filled_offset_min', 'odo', 'second_odo', 'odo_unit', 'energy', 'amount', 'unit_price', 'total', 'currency', 'vat_rate', 'full_tank', 'missed_previous', 'station', 'is_dc', 'location_kind', 'entered_by'],
		'maintenance' => ['uuid', 'done', 'done_offset_min', 'odo', 'second_odo', 'odo_unit', 'type', 'title', 'vendor', 'cost', 'currency', 'vat_rate', 'notes', 'entered_by'],
		'expenses' => ['uuid', 'spent', 'spent_offset_min', 'category', 'amount', 'currency', 'vat_rate', 'notes', 'entered_by'],
	];

	public function __construct(
		private VehicleService $fleet,
		private TripMapper $trips,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
		private EnteredBy $enteredBy,
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

		$id = (int)$vehicle->getId();
		[$start, $end] = LocalYear::window($year);
		$rows = match ($table) {
			'trips' => $this->rows($vehicle, $year, $this->trips->findAnyStartedBetween($id, $start, $end), self::trip(...)),
			'energy' => $this->rows($vehicle, $year, $this->energy->findBetween($id, $start, $end), self::fillUp(...)),
			'maintenance' => $this->rows($vehicle, $year, $this->maintenance->findBetween($id, $start, $end), self::record(...)),
			'expenses' => $this->rows($vehicle, $year, $this->expenses->findBetween($id, $start, $end), self::expense(...)),
		};
		$rows = array_values(array_filter($rows, static fn (?array $row): bool => $row !== null));

		// docs/security.md#what-is-logged: the trip file is the most sensitive thing this app hands out.
		$this->logger->info('CSV export', [
			'app' => Application::APP_ID,
			'user' => $userId,
			'vehicle' => $vehicleUuid,
			'year' => $year,
			'table' => $table,
			'rows' => count($rows),
		]);

		return [
			'name' => self::fileName($vehicle, $year, $table),
			'body' => Csv::of(self::HEADERS[$table], $rows),
		];
	}

	/**
	 * Each row ends in `entered_by`, which follows the timeline's rule (`EnteredBy::names()`).
	 *
	 * @template E of BaseEntity
	 * @param list<E> $entities
	 * @param \Closure(Vehicle, int, E): ?list<int|string|null> $row
	 * @return list<?list<int|string|null>>
	 * @throws \OCP\DB\Exception
	 */
	private function rows(Vehicle $vehicle, int $year, array $entities, \Closure $row): array {
		$names = $this->enteredBy->names($vehicle, array_map(static fn (BaseEntity $entity): string => $entity->getCreatedBy(), $entities));

		return array_map(/** @param E $entity */ static function (BaseEntity $entity) use ($vehicle, $year, $row, $names): ?array {
			$fields = $row($vehicle, $year, $entity);
			if ($fields === null) {
				return null;
			}
			$fields[] = $names === null ? null : $names[$entity->getCreatedBy()] ?? $entity->getCreatedBy();

			return $fields;
		}, $entities);
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
			gmdate('Y-m-d H:i:s', $trip->getCreatedAt()),
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
