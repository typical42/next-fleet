<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\AccessCommand;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:access`: who may use one vehicle, as an admin reads it.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class AccessCommandTest extends TestCase {
	/** Not an account: a vehicle's `user_id` is a string column. */
	private const OWNER = 'nextfleet-test-access-owner';
	/** An account and a group, since a grantee has to exist to be granted. */
	private const ANNA = 'nextfleet-test-access-anna';
	private const CREW = 'nextfleet-test-access-crew';

	private VehicleService $vehicles;
	private GrantService $grants;
	private CommandTester $command;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		\OCP\Server::get(IUserManager::class)->createUser(self::ANNA, bin2hex(random_bytes(16)));
		\OCP\Server::get(IGroupManager::class)->createGroup(self::CREW);
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		\OCP\Server::get(IUserManager::class)->get(self::ANNA)?->delete();
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
	}

	protected function setUp(): void {
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->grants = \OCP\Server::get(GrantService::class);
		$this->command = new CommandTester(\OCP\Server::get(AccessCommand::class));
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** Every row this suite writes is the owner's. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (['fleet_access', 'fleet_reminder_recipients', 'fleet_odo_readings', 'fleet_audit'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER, IQueryBuilder::PARAM_STR)));
			$qb->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
	}

	/** The owner first, holding no grant, then each grant in the order it was given. */
	public function testItListsTheOwnerThenEachLiveGrant(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::ANNA, 'user', 'driver');
		$this->grant($vehicle, self::CREW, 'group', 'viewer');
		[$anna, $annaAt] = $this->given($vehicle, self::ANNA);
		[$crew, $crewAt] = $this->given($vehicle, self::CREW);

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']), $this->command->getDisplay());

		$listed = $this->listed();
		$this->assertSame([
			[null, self::OWNER, 'user', 'owner', null, null],
			[$anna, self::ANNA, 'user', 'driver', $annaAt, null],
			[$crew, self::CREW, 'group', 'viewer', $crewAt, null],
		], array_map(array_values(...), $listed));
		$this->assertSame(['uuid', 'grantee', 'type', 'role', 'granted_at', 'deleted_at'], array_keys($listed[0]));
	}

	/** A revoke leaves its row (GrantService::revoke()); only --all shows it, with when it went. */
	public function testAllAddsTheRevokedGrantsWithWhenTheyWent(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::ANNA, 'user', 'driver');
		[$anna, $annaAt] = $this->given($vehicle, self::ANNA);
		$this->grants->revoke(self::OWNER, $vehicle->getUuid(), $anna);
		$this->grant($vehicle, self::ANNA, 'user', 'viewer');
		[$again] = $this->given($vehicle, self::ANNA);

		$this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']);
		$this->assertSame([null, $again], array_column($this->listed(), 'uuid'));

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--all' => true, '--output' => 'json']));
		$listed = $this->listed();
		$this->assertSame([null, $anna, $again], array_column($listed, 'uuid'));
		$this->assertSame(['driver', $annaAt], [$listed[1]['role'], $listed[1]['granted_at']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)$listed[1]['deleted_at']);
		$this->assertGreaterThanOrEqual($annaAt, $listed[1]['deleted_at']);
		$this->assertNull($listed[2]['deleted_at']);
	}

	/** A vehicle in the trash keeps its grants. */
	public function testAVehicleInTheTrashStillAnswers(): void {
		$vehicle = $this->vehicle();
		$this->grant($vehicle, self::ANNA, 'user', 'driver');
		[$anna] = $this->given($vehicle, self::ANNA);
		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), \OCP\Server::get(VehicleMapper::class)->findByUuid($vehicle->getUuid())->getUpdatedAt());

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid(), '--output' => 'json']));

		$this->assertSame([null, $anna], array_column($this->listed(), 'uuid'));
	}

	/** On stderr, so stdout stays one JSON document or nothing. */
	public function testAnUnknownVehicleIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['vehicle' => 'no-such-uuid', '--output' => 'json'], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('no-such-uuid', $this->command->getErrorOutput());
	}

	public function testAnUnknownOutputIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['vehicle' => $this->vehicle()->getUuid(), '--output' => 'xml']));
	}

	public function testPlainIsATable(): void {
		$vehicle = $this->vehicle();

		$this->assertSame(0, $this->command->execute(['vehicle' => $vehicle->getUuid()]));

		$display = $this->command->getDisplay();
		$this->assertMatchesRegularExpression('/\|\s*uuid\s*\|\s*grantee\s*\|\s*type\s*\|\s*role\s*\|\s*granted_at\s*\|\s*deleted_at\s*\|/', $display);
		$this->assertMatchesRegularExpression('/\|\s*\|\s*' . self::OWNER . '\s*\|\s*user\s*\|\s*owner\s*\|/', $display);
	}

	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'NF-AC 1']);
	}

	private function grant(Vehicle $vehicle, string $grantee, string $type, string $role): void {
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => $type, 'role' => $role]);
	}

	/**
	 * The live grant to this grantee as the app reads it, for what only the row knows: its uuid
	 * and when it was given.
	 *
	 * @return array{string, string} uuid and granted at
	 */
	private function given(Vehicle $vehicle, string $grantee): array {
		foreach (\OCP\Server::get(AccessMapper::class)->findByVehicle((int)$vehicle->getId()) as $grant) {
			if ($grant->getGrantee() === $grantee) {
				return [$grant->getUuid(), gmdate('Y-m-d\TH:i:s\Z', $grant->getCreatedAt())];
			}
		}

		throw new \RuntimeException('no live grant to ' . $grantee);
	}

	/** @return list<array<string, string|null>> */
	private function listed(): array {
		/** @var list<array<string, string|null>> */
		return json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
	}
}
