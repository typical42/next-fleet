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
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;

/**
 * A booker learns from their notifications that somebody else cancelled their booking, read over
 * OCS the way the phone reads them (CONTEXT.md, Booking).
 *
 * It writes to the instance it runs against (docs/development.md#testing), and it needs real
 * accounts.
 */
class BookingNotificationTest extends TestCase {
	use Accounts;

	/** Shown as "Anna": the sentence names who cancelled. */
	private const OWNER = 'nextfleet-test-pool-owner';
	/** A driver on the owner's car, reading German. */
	private const BEN = 'nextfleet-test-pool-ben';
	private const LANGUAGES = [self::OWNER => 'en', self::BEN => 'de'];
	/** 1 May 2036, 09:00 at +02:00. */
	private const NINE = 2093238000;
	private const HOUR = 3600;

	private static string $password;
	private BookingService $bookings;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
		self::$password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach (self::LANGUAGES as $uid => $language) {
			$users->createUser($uid, self::$password);
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
		$users->get(self::OWNER)?->setDisplayName('Anna');
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
	}

	protected function setUp(): void {
		// Without the notifications app booted a notification has nowhere to go (ReminderJobTest).
		\OCP\Server::get(IAppManager::class)->loadApps();
		$container = (new Application())->getContainer();
		$this->bookings = $container->get(BookingService::class);
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
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'created_by', 'fleet_bookings' => 'created_by'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(IManager::class);
		foreach ($people as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
		}
	}

	public function testTheBookerIsToldWhoCancelledWhichBooking(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$list = $this->notifications(self::BEN);
		$this->assertSame(['Anna hat deine Buchung von NF-DE 100 am 01.05.2036 um 09:00 storniert'], array_column($list, 'subject'));
		$this->assertStringContainsString('vehicle=' . $vehicle->getUuid(), $list[0]['link']);
	}

	/** Cancelling your own booking is no news to you. */
	public function testTheBookerCancellingTellsNobody(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$this->assertSame(0, $this->stored($booking['uuid']));
	}

	/**
	 * Nothing restores a booking yet; if anything ever does, the notice of its cancel is no longer
	 * true, and the notifier drops it from the store.
	 */
	public function testABookingNoLongerCancelledTakesTheNoticeBack(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);
		$this->assertSame(1, $this->stored($booking['uuid']));
		$mapper = \OCP\Server::get(BookingMapper::class);
		$row = $mapper->findOnVehicle((int)$vehicle->getId(), $booking['uuid']);
		$row->setState(Booking::BOOKED);
		$mapper->updateChecked($row, $row->getUpdatedAt());

		$this->assertSame([], $this->notifications(self::BEN));
		$this->assertSame(0, $this->stored($booking['uuid']));
	}

	/** A notice of a deleted car is litter, as its grants' are (GrantNotificationTest). */
	public function testDeletingTheVehicleTakesTheNoticeBack(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);
		$this->assertSame(1, $this->stored($booking['uuid']));
		$vehicle = $this->vehicles->find(self::OWNER, $vehicle->getUuid());

		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$this->assertSame(0, $this->stored($booking['uuid']));
	}

	/** @return array<string, int> */
	private function span(): array {
		return ['starts_at' => self::NINE, 'starts_at_off' => 120, 'ends_at' => self::NINE + 3 * self::HOUR, 'ends_at_off' => 120];
	}

	/** How many notifications the store holds for one booking, to anyone. */
	private function stored(string $bookingUuid): int {
		$manager = \OCP\Server::get(IManager::class);

		return $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)->setObject(BookingNotices::OBJECT, $bookingUuid));
	}

	/** The owner's car, with Ben on it as a driver. No grant notice: the grant row is written directly. */
	private function vehicle(): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-DE 100']);
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee(self::BEN);
		$grant->setGranteeType(Access::USER);
		$grant->setRole('driver');
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);

		return $vehicle;
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
