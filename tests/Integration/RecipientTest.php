<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Who a vehicle's reminders go to, against the real database (docs/architecture.md#reminder-engine).
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class RecipientTest extends TestCase {
	use Accounts;

	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const MANAGER = 'nextfleet-test-dave';
	private const DRIVER = 'nextfleet-test-erin';
	/** A real account, which a recipient added through the sheet has to be. */
	private const RECIPIENT = 'nextfleet-test-recipient';

	private RecipientService $recipients;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::RECIPIENT]);
		\OCP\Server::get(IUserManager::class)->createUser(self::RECIPIENT, bin2hex(random_bytes(16)));
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::RECIPIENT]);
	}

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->recipients = $container->get(RecipientService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::MANAGER, self::DRIVER];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee', 'fleet_reminder_recipients' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function testANewVehicleStartsWithItsOwnerAsTheOneRecipient(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->assertSame([self::OWNER], $this->userIds($vehicle));
	}

	/** A manager edits the list as the owner does, and the owner is not a fixed member of it. */
	public function testAManagerAddsSomebodyAndTakesTheOwnerOff(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant($vehicle, self::MANAGER, 'manager');

		$added = $this->recipients->add(self::MANAGER, $vehicle->getUuid(), self::RECIPIENT);
		$left = $this->recipients->remove(self::MANAGER, $vehicle->getUuid(), self::OWNER);

		$this->assertSame([self::OWNER, self::RECIPIENT], array_column($added, 'user_id'));
		$this->assertSame([self::RECIPIENT], array_column($left, 'user_id'));
		$this->assertSame([self::RECIPIENT], $this->userIds($vehicle));
	}

	/**
	 * Removing is for good, so the same person can be added again: the unique index would refuse a
	 * second row beside a soft-deleted one.
	 */
	public function testSomebodyRemovedCanBeAddedAgainAndAddingTwiceIsOnce(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->remove(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);

		$this->assertSame([self::OWNER, self::RECIPIENT], $this->userIds($vehicle));
	}

	/** The user backend finds an account in any case, so the list must hold its one spelling. */
	public function testAnAccountIsStoredAsTheInstanceSpellsIt(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->recipients->add(self::OWNER, $vehicle->getUuid(), self::RECIPIENT);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), strtoupper(self::RECIPIENT));

		$this->assertSame([self::OWNER, self::RECIPIENT], $this->userIds($vehicle));
	}

	/** A name the instance does not know would be a recipient nothing can ever reach. */
	public function testSomebodyWithoutAnAccountIsRefused(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);

		$this->expectException(\InvalidArgumentException::class);
		$this->recipients->add(self::OWNER, $vehicle->getUuid(), 'nextfleet-test-nobody');
	}

	/** Drivers and viewers neither read nor write the list (docs/architecture.md#reminder-engine). */
	public function testADriverNeitherReadsNorWritesTheList(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123']);
		$this->grant($vehicle, self::DRIVER, 'driver');

		foreach ([
			fn () => $this->recipients->list(self::DRIVER, $vehicle->getUuid()),
			fn () => $this->recipients->add(self::DRIVER, $vehicle->getUuid(), self::RECIPIENT),
			fn () => $this->recipients->remove(self::DRIVER, $vehicle->getUuid(), self::OWNER),
		] as $call) {
			try {
				$call();
				$this->fail('A driver reached the recipients list');
			} catch (AccessDeniedException) {
			}
		}
		$this->assertSame([self::OWNER], $this->userIds($vehicle));
	}

	/** @return list<string> */
	private function userIds(Vehicle $vehicle, string $asking = self::OWNER): array {
		return array_column($this->recipients->list($asking, $vehicle->getUuid()), 'user_id');
	}

	/** One grant on the vehicle, as the sharing UI will write it (M6). */
	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
