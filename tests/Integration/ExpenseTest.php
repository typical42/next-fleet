<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * An Expense against the real database: the row, and the VAT the sheet is prefilled with. It knows
 * no counter, so it never touches the odometer.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ExpenseTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';

	private ExpenseService $expenses;
	private OdometerService $odometer;
	private VehicleService $vehicles;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->expenses = $container->get(ExpenseService::class);
		$this->odometer = $container->get(OdometerService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_odo_readings' => 'created_by', 'fleet_expenses' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	/** Every field the sheet offers is stored as given. */
	public function testTheFieldsAreStoredAsGiven(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->expenses->record(self::OWNER, $vehicle->getUuid(), $this->spend([
			'category' => 'insurance',
			'vat_rate' => 1900,
			'notes' => 'HUK, full year',
		]));

		$this->assertSame(1750000000, $written['spent_at']);
		$this->assertSame(120, $written['spent_at_off']);
		$this->assertSame(64000, $written['amount']);
		$this->assertSame('insurance', $written['category']);
		$this->assertSame(1900, $written['vat_rate']);
		$this->assertSame('HUK, full year', $written['notes']);
		$this->assertNotEmpty($written['uuid']);
	}

	/** An Expense knows no counter: one sent along is not a Reading, and the odometer stays put. */
	public function testAnExpenseWritesNoReading(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->expenses->record(self::OWNER, $vehicle->getUuid(), $this->spend(['odo' => 120450]));

		$this->assertArrayNotHasKey('odo', $written);
		$this->assertSame([], $this->odometer->list(self::OWNER, $vehicle->getUuid()));
		$this->assertNull($this->vehicles->find(self::OWNER, $vehicle->getUuid())->getOdoValue());
	}

	/**
	 * The amount is the one field the sheet requires; category, VAT and notes may be left out, and
	 * a missing VAT is "not stated", never zero.
	 */
	public function testOnlyTheAmountIsRequired(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$written = $this->expenses->record(self::OWNER, $vehicle->getUuid(), $this->spend());

		$this->assertNull($written['category']);
		$this->assertNull($written['vat_rate']);
		$this->assertNull($written['notes']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expenses->record(self::OWNER, $vehicle->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 120]);
	}

	/** A category outside the seven is a client that did not use the sheet's list. */
	public function testAnUnknownCategoryIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expenses->record(self::OWNER, $vehicle->getUuid(), $this->spend(['category' => 'fuel']));
	}

	/** The VAT is the jurisdiction's rate on the day of the expense, as for a fill-up. */
	public function testThePrefillStatesTheJurisdictionsRateOnTheDay(): void {
		$german = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de']);
		$generic = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124', 'jurisdiction' => 'generic']);

		$this->assertSame(['vat_rate' => 1600], $this->expenses->prefill(self::OWNER, $german->getUuid(), ['at' => 1609453800, 'off' => 60]));
		$this->assertSame(['vat_rate' => null], $this->expenses->prefill(self::OWNER, $generic->getUuid(), ['at' => 1750000000, 'off' => 120]));
	}

	/** A moment the prefill cannot read is a client that did not send one. */
	public function testThePrefillWantsAMoment(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expenses->prefill(self::OWNER, $vehicle->getUuid(), ['at' => 1750000000]);
	}

	/** An edit rewrites the Expense in place; a field it leaves out is one the person emptied. */
	public function testAnEditRewritesTheExpense(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->expenses->record(self::OWNER, $uuid, $this->spend(['category' => 'toll', 'notes' => 'A7']));

		$edited = $this->expenses->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->spend(['amount' => 1250]));

		$this->assertSame($written['uuid'], $edited['uuid']);
		$this->assertSame(1250, $edited['amount']);
		$this->assertNull($edited['category']);
		$this->assertNull($edited['notes']);
		$this->assertGreaterThan($written['updated_at'], $edited['updated_at']);
	}

	/** An edit on a token somebody else has moved on from is refused. */
	public function testAnEditThatLostTheRaceIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->expenses->record(self::OWNER, $uuid, $this->spend());
		$this->expenses->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->spend(['amount' => 1250]));

		$this->expectException(StaleUpdateException::class);
		$this->expenses->update(self::OWNER, $uuid, $written['uuid'], $written['updated_at'], $this->spend(['amount' => 9999]));
	}

	/** A delete soft-deletes, and undo on the token the delete answered with brings it back. */
	public function testDeleteAndUndo(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();
		$written = $this->expenses->record(self::OWNER, $uuid, $this->spend());

		$deleted = $this->expenses->delete(self::OWNER, $uuid, $written['uuid'], $written['updated_at']);
		$this->assertNotNull($deleted['deleted_at']);

		$back = $this->expenses->restore(self::OWNER, $uuid, $written['uuid'], $deleted['updated_at']);
		$this->assertNull($back['deleted_at']);
		$this->assertSame(64000, $back['amount']);
	}

	/** Another vehicle's Expense is not reached through this one, even by its owner. */
	public function testAnExpenseIsReachedOnlyThroughItsOwnVehicle(): void {
		$mine = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$other = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 124']);
		$written = $this->expenses->record(self::OWNER, $other->getUuid(), $this->spend());

		$this->expectException(DoesNotExistException::class);
		$this->expenses->update(self::OWNER, $mine->getUuid(), $written['uuid'], $written['updated_at'], $this->spend());
	}

	/**
	 * An Expense as the sheet sends it, with what a test does not care about filled in.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function spend(array $fields = []): array {
		return $fields + [
			'spent_at' => 1750000000,
			'spent_at_off' => 120,
			'amount' => 64000,
		];
	}
}
