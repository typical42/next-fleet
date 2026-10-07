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
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
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

	/**
	 * Which booking, never who cancelled: a name in a notice is one an erasure cannot take back.
	 * Who cancelled is on the booking, for whoever may see the vehicle.
	 */
	public function testTheBookerIsToldWhichBookingWasCancelled(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$list = $this->notifications(self::BEN);
		$this->assertSame(['Deine Buchung von NF-DE 100 am ' . self::nine()->format('d.m.Y') . ' um 09:00 wurde storniert'], array_column($list, 'subject'));
		$this->assertStringContainsString('vehicle=' . $vehicle->getUuid(), $list[0]['link']);
		$this->assertSame([['vehicle' => $vehicle->getUuid()]], $this->parameters($booking['uuid']));
	}

	/** A notice stored before 0.3.0 still carries who cancelled, and is worded as a new one. */
	public function testAStoredNoticeNamingTheCancellerNamesNobody(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);
		$manager = \OCP\Server::get(IManager::class);
		$manager->notify($manager->createNotification()
			->setApp(Application::APP_ID)
			->setUser(self::BEN)
			->setDateTime(new \DateTime())
			->setObject(BookingNotices::OBJECT, $booking['uuid'])
			->setSubject(BookingNotices::OBJECT, ['vehicle' => $vehicle->getUuid(), 'by' => self::OWNER]));

		$this->assertSame(['Deine Buchung von NF-DE 100 am ' . self::nine()->format('d.m.Y') . ' um 09:00 wurde storniert'], array_column($this->notifications(self::BEN), 'subject'));
	}

	/** Cancelling your own booking is no news to you. */
	public function testTheBookerCancellingTellsNobody(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$this->assertSame(0, $this->stored($booking['uuid']));
	}

	/** Should a cancel ever be undone, its notice is untrue and the notifier drops it. */
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

	/**
	 * A booker whose access ended reads nothing more of the car: the notice names the vehicle as it
	 * stands, so a kept notice would tell a former driver every later plate and name.
	 */
	public function testABookerWhoLostAccessIsToldNothingMore(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);
		$this->assertSame(1, $this->stored($booking['uuid']));
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('fleet_access')->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter((int)$vehicle->getId(), $qb::PARAM_INT)));
		$qb->executeStatement();

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
		$nine = self::nine()->getTimestamp();

		return ['starts_at' => $nine, 'starts_at_off' => 120, 'ends_at' => $nine + 3 * self::HOUR, 'ends_at_off' => 120];
	}

	/** Tomorrow, 09:00 at +02:00: a booking ahead, as the sheet allows, at a time the notice prints. */
	private static function nine(): \DateTimeImmutable {
		return new \DateTimeImmutable('tomorrow 09:00', new \DateTimeZone('+02:00'));
	}

	/** How many notifications the store holds for one booking, to anyone. */
	private function stored(string $bookingUuid): int {
		$manager = \OCP\Server::get(IManager::class);

		return $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)->setObject(BookingNotices::OBJECT, $bookingUuid));
	}

	/**
	 * What the store keeps of one booking's notices: read from the notifications app's table, since
	 * no public interface hands the stored parameters back.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function parameters(string $bookingUuid): array {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('subject_parameters')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter(BookingNotices::OBJECT)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($bookingUuid)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return array_map(static fn (string $json): array => json_decode($json, true), $rows);
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
			Endpoints::server() . '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
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
