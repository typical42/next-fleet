<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Budget;
use OCA\NextFleet\Db\BudgetMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\RefusedException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * A vehicle's budgets (CONTEXT.md, Budget): the owner sets one per calendar year, and whoever sees
 * the vehicle's costs reads them. The warning is BudgetWarnings'.
 *
 * @psalm-import-type NextFleetBudget from \OCA\NextFleet\ResponseDefinitions
 */
class BudgetService {
	use TTransactional;

	/** The refusals a client words (RefusedException). */
	public const NO_CURRENCY = 'no_currency';
	public const YEAR_TAKEN = 'year_taken';

	/** The years a budget can be for: four digits. */
	private const FIRST_YEAR = 1000;
	private const LAST_YEAR = 9999;

	public function __construct(
		private BudgetMapper $budgets,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private IDBConnection $db,
		private BudgetWarnings $warnings,
	) {
	}

	/**
	 * The vehicle's budgets by year. None for a reader the costs are kept from: a budget is money.
	 *
	 * @return list<NextFleetBudget>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not see the vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		if (!$vehicle->showsCosts()) {
			return [];
		}

		return array_map(static fn (Budget $budget): array => $budget->jsonSerialize(), $this->budgets->findOf((int)$vehicle->getId()));
	}

	/**
	 * Sets the budget of a year the vehicle has none for yet.
	 *
	 * @param array<string, mixed> $fields `year`, `amount` in cents, and `net`
	 * @return NextFleetBudget
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own the vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws RefusedException if the vehicle has no currency, or a budget for the year
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		$year = self::year($fields);
		[$amount, $net] = self::money($fields);

		return $this->atomicRetry(function () use ($userId, $vehicle, $year, $amount, $net): array {
			$vehicleId = (int)$vehicle->getId();
			// Two creates of one year queue here, so the second finds the first.
			$this->vehicles->hold($vehicleId);
			self::priced($this->vehicles->findByUuid($vehicle->getUuid())->getCurrency());
			if ($this->budgets->findYear($vehicleId, $year) !== null) {
				throw new RefusedException('the vehicle has a budget for ' . $year, self::YEAR_TAKEN);
			}
			$budget = new Budget();
			$budget->setVehicleId($vehicleId);
			$budget->setCreatedBy($userId);
			$budget->setYear($year);
			$budget->setAmount($amount);
			$budget->setNet($net);
			$budget->setWarned(0);

			return $this->budgets->insert($budget)->jsonSerialize();
		}, $this->db);
	}

	/**
	 * Changes the amount and whether it is net. The year stays: a budget for another year is
	 * another budget. A share of it the costs no longer reach is told again once reached
	 * (BudgetWarnings::rearm()).
	 *
	 * @param array<string, mixed> $fields `amount` in cents, and `net`
	 * @return NextFleetBudget
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own the vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $vehicleUuid, string $budgetUuid, int $expectedUpdatedAt, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);
		[$amount, $net] = self::money($fields);

		[$budget, $rearmed] = $this->atomicRetry(function () use ($vehicle, $budgetUuid, $expectedUpdatedAt, $amount, $net): array {
			$this->vehicles->hold((int)$vehicle->getId());
			$budget = $this->budgets->findOnVehicle((int)$vehicle->getId(), $budgetUuid);
			$budget->setAmount($amount);
			$budget->setNet($net);
			$rearmed = $this->warnings->rearm($vehicle, $budget);

			return [$this->budgets->updateChecked($budget, $expectedUpdatedAt), $rearmed];
		}, $this->db);
		// After the commit, as a sweep's sends: the notifier drops a notice of a share no longer
		// reached anyway (Notifier::budgeted()), this puts the one still reached in its place.
		if ($rearmed) {
			$this->warnings->retell($vehicle, $budget);
		}

		return $budget->jsonSerialize();
	}

	/**
	 * @return NextFleetBudget the budget as it was left
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user does not own the vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCA\NextFleet\Exception\StaleUpdateException
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $vehicleUuid, string $budgetUuid, int $expectedUpdatedAt): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::OWN, $vehicleUuid);

		$gone = $this->atomicRetry(function () use ($vehicle, $budgetUuid, $expectedUpdatedAt): Budget {
			$this->vehicles->hold((int)$vehicle->getId());
			$budget = $this->budgets->findOnVehicle((int)$vehicle->getId(), $budgetUuid);

			return $this->budgets->softDelete($budget, $expectedUpdatedAt);
		}, $this->db);
		$this->warnings->withdraw($gone);

		return $gone->jsonSerialize();
	}

	/**
	 * A budget is an amount in the vehicle's currency; without one it is a number nobody can read.
	 *
	 * @throws RefusedException
	 */
	private static function priced(?string $currency): void {
		if ($currency === null) {
			throw new RefusedException('the vehicle has no currency', self::NO_CURRENCY);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @throws \InvalidArgumentException
	 */
	private static function year(array $fields): int {
		$year = Field::read('year', 'count', self::LAST_YEAR, $fields['year'] ?? null);
		if (!is_int($year) || $year < self::FIRST_YEAR) {
			throw new \InvalidArgumentException('year is a calendar year from ' . self::FIRST_YEAR . ' to ' . self::LAST_YEAR);
		}

		return $year;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{int, bool} the amount in cents, and whether it is net
	 * @throws \InvalidArgumentException
	 */
	private static function money(array $fields): array {
		$amount = Field::read('amount', 'count', Field::MONEY, $fields['amount'] ?? null);
		if (!is_int($amount) || $amount === 0) {
			throw new \InvalidArgumentException('amount is a whole number of cents above 0');
		}
		$net = Field::read('net', 'flag', null, $fields['net'] ?? null);
		if (!is_bool($net)) {
			throw new \InvalidArgumentException('net is a field every budget carries');
		}

		return [$amount, $net];
	}
}
