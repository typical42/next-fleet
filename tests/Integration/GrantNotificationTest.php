<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\GrantNotices;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;

/**
 * A grantee learns of a grant from their notifications, read over OCS the way the phone reads
 * them (CONTEXT.md, Vehicle Access).
 *
 * It writes to the instance it runs against (docs/development.md#testing), and it needs real
 * accounts and a real group.
 */
class GrantNotificationTest extends TestCase {
	use Accounts;

	/** Shown as "Anna": the sentence names the owner. */
	private const OWNER = 'nextfleet-test-told-owner';
	private const BEN = 'nextfleet-test-told-ben';
	/** In the group, and reading German. */
	private const CARL = 'nextfleet-test-told-carl';
	private const LANGUAGES = [self::OWNER => 'en', self::BEN => 'en', self::CARL => 'de'];
	/** The owner is a member too, and is still never told. */
	private const GROUP = 'nextfleet-test-told-crew';
	/** Made and deleted by one case. */
	private const GONE = 'nextfleet-test-told-gone';

	private static string $password;
	private GrantService $grants;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		self::$password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach (self::LANGUAGES as $uid => $language) {
			$users->createUser($uid, self::$password);
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
		$users->get(self::OWNER)?->setDisplayName('Anna');
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::GROUP);
		foreach ([self::OWNER, self::CARL] as $uid) {
			$crew?->addUser($users->get($uid) ?? throw new \RuntimeException('no ' . $uid));
		}
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
		foreach ([self::GROUP, self::GONE] as $gid) {
			\OCP\Server::get(IGroupManager::class)->get($gid)?->delete();
		}
	}

	protected function setUp(): void {
		// Without the notifications app booted a notification has nowhere to go (ReminderJobTest).
		\OCP\Server::get(IAppManager::class)->loadApps();
		$container = (new Application())->getContainer();
		$this->grants = $container->get(GrantService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forget();
	}

	/** Before the accounts go: a deleted account's uid is on none of its rows. */
	protected function tearDown(): void {
		$this->forget();
	}

	/** The rows and the notifications this suite invents, gone for real. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = array_keys(self::LANGUAGES);
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(IManager::class);
		foreach ($people as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
		}
	}

	public function testAUserIsToldWhoGaveThemWhichVehicleAsWhat(): void {
		$vehicle = $this->vehicle();

		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);

		$list = $this->notifications(self::BEN);
		$this->assertSame(['Anna gave you access to NF-DE 100 as a driver'], array_column($list, 'subject'));
		$this->assertStringContainsString('vehicle=' . $vehicle->getUuid(), $list[0]['link']);
	}

	/** Each member as the group stands now, in their own language; the owner never. */
	public function testEachMemberOfAGroupIsToldButNotTheOwner(): void {
		$vehicle = $this->vehicle();

		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);

		$this->assertSame(['Anna hat dir Zugriff auf NF-DE 100 als Betrachter gegeben'], array_column($this->notifications(self::CARL), 'subject'));
		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** A role change sends nothing; a notice not yet read names the role as it stands. */
	public function testARoleChangeSendsNothingNew(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'viewer']);

		$this->grants->change(self::OWNER, $vehicle->getUuid(), $grant['uuid'], ['role' => 'manager']);
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);

		$this->assertSame(['Anna gave you access to NF-DE 100 as a driver'], array_column($this->notifications(self::BEN), 'subject'));
	}

	/** A grant taken back is no longer news, for a user or a whole group. */
	public function testRevokingTakesTheNotificationBack(): void {
		$vehicle = $this->vehicle();
		$this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);
		$list = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::GROUP, 'grantee_type' => 'group', 'role' => 'viewer']);

		foreach ($list as $grant) {
			$this->grants->revoke(self::OWNER, $vehicle->getUuid(), $grant['uuid']);
		}

		$this->assertSame([], $this->notifications(self::BEN));
		$this->assertSame([], $this->notifications(self::CARL));
	}

	/**
	 * Leaving takes the leaver's notice out of the store. Counted there rather than read over OCS:
	 * the notifier already drops a notice whose grant is gone, so a list would be empty either way.
	 */
	public function testLeavingTakesTheNotificationBack(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(1, $this->stored($grant['uuid']));

		$this->grants->leave(self::BEN, $vehicle->getUuid());

		$this->assertSame(0, $this->stored($grant['uuid']));
	}

	/** A deleted group's grant goes, and its members' unread notices with it. */
	public function testDeletingTheGroupTakesTheNotificationBack(): void {
		$vehicle = $this->vehicle();
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::GONE);
		$crew?->addUser(\OCP\Server::get(IUserManager::class)->get(self::BEN) ?? throw new \RuntimeException('no ' . self::BEN));
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::GONE, 'grantee_type' => 'group', 'role' => 'viewer']);
		$this->assertSame(1, $this->stored($grant['uuid']));

		$crew?->delete();

		$this->assertSame(0, $this->stored($grant['uuid']));
	}

	/** A notice of a deleted car is litter, as its reminders' are (ReminderJobTest). */
	public function testDeletingTheVehicleTakesTheNotificationBack(): void {
		$vehicle = $this->vehicle();
		[$grant] = $this->grants->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => self::BEN, 'grantee_type' => 'user', 'role' => 'driver']);
		$this->assertSame(1, $this->stored($grant['uuid']));
		$vehicle = $this->vehicles->find(self::OWNER, $vehicle->getUuid());

		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$this->assertSame(0, $this->stored($grant['uuid']));
	}

	/** How many notifications the store holds for one grant, to anyone. */
	private function stored(string $grantUuid): int {
		$manager = \OCP\Server::get(IManager::class);

		return $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)->setObject(GrantNotices::OBJECT, $grantUuid));
	}

	private function vehicle(): Vehicle {
		return $this->vehicles->create(self::OWNER, ['plate' => 'NF-DE 100']);
	}

	/**
	 * The user's notifications of this app, prepared in their language by the notifier.
	 *
	 * @return list<array{subject: string, link: string}>
	 */
	private function notifications(string $uid): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->get(
			'http://localhost/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
			[
				'auth' => [$uid, self::$password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$data = json_decode((string)$response->getBody(), true)['ocs']['data'];

		return array_values(array_filter($data, static fn (array $one): bool => $one['app'] === Application::APP_ID));
	}
}
