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
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Service\BookingNotices;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\RecipientService;
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
 * The owner or a manager books a vehicle for one of its drivers (docs/architecture.md#booking-for-a-driver):
 * the booking is the driver's, made by the approver, and stands at once. The driver confirms that
 * they saw it.
 *
 * It writes to the instance it runs against (docs/development.md#testing), and it needs real
 * accounts.
 */
class BookForDriverTest extends TestCase {
	use Accounts;

	/** The owner, shown as "Anna". */
	private const OWNER = 'nextfleet-test-for-owner';
	/** A driver by name, shown as "Ben". */
	private const BEN = 'nextfleet-test-for-ben';
	/** A manager by name, shown as "Cleo". */
	private const CLEO = 'nextfleet-test-for-cleo';
	/** A viewer by name. */
	private const DORA = 'nextfleet-test-for-dora';
	/** A driver through `CREW` only. */
	private const EVA = 'nextfleet-test-for-eva';
	/** Sees nothing of the car. */
	private const FINN = 'nextfleet-test-for-finn';
	private const CREW = 'nextfleet-test-for-crew';
	private const PEOPLE = [self::OWNER => 'Anna', self::BEN => 'Ben', self::CLEO => 'Cleo', self::DORA => 'Dora', self::EVA => 'Eva', self::FINN => 'Finn'];
	private const HOUR = 3600;

	private static string $password;
	private BookingService $bookings;
	private VehicleService $vehicles;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(array_keys(self::PEOPLE));
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
		self::$password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach (self::PEOPLE as $uid => $name) {
			$users->createUser($uid, self::$password)?->setDisplayName($name);
			$config->setUserValue($uid, 'core', 'lang', 'en');
		}
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::CREW);
		$crew?->addUser($users->get(self::EVA) ?? throw new \RuntimeException('no ' . self::EVA));
	}

	public static function tearDownAfterClass(): void {
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
		self::deleteAccounts(array_keys(self::PEOPLE));
	}

	protected function setUp(): void {
		// Without the notifications app booted a notification has nowhere to go (ReminderJobTest).
		\OCP\Server::get(IAppManager::class)->loadApps();
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** The rows and the notifications this suite invents. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = array_keys(self::PEOPLE);
		foreach ([['fleet_bookings', 'created_by'], ['fleet_booking_recipients', 'created_by'], ['fleet_access', 'created_by'], ['fleet_vehicles', 'user_id']] as [$table, $column]) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		$manager = \OCP\Server::get(IManager::class);
		foreach ($people as $uid) {
			$manager->markProcessed($manager->createNotification()->setApp(Application::APP_ID)->setUser($uid));
		}
	}

	/** The owner's booking for Ben is Ben's, made by the owner, and needs no answer. */
	public function testTheOwnerBooksForADriver(): void {
		$vehicle = $this->vehicle();

		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->assertSame([self::BEN, 'Ben', self::OWNER, 'Anna'], [$booking['user_id'], $booking['user_name'], $booking['created_by'], $booking['created_by_name']]);
		$this->assertSame(Booking::APPROVED, $booking['approval']);
		$this->assertSame(self::OWNER, $booking['approval_by']);
		$this->assertNull($booking['seen_at']);
	}

	/** A manager books for a driver through a group, and for the owner: whoever may book it. */
	public function testAManagerBooksForAnybodyWhoMayBook(): void {
		$vehicle = $this->vehicle();

		$forEva = $this->bookings->book(self::CLEO, $vehicle->getUuid(), $this->span(1) + ['user_id' => self::EVA]);
		$forAnna = $this->bookings->book(self::CLEO, $vehicle->getUuid(), $this->span(2) + ['user_id' => self::OWNER]);

		$this->assertSame([self::EVA, self::CLEO, Booking::APPROVED], [$forEva['user_id'], $forEva['created_by'], $forEva['approval']]);
		$this->assertSame([self::OWNER, self::CLEO], [$forAnna['user_id'], $forAnna['created_by']]);
	}

	/**
	 * The owner and a manager read whom they may book for: whoever may book the car, through a
	 * group too, the owner and themselves included, by name. A viewer and a stranger are not on it.
	 */
	public function testAnApproverReadsWhoMayBeBookedFor(): void {
		$vehicle = $this->vehicle();
		$expected = [
			['user_id' => self::OWNER, 'display_name' => 'Anna'],
			['user_id' => self::BEN, 'display_name' => 'Ben'],
			['user_id' => self::CLEO, 'display_name' => 'Cleo'],
			['user_id' => self::EVA, 'display_name' => 'Eva'],
		];

		$this->assertSame($expected, $this->bookings->bookers(self::OWNER, $vehicle->getUuid()));
		$this->assertSame($expected, $this->bookings->bookers(self::CLEO, $vehicle->getUuid()));
	}

	/** A driver books for nobody else, so the list is not theirs to read. */
	public function testADriverReadsNoneToBookFor(): void {
		$vehicle = $this->vehicle();

		$this->expectException(AccessDeniedException::class);
		$this->bookings->bookers(self::BEN, $vehicle->getUuid());
	}

	/** On a vehicle that asks nobody, a booking for a driver waits for nobody either. */
	public function testWithoutApprovalTheBookingForADriverIsPlain(): void {
		$vehicle = $this->vehicle(Vehicle::APPROVAL_NEVER);

		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->assertSame([self::BEN, null], [$booking['user_id'], $booking['approval']]);
	}

	/** Naming yourself is booking for yourself, in whatever case. */
	public function testNamingYourselfIsAPlainBooking(): void {
		$vehicle = $this->vehicle();

		foreach ([self::BEN, strtoupper(self::BEN)] as $days => $named) {
			$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span($days + 1) + ['user_id' => $named]);

			$this->assertSame([self::BEN, self::BEN, Booking::REQUESTED], [$booking['user_id'], $booking['created_by'], $booking['approval']], $named);
		}
	}

	/** A driver books for nobody else: the booking would stand without an answer. */
	public function testADriverBooksForNobodyElse(): void {
		$vehicle = $this->vehicle();

		$this->expectException(AccessDeniedException::class);
		$this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span() + ['user_id' => self::EVA]);
	}

	/**
	 * Only somebody who may book the car is booked for: a viewer, an account without access or none
	 * at all is refused, so a booking hands out nothing.
	 *
	 * @dataProvider notBookers
	 */
	public function testOnlySomebodyWhoMayBookIsBookedFor(mixed $named): void {
		$vehicle = $this->vehicle();

		try {
			$this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => $named]);
			$this->fail('booked for ' . var_export($named, true));
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame([], $this->bookings->list(self::OWNER, $vehicle->getUuid(), []));
	}

	/** @return array<string, array{mixed}> */
	public static function notBookers(): array {
		return [
			'a viewer' => [self::DORA],
			'an account without access' => [self::FINN],
			'no account' => ['nextfleet-test-for-nobody'],
			'a group' => [self::CREW],
			'no uid' => [42],
		];
	}

	/** The driver confirms once; the first moment stays, and nobody else confirms for them. */
	public function testOnlyTheDriverMarksTheBookingSeen(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$seen = $this->bookings->seen(self::BEN, $vehicle->getUuid(), $booking['uuid']);
		$this->assertIsInt($seen['seen_at']);
		$this->assertSame($seen['seen_at'], $this->bookings->seen(self::BEN, $vehicle->getUuid(), $booking['uuid'])['seen_at']);

		foreach ([self::OWNER, self::CLEO] as $other) {
			try {
				$this->bookings->seen($other, $vehicle->getUuid(), $booking['uuid']);
				$this->fail($other . ' confirmed for Ben');
			} catch (AccessDeniedException) {
			}
		}
	}

	/** Taken now for a driver, the booking is approved by its maker, as one ahead is. */
	public function testTakeItNowForADriverIsApproved(): void {
		$vehicle = $this->vehicle();

		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->now() + ['user_id' => self::BEN]);

		$this->assertSame([self::BEN, Booking::APPROVED], [$booking['user_id'], $booking['approval']]);
	}

	/**
	 * Booked for a driver to start now, the car is not yet taken: the driver checks it out
	 * themselves. The list hears of a booking ahead, though it would hear a take too.
	 */
	public function testTheListHearsABookingForADriverAsBookedAheadThoughItStartsNow(): void {
		$vehicle = $this->vehicle();
		\OCP\Server::get(RecipientService::class)->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user');
		$this->assertContains(Vehicle::NOTICE_TAKEN, Vehicle::DEFAULT_NOTICES);

		$this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->now() + ['user_id' => self::BEN]);

		$this->assertStringStartsWith('Ben booked NF-FOR 1 from ', array_column($this->notifications(self::CLEO), 'subject')[0] ?? '');
	}

	/**
	 * The driver named in another case is stored as the instance spells them, so the booking is
	 * theirs to confirm, change and check out. By name or through a group, both reach the car.
	 */
	public function testTheDriverIsStoredAsTheInstanceSpellsThem(): void {
		$vehicle = $this->vehicle();

		foreach ([self::BEN, self::EVA] as $days => $driver) {
			$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span($days + 1) + ['user_id' => strtoupper($driver)]);

			$this->assertSame($driver, $booking['user_id']);
			$this->assertIsInt($this->bookings->seen($driver, $vehicle->getUuid(), $booking['uuid'])['seen_at']);
		}
	}

	/** A retry answers the row it wrote, though the driver lost the car in between. */
	public function testARetryAnswersTheBookingItWrote(): void {
		$vehicle = $this->vehicle();
		$fields = $this->span() + ['user_id' => self::BEN, 'client_uuid' => '0f6e8a4c-2b1d-4e7a-9c3f-5d8b7a6e1f20'];
		$first = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $fields);
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('fleet_access')->where($qb->expr()->eq('grantee', $qb->createNamedParameter(self::BEN)));
		$qb->executeStatement();

		try {
			$this->bookings->book(self::OWNER, $vehicle->getUuid(), $fields);
			$this->fail('the retry wrote a second booking');
		} catch (AlreadyCreatedException $retried) {
			$this->assertSame($first['uuid'], $retried->answer['uuid']);
		}
	}

	/** Whether the driver confirmed is the driver's and the approvers' to read. */
	public function testOnlyTheDriverAndTheApproversReadSeenAt(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);
		$this->bookings->seen(self::BEN, $vehicle->getUuid(), $booking['uuid']);

		foreach ([self::BEN => true, self::OWNER => true, self::CLEO => true, self::DORA => false, self::EVA => false] as $uid => $reads) {
			$seen = array_column($this->bookings->list($uid, $vehicle->getUuid(), []), 'seen_at')[0];
			$this->assertSame($reads, $seen !== null, $uid);
		}
	}

	/** A booking you made yourself has nothing to confirm. */
	public function testYourOwnBookingIsNotMarkedSeen(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->expectException(\InvalidArgumentException::class);
		$this->bookings->seen(self::BEN, $vehicle->getUuid(), $booking['uuid']);
	}

	/**
	 * The driver hears of the booking once, with a button to confirm it, also when the list hears
	 * of bookings ahead and names them. The rest of the list hears as usual.
	 */
	public function testTheDriverHearsOnceAndTheListAsUsual(): void {
		$vehicle = $this->vehicle();
		$recipients = \OCP\Server::get(RecipientService::class);
		foreach ([self::BEN, self::CLEO] as $uid) {
			$recipients->addBooking(self::OWNER, $vehicle->getUuid(), $uid, 'user');
		}

		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$told = $this->notifications(self::BEN);
		$this->assertCount(1, $told);
		$this->assertStringStartsWith('Anna booked NF-FOR 1 for you from ', $told[0]['subject']);
		$this->assertSame(['Seen'], array_column($told[0]['actions'], 'label'));
		$this->assertSame('POST', $told[0]['actions'][0]['type']);
		$this->assertStringEndsWith('/vehicles/' . $vehicle->getUuid() . '/bookings/' . $booking['uuid'] . '/seen', $told[0]['actions'][0]['link']);
		$this->assertStringStartsWith('Ben booked NF-FOR 1 from ', array_column($this->notifications(self::CLEO), 'subject')[0] ?? '');
		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Confirmed, the notice has done its work and goes. The maker hears nothing of it. */
	public function testConfirmingTakesTheNoticeAway(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);
		$this->assertCount(1, $this->notifications(self::BEN));
		$this->assertSame([], $this->notifications(self::OWNER));

		$this->bookings->seen(self::BEN, $vehicle->getUuid(), $booking['uuid']);

		$this->assertSame([], $this->notifications(self::BEN));
		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Moved by the owner, the booking the driver confirms is the one that stands now. */
	public function testTheNoticeNamesTheSpanAsItStands(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->bookings->change(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at'], $this->span(3));

		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$day = static fn (int $days): string => (new \DateTimeImmutable('today', new \DateTimeZone('+02:00')))->modify('+' . $days . ' day')->format('M j, Y');
		$this->assertStringContainsString('from ' . $day(3), $subjects[0]);
	}

	/** The trash takes the notice back; a restore sends it again while the driver has not confirmed. */
	public function testARestoredVehicleAsksTheDriverAgain(): void {
		$vehicle = $this->vehicle();
		$unseen = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);
		$seen = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span(2) + ['user_id' => self::BEN]);
		$this->bookings->seen(self::BEN, $vehicle->getUuid(), $seen['uuid']);
		$trashed = $this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $this->vehicles->find(self::OWNER, $vehicle->getUuid())->getUpdatedAt());
		$this->assertSame([], $this->notifications(self::BEN));

		$this->vehicles->restore(self::OWNER, $vehicle->getUuid(), $trashed->getUpdatedAt());

		$told = $this->notifications(self::BEN);
		$this->assertCount(1, $told);
		$this->assertSame(['Seen'], array_column($told[0]['actions'], 'label'));
		$this->assertStringContainsString('/bookings/' . $unseen['uuid'] . '/seen', $told[0]['actions'][0]['link']);
	}

	/** A booking for a driver cancelled before they confirmed it is no longer theirs to confirm. */
	public function testACancelledBookingAsksNoConfirmation(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->bookings->cancel(self::OWNER, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Your booking of NF-FOR 1 on ', $subjects[0]);
	}

	/** The driver who cancels tells whoever booked it for them; their own cancel tells nobody. */
	public function testTheDriversCancelTellsWhoBookedIt(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::CLEO, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$subjects = array_column($this->notifications(self::CLEO), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Ben cancelled the booking of NF-FOR 1 from ', $subjects[0]);
		$this->assertStringEndsWith(' that you made for them', $subjects[0]);
		$this->assertSame([], $this->notifications(self::BEN));
		// The empty list is the owner's, who heard of the booking, and does not hear of cancels.
		$this->assertSame(['booking_event'], array_column($this->notifications(self::OWNER), 'object_type'));
	}

	/** Who made the booking hears of the driver's cancel once, also when on the list for cancels. */
	public function testTheMakerOnTheListHearsTheCancelOnce(): void {
		$vehicle = $this->vehicle();
		\OCP\Server::get(RecipientService::class)->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user', ['cancelled']);
		$booking = $this->bookings->book(self::CLEO, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at']);

		$this->assertSame([BookingNotices::MADE_FOR], array_column($this->notifications(self::CLEO), 'object_type'));
	}

	/** The driver's move asks for an answer again, as a move of their own booking does. */
	public function testTheDriversMoveAsksAgain(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span() + ['user_id' => self::BEN]);

		$moved = $this->bookings->change(self::BEN, $vehicle->getUuid(), $booking['uuid'], $booking['updated_at'], $this->span(2));

		$this->assertSame([Booking::REQUESTED, self::OWNER], [$moved['approval'], $moved['created_by']]);
	}

	/**
	 * Take it now for a minute, on the clock that puts the booker at about noon, so the car is
	 * back by midnight whenever the suite runs (TakeNowTest).
	 *
	 * @return array<string, int>
	 */
	private function now(): array {
		$now = time();
		$off = intdiv((43200 - $now % 86400 + 86400 + 43200) % 86400 - 43200, 60);

		return ['starts_at' => $now, 'starts_at_off' => $off, 'ends_at' => $now + 60, 'ends_at_off' => $off];
	}

	/** @return array<string, int> */
	private function span(int $days = 1): array {
		$nine = (new \DateTimeImmutable('today 09:00', new \DateTimeZone('+02:00')))->modify('+' . $days . ' day')->getTimestamp();

		return ['starts_at' => $nine, 'starts_at_off' => 120, 'ends_at' => $nine + 3 * self::HOUR, 'ends_at_off' => 120];
	}

	/**
	 * The owner's car that asks for approval: Ben a driver, Cleo a manager, Dora a viewer, the crew
	 * drivers. The grant rows are written directly, so no grant notice is sent.
	 */
	private function vehicle(string $rule = Vehicle::APPROVAL_ALWAYS): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-FOR 1', 'booking_approval_rule' => $rule]);
		foreach ([[self::BEN, Access::USER, 'driver'], [self::CLEO, Access::USER, 'manager'], [self::DORA, Access::USER, 'viewer'], [self::CREW, Access::GROUP, 'driver']] as [$grantee, $type, $role]) {
			$grant = new Access();
			$grant->setVehicleId((int)$vehicle->getId());
			$grant->setGrantee($grantee);
			$grant->setGranteeType($type);
			$grant->setRole($role);
			$grant->setCreatedBy(self::OWNER);
			\OCP\Server::get(AccessMapper::class)->insert($grant);
		}

		return $vehicle;
	}

	/**
	 * The user's notifications of this app, prepared by the notifier and read over OCS.
	 *
	 * @return list<array{object_type: string, subject: string, message: string, link: string, actions: list<array{label: string, link: string, type: string, primary: bool}>}>
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
