<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\CurrencyInUseException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\BudgetService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The owner plans an amount per vehicle and calendar year (CONTEXT.md, Budget); whoever sees the
 * vehicle's costs reads it.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class BudgetTest extends TestCase {
	/** Not an account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-bgt-owner';
	/** Accounts, since a grantee has to exist on the instance. */
	private const MANAGER = 'nextfleet-test-bgt-manager';
	private const DRIVER = 'nextfleet-test-bgt-driver';

	private BudgetService $budgets;

	public static function setUpBeforeClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::MANAGER, self::DRIVER] as $uid) {
			$users->get($uid)?->delete();
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ([self::MANAGER, self::DRIVER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->budgets = Server::get(BudgetService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$db = Server::get(IDBConnection::class);
		$tables = [
			'fleet_budgets' => 'created_by',
			'fleet_access' => 'created_by',
			'fleet_vehicles' => 'user_id',
		];
		foreach ($tables as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter(
				[self::OWNER, self::MANAGER, self::DRIVER],
				$qb::PARAM_STR_ARRAY,
			)));
			$qb->executeStatement();
		}
	}

	public function testTheOwnerSetsABudgetForAYear(): void {
		$vehicle = $this->vehicle();

		$made = $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => true]);

		$this->assertSame([2031, 500000, true], [$made['year'], $made['amount'], $made['net']]);
		$this->assertSame([$made], $this->budgets->list(self::OWNER, $vehicle->getUuid()));
	}

	public function testAVehicleWithoutACurrencyTakesNoBudget(): void {
		$vehicle = $this->vehicle(['currency' => '']);

		$this->assertRefused('no_currency', fn () => $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]));
	}

	public function testAYearHasOneBudget(): void {
		$vehicle = $this->vehicle();
		$this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$this->assertRefused('year_taken', fn () => $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 600000, 'net' => false]));
		$this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2032, 'amount' => 600000, 'net' => false]);
	}

	public function testABudgetNeedsAnAmountAboveZeroAndSaysNetOrGross(): void {
		$vehicle = $this->vehicle();

		foreach ([['year' => 2031, 'amount' => 0, 'net' => false], ['year' => 2031, 'amount' => 100], ['year' => 31, 'amount' => 100, 'net' => true]] as $fields) {
			try {
				$this->budgets->create(self::OWNER, $vehicle->getUuid(), $fields);
				$this->fail('taken: ' . json_encode($fields));
			} catch (\InvalidArgumentException $e) {
				$this->assertNotInstanceOf(RefusedException::class, $e);
			}
		}
		$this->assertSame([], $this->budgets->list(self::OWNER, $vehicle->getUuid()));
	}

	/** A manager's rights end at the vehicle, and the budget is the owner's plan. */
	public function testOnlyTheOwnerSetsABudget(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::MANAGER, 'manager');

		$this->expectException(AccessDeniedException::class);
		$this->budgets->create(self::MANAGER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);
	}

	/** Whoever sees the vehicle's costs sees the budget. */
	public function testAManagerReadsTheBudgetAndADriverDoesNot(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::MANAGER, 'manager');
		$this->grant($vehicle, self::DRIVER, 'driver');
		$made = $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$this->assertSame([$made], $this->budgets->list(self::MANAGER, $vehicle->getUuid()));
		$this->assertSame([], $this->budgets->list(self::DRIVER, $vehicle->getUuid()));
	}

	public function testTheOwnerChangesTheAmountAndWhetherItIsNet(): void {
		$vehicle = $this->vehicle();
		$made = $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$changed = $this->budgets->update(self::OWNER, $vehicle->getUuid(), $made['uuid'], $made['updated_at'], ['amount' => 600000, 'net' => true]);

		$this->assertSame([2031, 600000, true], [$changed['year'], $changed['amount'], $changed['net']]);
		$this->assertSame([$changed], $this->budgets->list(self::OWNER, $vehicle->getUuid()));
		$this->expectException(StaleUpdateException::class);
		$this->budgets->update(self::OWNER, $vehicle->getUuid(), $made['uuid'], $made['updated_at'], ['amount' => 700000, 'net' => true]);
	}

	public function testAManagerChangesNoBudget(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::MANAGER, 'manager');
		$made = $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$this->expectException(AccessDeniedException::class);
		$this->budgets->update(self::MANAGER, $vehicle->getUuid(), $made['uuid'], $made['updated_at'], ['amount' => 900000, 'net' => false]);
	}

	public function testTheOwnerDeletesABudgetAndTheYearIsFreeAgain(): void {
		$vehicle = $this->vehicle();
		$made = $this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$gone = $this->budgets->delete(self::OWNER, $vehicle->getUuid(), $made['uuid'], $made['updated_at']);

		$this->assertSame($made['uuid'], $gone['uuid']);
		$this->assertNotNull($gone['deleted_at']);
		$this->assertSame([], $this->budgets->list(self::OWNER, $vehicle->getUuid()));
		$this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 400000, 'net' => false]);
	}

	/** Its amount is in the vehicle's currency, so the currency stays while the budget does. */
	public function testABudgetHoldsTheVehiclesCurrency(): void {
		$vehicle = $this->vehicle();
		$this->budgets->create(self::OWNER, $vehicle->getUuid(), ['year' => 2031, 'amount' => 500000, 'net' => false]);

		$this->expectException(CurrencyInUseException::class);
		Server::get(VehicleService::class)->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), ['plate' => 'NF-BG 1', 'currency' => 'CHF']);
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(array $fields = []): Vehicle {
		return Server::get(VehicleService::class)->create(self::OWNER, $fields + ['plate' => 'NF-BG 1', 'currency' => 'EUR', 'costs_shared' => false]);
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => 'user', 'role' => $role]);
	}

	/** @param callable(): mixed $call */
	private function assertRefused(string $reason, callable $call): void {
		try {
			$call();
			$this->fail('not refused');
		} catch (RefusedException $e) {
			$this->assertSame($reason, $e->reason);
		}
	}
}
