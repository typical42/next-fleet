<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Everything an Expense is written under. It knows no counter, so unlike a fill-up or a
 * Maintenance Record it leaves the odometer alone.
 *
 * @psalm-import-type NextFleetExpense from \OCA\NextFleet\ResponseDefinitions
 */
class ExpenseService {
	use TTransactional;

	/** What an Expense can be (docs/architecture.md#data-model), in the order the sheet offers them. */
	public const CATEGORIES = ['insurance', 'tax', 'toll', 'parking', 'fine', 'lease', 'other'];

	/**
	 * The columns a request may set, in the shape MaintenanceService::WRITABLE has.
	 *
	 * @var array<string, array{string, string, int|list<string>|null}>
	 */
	private const WRITABLE = [
		'spent_at' => ['setSpentAt', 'count', Field::MOMENT],
		'spent_at_off' => ['setSpentAtOff', 'offset', null],
		'category' => ['setCategory', 'word', self::CATEGORIES],
		'amount' => ['setAmount', 'count', Field::MONEY],
		'vat_rate' => ['setVatRate', 'count', Field::RATE],
		'notes' => ['setNotes', 'text', Field::TEXT],
	];

	/**
	 * The columns the database will not default. The sheet requires the amount and prefills the
	 * moment, so a request without one is a client that did not.
	 */
	private const REQUIRED = ['spent_at', 'spent_at_off', 'amount'];

	public function __construct(
		private ExpenseMapper $expenses,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private Jurisdictions $jurisdictions,
		private IDBConnection $db,
	) {
	}

	/**
	 * Writes one Expense.
	 *
	 * @param array<string, mixed> $fields
	 * @return NextFleetExpense the row as written, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function record(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->expenses,
			static fn (Expense $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			static fn (Expense $row): array => $row->jsonSerialize(),
		);

		// Held though an Expense moves no counter, so every write on a vehicle queues behind the
		// same row (docs/architecture.md#odometer-rules, rule 2). Retried for the reason
		// TripService::record() gives. The replay builds a fresh row.
		return $once->run(fn (): array => $this->atomicRetry(function () use ($userId, $vehicle, $fields, $once): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$once->check();

			return $this->add($vehicle, $userId, $fields, $once);
		}, $this->db));
	}

	/**
	 * What record() writes, for a caller that has reached the vehicle and holds it, as
	 * EnergyService::add() is.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Expense>|null $once the client's uuid for the row, if it sent one
	 * @return NextFleetExpense the row as written, in its wire form
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function add(Vehicle $vehicle, string $userId, array $fields, ?Once $once = null): array {
		$expense = new Expense();
		$once?->stamp($expense);
		$expense->setVehicleId((int)$vehicle->getId());
		$expense->setCreatedBy($userId);
		self::apply($expense, $fields);

		return $this->expenses->insert($expense)->jsonSerialize();
	}

	/**
	 * Rewrites one Expense in place. The request is the whole Expense, as record() takes it.
	 *
	 * @param array<string, mixed> $fields
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return NextFleetExpense the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this Expense
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Expense has changed since
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $expenseUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $expenseUuid, $expectedUpdatedAt, $fields): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$expense = $this->expenses->findOnVehicle((int)$vehicle->getId(), $expenseUuid);
			$this->fleet->change($userId, VehicleAccess::EDIT, $vehicle, $expense->getCreatedBy());
			self::apply($expense, $fields);

			return $this->expenses->updateChecked($expense, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
	}

	/**
	 * Soft-deletes one Expense, and answers with the token the undo is checked against.
	 *
	 * @return NextFleetExpense the row as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete this Expense
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Expense has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $expenseUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $expenseUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$expense = $this->expenses->findOnVehicle((int)$vehicle->getId(), $expenseUuid);
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $expense->getCreatedBy());

			return $this->remove($expense, $expectedUpdatedAt);
		}, $this->db);
	}

	/**
	 * What delete() writes, for a caller that holds the vehicle and found the Expense on it, as
	 * EnergyService::remove() is.
	 *
	 * @return NextFleetExpense the row as it was left, in its wire form
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Expense has changed since
	 * @throws \OCP\DB\Exception
	 */
	public function remove(Expense $expense, int $expectedUpdatedAt): array {
		return $this->expenses->softDelete($expense, $expectedUpdatedAt)->jsonSerialize();
	}

	/**
	 * Undo, on the token the delete answered with (TripService::restore()).
	 *
	 * @return NextFleetExpense the row as it now stands, in its wire form
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not delete this Expense
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException if the Expense has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $expenseUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);

		return $this->atomicRetry(function () use ($userId, $vehicle, $expenseUuid, $expectedUpdatedAt): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$expense = $this->expenses->findAnyOnVehicle((int)$vehicle->getId(), $expenseUuid);
			$this->fleet->change($userId, VehicleAccess::DELETE, $vehicle, $expense->getCreatedBy());

			return $this->expenses->restoreChecked($expense, $expectedUpdatedAt)->jsonSerialize();
		}, $this->db);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 */
	private static function apply(Expense $expense, array $fields): void {
		foreach (self::WRITABLE as $column => [$setter, $kind, $limit]) {
			$value = Field::read($column, $kind, $limit, $fields[$column] ?? null);
			if ($value === null && in_array($column, self::REQUIRED, true)) {
				throw new \InvalidArgumentException($column . ' is a field every expense carries');
			}
			$expense->$setter($value);
		}
	}

	/**
	 * What the sheet prefills an Expense with (docs/ui.md): the VAT rate of the vehicle's
	 * jurisdiction on the day of the expense, or 0 for a category the jurisdiction charges no VAT
	 * on - a stated rate, where null would leave the net figures counting it gross.
	 *
	 * @param array<string, mixed> $fields `at` and `off`: the moment the sheet is on; `category`
	 *                                     when one is picked
	 * @return array{vat_rate: ?int}
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not log on this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException if the moment is not one
	 * @throws \OCP\DB\Exception
	 */
	public function prefill(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$at = Field::read('at', 'count', Field::MOMENT, $fields['at'] ?? null);
		$off = Field::read('off', 'offset', null, $fields['off'] ?? null);
		if (!is_int($at) || !is_int($off)) {
			throw new \InvalidArgumentException('at and off are the moment a prefill is for');
		}
		$category = Field::read('category', 'word', self::CATEGORIES, $fields['category'] ?? null);
		$free = $this->jurisdictions->get($vehicle->getJurisdiction())->rates()?->vatFreeCategories() ?? [];

		return ['vat_rate' => in_array($category, $free, true) ? 0 : $this->jurisdictions->vatRateAt($vehicle->getJurisdiction(), $at, $off)];
	}
}
