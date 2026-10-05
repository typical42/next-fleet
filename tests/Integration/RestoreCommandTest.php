<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\RestoreCommand;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\ErasureService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:restore`: the owner's undo of a delete, run by an admin.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class RestoreCommandTest extends TestCase {
	/** Not an account: a vehicle's `user_id` is a string column. */
	private const OWNER = 'nextfleet-test-restore-owner';

	private VehicleService $vehicles;
	private VehicleMapper $mapper;
	private CommandTester $command;
	/** @var list<int> by id, since one test renames the owner */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->mapper = \OCP\Server::get(VehicleMapper::class);
		$this->command = new CommandTester(\OCP\Server::get(RestoreCommand::class));
	}

	protected function tearDown(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach ($this->vehicleIds as $id) {
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $qb::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/** The trail gains what the app's undo leaves on a vehicle: nothing, as a delete leaves nothing. */
	public function testADeletedVehicleComesBackAsTheOwnersUndoBringsIt(): void {
		$vehicle = $this->deleted();
		$deletedAt = $this->fresh($vehicle)->getUpdatedAt();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid()]), $this->command->getDisplay());

		$fresh = $this->fresh($vehicle);
		$this->assertNull($fresh->getDeletedAt());
		$this->assertGreaterThan($deletedAt, $fresh->getUpdatedAt());
		$this->assertSame(self::OWNER, $fresh->getUserId());
		$this->assertStringContainsString($vehicle->getUuid(), $this->command->getDisplay());
		$this->assertSame([], \OCP\Server::get(AuditMapper::class)->findForEntity(Audit::VEHICLE, (int)$vehicle->getId()));
	}

	public function testALiveVehicleIsRefusedAndLeftAsItIs(): void {
		$vehicle = $this->vehicle();
		$before = $this->fresh($vehicle)->getUpdatedAt();

		$this->assertSame(1, $this->command->execute(['vehicle' => $vehicle->getUuid()], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('not deleted', $this->command->getErrorOutput());
		$this->assertSame($before, $this->fresh($vehicle)->getUpdatedAt());
	}

	/** An erasure closed it (ErasureService::erase()): nobody is left who may restore it, an admin included. */
	public function testAVehicleItsOwnersUndoWouldRefuseIsRefused(): void {
		$vehicle = $this->deleted();
		$gone = ErasureService::PREFIX . str_repeat('r3st0', 4);
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_vehicles')->set('user_id', $qb->createNamedParameter($gone))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));
		$qb->executeStatement();

		$this->assertSame(1, $this->command->execute(['vehicle' => $vehicle->getUuid()], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString($gone, $this->command->getErrorOutput());
		$this->assertNotNull($this->fresh($vehicle)->getDeletedAt());
	}

	public function testAnUnknownVehicleIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['vehicle' => 'no-such-uuid'], ['capture_stderr_separately' => true]));

		$this->assertStringContainsString('no-such-uuid', $this->command->getErrorOutput());
	}

	private function vehicle(): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-RS 1']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function deleted(): Vehicle {
		$vehicle = $this->vehicle();
		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		return $vehicle;
	}

	private function fresh(Vehicle $vehicle): Vehicle {
		return $this->mapper->findAnyById((int)$vehicle->getId());
	}
}
