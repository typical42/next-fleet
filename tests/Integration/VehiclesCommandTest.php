<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\VehiclesCommand;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:vehicles`: the uuids every other admin command takes, which no screen of
 * `occ` shows otherwise.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class VehiclesCommandTest extends TestCase {
	private const ANNA = 'nextfleet-test-vehicles-anna';
	private const BEN = 'nextfleet-test-vehicles-ben';

	private VehicleService $vehicles;
	private CommandTester $command;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->command = new CommandTester(\OCP\Server::get(VehiclesCommand::class));
		$this->forget();

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::ANNA, self::BEN] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Rows first: deleting an account closes the vehicles it owns. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (['fleet_access', 'fleet_reminder_recipients', 'fleet_odo_readings'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_audit')
				->where($qb->expr()->eq('entity', $qb->createNamedParameter(Audit::VEHICLE)))
				->andWhere($qb->expr()->in('entity_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];

		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::ANNA, self::BEN] as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/** The instance may hold other vehicles, so only ours are compared. */
	public function testItListsEveryLiveVehicleByOwnerThenPlate(): void {
		$second = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 2', 'manufacturer' => 'Škoda', 'model' => 'Octavia']);
		$first = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 1']);
		$bens = $this->vehicle(self::BEN, ['plate' => 'NF-VL 0']);

		$this->assertSame(0, $this->command->execute(['--output' => 'json']), $this->command->getDisplay());

		$this->assertSame([
			$this->row($first, self::ANNA, 'NF-VL 1', ''),
			$this->row($second, self::ANNA, 'NF-VL 2', 'Škoda Octavia'),
			$this->row($bens, self::BEN, 'NF-VL 0', ''),
		], $this->ours($this->listed()));
	}

	public function testUserNarrowsToTheVehiclesThatAccountOwns(): void {
		$annas = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 1']);
		$this->vehicle(self::BEN, ['plate' => 'NF-VL 0']);

		$this->command->execute(['--user' => self::ANNA, '--output' => 'json']);

		$this->assertSame([$annas->getUuid()], array_column($this->listed(), 'uuid'));
	}

	/** The trash is apart: a live list without it, and with --deleted nothing else, when it went in. */
	public function testDeletedListsTheTrashAlone(): void {
		$live = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 1']);
		$gone = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 2']);
		$gone = $this->vehicles->delete(self::ANNA, $gone->getUuid(), $gone->getUpdatedAt());

		$this->command->execute(['--user' => self::ANNA, '--output' => 'json']);
		$this->assertSame([$live->getUuid()], array_column($this->listed(), 'uuid'));

		$this->command->execute(['--user' => self::ANNA, '--deleted' => true, '--output' => 'json']);
		$listed = $this->listed();
		$this->assertSame([$gone->getUuid()], array_column($listed, 'uuid'));
		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', (int)$gone->getDeletedAt()), $listed[0]['deleted_at']);
	}

	public function testPlainIsATableAnAdminCanCopyAUuidFrom(): void {
		$vehicle = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 1']);

		$this->assertSame(0, $this->command->execute(['--user' => self::ANNA]));

		$display = $this->command->getDisplay();
		$this->assertMatchesRegularExpression('/\|\s*uuid\s*\|\s*plate\s*\|\s*name\s*\|\s*owner\s*\|\s*lifecycle\s*\|\s*deleted_at\s*\|/', $display);
		$this->assertStringContainsString($vehicle->getUuid(), $display);
	}

	/** The owner column holds the uid as stored, and the database compares it exactly. */
	public function testAUidTypedInAnotherCaseListsTheAccountsVehicles(): void {
		$vehicle = $this->vehicle(self::ANNA, ['plate' => 'NF-VL 1']);

		$this->command->execute(['--user' => strtoupper(self::ANNA), '--output' => 'json']);

		$this->assertSame([$vehicle->getUuid()], array_column($this->listed(), 'uuid'));
	}

	public function testAnAccountWithNoVehicleIsNothingFound(): void {
		$this->assertSame(0, $this->command->execute(['--user' => self::BEN]));

		$this->assertSame("No vehicles.\n", $this->command->getDisplay());
	}

	public function testAnUnknownOutputIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['--output' => 'xml']));
	}

	/** @param array<string, mixed> $fields */
	private function vehicle(string $owner, array $fields): Vehicle {
		$vehicle = $this->vehicles->create($owner, $fields);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	/** @return array<string, string|null> */
	private function row(Vehicle $vehicle, string $owner, string $plate, string $name): array {
		return ['uuid' => $vehicle->getUuid(), 'plate' => $plate, 'name' => $name, 'owner' => $owner, 'lifecycle' => Vehicle::ACTIVE, 'deleted_at' => null];
	}

	/** @return list<array<string, string|null>> */
	private function listed(): array {
		/** @var list<array<string, string|null>> */
		return json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param list<array<string, string|null>> $rows
	 * @return list<array<string, string|null>>
	 */
	private function ours(array $rows): array {
		return array_values(array_filter($rows, static fn (array $row): bool => in_array($row['owner'], [self::ANNA, self::BEN], true)));
	}
}
