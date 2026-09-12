<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\VehicleController;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle against the real database: what the columns give back, what the concurrency check
 * does when a second tab got there first, and what a soft delete leaves behind.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class VehicleTest extends TestCase {
	/** Not a Nextcloud account: `user_id` is a string column with no key on it. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';

	private VehicleService $service;
	private AuditMapper $audit;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->service = $container->get(VehicleService::class);
		$this->audit = $container->get(AuditMapper::class);
		$this->forgetTestVehicles();
	}

	protected function tearDown(): void {
		$this->forgetTestVehicles();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestVehicles(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::STRANGER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_audit' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Every kind of column the table has, written and read back: a JSON set, a calendar day, a
	 * boolean nobody touched, integers, and the identity the server chose.
	 */
	public function testAVehicleComesBackAsItWasWritten(): void {
		$written = $this->service->create(self::OWNER, [
			'plate' => 'B-XY 123',
			'manufacturer' => 'Volkswagen',
			'model' => 'Caddy',
			'vehicle_type' => 'van',
			'engine' => 'hybrid',
			'energy_types' => ['petrol', 'electric'],
			'tank_ml' => '55000',
			'battery_wh' => '13600',
			'first_reg' => '2019-03-07',
			'purchase_price' => '1850000',
			'currency' => 'EUR',
			'odo_unit' => 'km',
			'notes' => "two rows of seats\nand a dent",
		]);

		$read = $this->service->find(self::OWNER, $written->getUuid());

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$read->getUuid(),
		);
		$this->assertSame(self::OWNER, $read->getUserId());
		$this->assertSame(self::OWNER, $read->getCreatedBy());
		$this->assertSame('B-XY 123', $read->getPlate());
		$this->assertSame('van', $read->getVehicleType());
		$this->assertSame(['petrol', 'electric'], $read->getEnergyTypes());
		$this->assertSame(55000, $read->getTankMl());
		$this->assertSame(1850000, $read->getPurchasePrice());
		$this->assertSame('2019-03-07', $read->getFirstReg()?->format('Y-m-d'));
		$this->assertSame("two rows of seats\nand a dent", $read->getNotes());
		// Nobody set it, and the column's default is what a flag nobody touched means.
		$this->assertFalse((bool)$read->getLogbookMode());
		$this->assertNull($read->getOdoValue());
		$this->assertSame($read->getCreatedAt(), $read->getUpdatedAt());
	}

	/**
	 * The edit sheet's payload, as it sends it: every writable column at once, the numbers as the
	 * strings a text field holds, and an emptied field as the empty string. It is the only write
	 * that reaches `lifecycle`, `disposed_at` and the vehicle's country, so this is where those
	 * three are proved against the real columns.
	 */
	public function testTheEditSheetsPayloadReachesEveryColumn(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123', 'jurisdiction' => 'de']);

		$this->service->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), [
			'plate' => 'B-XY 999',
			'manufacturer' => 'Volkswagen',
			'model' => 'Caddy',
			'vehicle_type' => 'van',
			'engine' => 'hybrid',
			'energy_types' => ['petrol', 'electric'],
			'tank_ml' => '55000',
			'battery_wh' => '13600',
			'first_reg' => '2019-03-07',
			'disposed_at' => '2025-06-30',
			'vin' => 'WVWZZZ1KZAW000001',
			'odo_unit' => 'h',
			'purchase_price' => '1850000',
			'residual_est' => '400000',
			// Emptied on screen, so it travels empty and the column is cleared - a diff would
			// leave the euros of the country this vehicle has just left.
			'currency' => '',
			'jurisdiction' => 'generic',
			'lifecycle' => 'disposed',
			'retention_months' => '120',
			'color' => 'blue',
			'notes' => "two rows of seats\nand a dent",
		]);

		$read = $this->service->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame('B-XY 999', $read->getPlate());
		$this->assertSame('van', $read->getVehicleType());
		$this->assertSame(['petrol', 'electric'], $read->getEnergyTypes());
		$this->assertSame(13600, $read->getBatteryWh());
		$this->assertSame('2019-03-07', $read->getFirstReg()?->format('Y-m-d'));
		$this->assertSame('h', $read->getOdoUnit());
		$this->assertSame(400000, $read->getResidualEst());
		$this->assertNull($read->getCurrency());
		$this->assertSame('generic', $read->getJurisdiction());
		$this->assertSame('disposed', $read->getLifecycle());
		$this->assertSame('2025-06-30', $read->getDisposedAt()?->format('Y-m-d'));
		$this->assertSame("two rows of seats\nand a dent", $read->getNotes());
	}

	/**
	 * Back in service, and the day it was sold on goes with the lifecycle it belonged to: the
	 * sheet sends the disposal day empty, and empty clears the column rather than being ignored.
	 */
	public function testAVehicleBackInServiceLosesItsDisposalDay(): void {
		$vehicle = $this->service->create(self::OWNER, ['lifecycle' => 'disposed', 'disposed_at' => '2025-06-30']);
		$this->assertSame('2025-06-30', $vehicle->getDisposedAt()?->format('Y-m-d'), 'nothing to clear');

		$this->service->update(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt(), [
			'lifecycle' => 'active',
			'disposed_at' => '',
		]);

		$read = $this->service->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame('active', $read->getLifecycle());
		$this->assertNull($read->getDisposedAt());
	}

	/**
	 * The mode switched on and off again, as the trail kept it. Only the instance says that the
	 * two rows committed alongside the writes they describe, that `Types::JSON` gave the nested
	 * pair back as it went in, and that the order they come back in is the order they happened.
	 */
	public function testEveryFlipOfTheModeIsInTheTrailOfTheVehicle(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$uuid = $vehicle->getUuid();

		$on = $this->service->update(self::OWNER, $uuid, $vehicle->getUpdatedAt(), ['logbook_mode' => true]);
		$this->assertTrue($this->service->find(self::OWNER, $uuid)->getLogbookMode());
		// A save that says nothing about the mode sits between the two flips, so what comes back
		// is the flips and not the saves.
		$saved = $this->service->update(self::OWNER, $uuid, $on->getUpdatedAt(), ['plate' => 'B-ZZ 9']);
		$this->service->update(self::OWNER, $uuid, $saved->getUpdatedAt(), ['logbook_mode' => false]);

		$trail = $this->audit->findForEntity(Audit::VEHICLE, (int)$vehicle->getId());
		$this->assertSame(
			[
				['change' => 'switched', 'fields' => ['logbook_mode' => [false, true]]],
				['change' => 'switched', 'fields' => ['logbook_mode' => [true, false]]],
			],
			array_map(static fn (Audit $row): array => $row->getDiffJson(), $trail),
		);
		$this->assertSame([self::OWNER, self::OWNER], array_map(
			static fn (Audit $row): string => $row->getCreatedBy(),
			$trail,
		));
		$this->assertFalse($this->service->find(self::OWNER, $uuid)->getLogbookMode());
	}

	/**
	 * A refused write leaves no trail. The row and the audit row are one write, so the flip that
	 * lost the race did not happen and nothing may say it did.
	 */
	public function testAFlipThatLostTheRaceIsNotInTheTrail(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$stale = $vehicle->getUpdatedAt();
		$this->service->update(self::OWNER, $vehicle->getUuid(), $stale, ['plate' => 'B-ZZ 9']);

		try {
			$this->service->update(self::OWNER, $vehicle->getUuid(), $stale, ['logbook_mode' => true]);
			$this->fail('the stale flip was written');
		} catch (StaleUpdateException) {
		}

		$this->assertSame([], $this->audit->findForEntity(Audit::VEHICLE, (int)$vehicle->getId()));
		// Cast, because a column nobody has written is null rather than false - which is the same
		// answer to "is this vehicle under the mode" (docs/architecture.md#data-model).
		$this->assertFalse((bool)$this->service->find(self::OWNER, $vehicle->getUuid())->getLogbookMode());
	}

	/**
	 * What a new vehicle counts and prices in is its country's answer, asked through the
	 * container that built this service (lib/Jurisdiction/Jurisdictions.php) - and the columns
	 * really take it: euros under Germany, no currency at all under the generic profile, where
	 * the vehicle states its own.
	 */
	public function testANewVehicleTakesItsUnitsAndCurrencyFromItsJurisdiction(): void {
		$german = $this->service->create(self::OWNER, ['jurisdiction' => 'de']);
		$generic = $this->service->create(self::OWNER, ['jurisdiction' => 'generic']);

		$readGerman = $this->service->find(self::OWNER, $german->getUuid());
		$readGeneric = $this->service->find(self::OWNER, $generic->getUuid());

		$this->assertSame('km', $readGerman->getOdoUnit());
		$this->assertSame('EUR', $readGerman->getCurrency());
		$this->assertSame('km', $readGeneric->getOdoUnit());
		$this->assertNull($readGeneric->getCurrency());
	}

	/**
	 * A country this release has never heard of is still written to the row, and the generic
	 * profile answers for it - so a vehicle whose jurisdiction left a later release opens
	 * instead of throwing.
	 */
	public function testAVehicleUnderAnUnknownJurisdictionIsStillWritten(): void {
		$written = $this->service->create(self::OWNER, ['jurisdiction' => 'zz']);

		$read = $this->service->find(self::OWNER, $written->getUuid());

		$this->assertSame('zz', $read->getJurisdiction());
		$this->assertSame('km', $read->getOdoUnit());
		$this->assertNull($read->getCurrency());
	}

	/** The overview is one user's fleet, and a row belonging to someone else is not in it. */
	public function testTheListHoldsOneUsersVehicles(): void {
		$mine = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->service->create(self::STRANGER, ['plate' => 'HH-ZZ 9']);

		$this->assertSame(
			[$mine->getUuid()],
			array_map(static fn (Vehicle $v): string => $v->getUuid(), $this->service->list(self::OWNER)),
		);
	}

	/**
	 * The second tab read the row before the first one saved it, so its write matches nothing
	 * and is refused rather than quietly winning (docs/architecture.md#concurrency).
	 */
	public function testAWriteThatLostTheRaceIsRefused(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$stale = $vehicle->getUpdatedAt();

		$fresh = $this->service->update(self::OWNER, $vehicle->getUuid(), $stale, ['plate' => 'B-ZZ 9']);
		$this->assertSame('B-ZZ 9', $this->service->find(self::OWNER, $vehicle->getUuid())->getPlate());
		$this->assertGreaterThan($stale, $fresh->getUpdatedAt());

		$this->expectException(StaleUpdateException::class);
		$this->service->update(self::OWNER, $vehicle->getUuid(), $stale, ['plate' => 'B-AA 1']);
	}

	/**
	 * The same race as above, through the controller a route reaches: the loser gets a 412 whose
	 * body names the conflict, and the winner's plate is still on the row.
	 */
	public function testAWriteThatLostTheRaceIsAPreconditionFailure(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$stale = $vehicle->getUpdatedAt();
		$this->service->update(self::OWNER, $vehicle->getUuid(), $stale, ['plate' => 'B-ZZ 9']);

		$response = $this->controller(self::OWNER, ['updated_at' => $stale, 'plate' => 'B-AA 1'])
			->update($vehicle->getUuid());

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertTrue($response->getData()['conflict']);
		$this->assertSame('B-ZZ 9', $this->service->find(self::OWNER, $vehicle->getUuid())->getPlate());
	}

	/**
	 * The controller as a route reaches it: the real service, and a session that is whoever is
	 * asking.
	 *
	 * @param array<string, mixed> $params
	 */
	private function controller(string $userId, array $params): VehicleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new VehicleController(Application::APP_ID, $request, $this->service, $session);
	}

	/** A deleted vehicle is out of reach of a read, and the row is still there for the trash. */
	public function testADeletedVehicleIsOutOfReachButNotGone(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);

		$deleted = $this->service->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$this->assertNotNull($deleted->getDeletedAt());
		$this->assertSame([], $this->service->list(self::OWNER));

		$this->expectException(DoesNotExistException::class);
		$this->service->find(self::OWNER, $vehicle->getUuid());
	}

	/**
	 * The undo toast, end to end: the token the delete answered with brings the row back, and the
	 * vehicle is in the overview again with nothing about it changed.
	 */
	public function testADeletedVehicleComesBackWithTheTokenTheDeleteAnsweredWith(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$deleted = $this->service->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$restored = $this->service->restore(self::OWNER, $vehicle->getUuid(), $deleted->getUpdatedAt());

		$this->assertNull($restored->getDeletedAt());
		$read = $this->service->find(self::OWNER, $vehicle->getUuid());
		$this->assertSame('B-XY 123', $read->getPlate());
		$this->assertNull($read->getDeletedAt());
		$this->assertSame(
			[$vehicle->getUuid()],
			array_map(static fn (Vehicle $v): string => $v->getUuid(), $this->service->list(self::OWNER)),
		);
	}

	/**
	 * The token the vehicle carried before the delete is not the one the delete left behind, so an
	 * undo wearing it is refused - and through the controller that is the same 412 a stale update
	 * gets, named by the body rather than by the status.
	 */
	public function testARestoreCarryingAStaleTokenIsRefused(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);
		$stale = $vehicle->getUpdatedAt();
		$this->service->delete(self::OWNER, $vehicle->getUuid(), $stale);

		$response = $this->controller(self::OWNER, ['updated_at' => $stale])->restore($vehicle->getUuid());

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertTrue($response->getData()['conflict']);
		$this->expectException(DoesNotExistException::class);
		$this->service->find(self::OWNER, $vehicle->getUuid());
	}

	/**
	 * Undoing a delete nobody did is the same answer: the statement wants a stamped row, and a
	 * live one is not what the client read. Two tabs racing on one undo toast is the real case -
	 * the second one must not silently succeed.
	 */
	public function testRestoringAVehicleThatIsNotDeletedIsRefused(): void {
		$vehicle = $this->service->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(StaleUpdateException::class);
		$this->service->restore(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to
	 * build the whole chain from constructor types alone - and a route that cannot be built is
	 * a 500 no unit test sees.
	 */
	public function testTheControllerIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			VehicleController::class,
			(new Application())->getContainer()->get(VehicleController::class),
		);
	}
}
