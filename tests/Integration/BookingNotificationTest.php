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
use OCA\NextFleet\Exception\AccessDeniedException;
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
	/** A driver on the owner's car, reading German, shown as "Ben". */
	private const BEN = 'nextfleet-test-pool-ben';
	/** A viewer on the owner's car, in `CREW`. */
	private const CLEO = 'nextfleet-test-pool-cleo';
	/** Sees nothing of the car, in `CREW`. */
	private const DORA = 'nextfleet-test-pool-dora';
	private const CREW = 'nextfleet-test-pool-crew';
	private const LANGUAGES = [self::OWNER => 'en', self::BEN => 'de', self::CLEO => 'en', self::DORA => 'en'];
	private const HOUR = 3600;

	private static string $password;
	private BookingService $bookings;
	private VehicleService $vehicles;
	private RecipientService $recipients;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(array_keys(self::LANGUAGES));
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
		self::$password = bin2hex(random_bytes(16));
		$users = \OCP\Server::get(IUserManager::class);
		$config = \OCP\Server::get(IConfig::class);
		foreach (self::LANGUAGES as $uid => $language) {
			$users->createUser($uid, self::$password);
			$config->setUserValue($uid, 'core', 'lang', $language);
		}
		$users->get(self::OWNER)?->setDisplayName('Anna');
		$users->get(self::BEN)?->setDisplayName('Ben');
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::CREW);
		foreach ([self::CLEO, self::DORA] as $uid) {
			$user = $users->get($uid);
			if ($crew !== null && $user !== null) {
				$crew->addUser($user);
			}
		}
	}

	public static function tearDownAfterClass(): void {
		\OCP\Server::get(IGroupManager::class)->get(self::CREW)?->delete();
		self::deleteAccounts(array_keys(self::LANGUAGES));
	}

	protected function setUp(): void {
		// Without the notifications app booted a notification has nowhere to go (ReminderJobTest).
		\OCP\Server::get(IAppManager::class)->loadApps();
		$this->bookings = \OCP\Server::get(BookingService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->recipients = \OCP\Server::get(RecipientService::class);
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
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'created_by', 'fleet_bookings' => 'created_by', 'fleet_booking_recipients' => 'created_by'] as $table => $column) {
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

	/** An empty list is the owner's; a new vehicle's list hears of bookings. */
	public function testTheOwnerHearsOfABookingByDefault(): void {
		$vehicle = $this->vehicle();

		$this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$subjects = array_column($this->notifications(self::OWNER), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Ben booked NF-DE 100 from ', $subjects[0]);
		$this->assertSame([], $this->notifications(self::BEN));
	}

	/** Nobody is told of their own action. */
	public function testTheOwnerBookingTellsTheOwnerNothing(): void {
		$vehicle = $this->vehicle();

		$this->bookings->book(self::OWNER, $vehicle->getUuid(), $this->span());

		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Take it now books a start within the slack: that is the car taken, not a booking ahead. */
	public function testTakingTheCarNowIsTakenNotBooked(): void {
		$vehicle = $this->vehicle();
		$now = time();

		$this->bookings->book(self::BEN, $vehicle->getUuid(), ['starts_at' => $now, 'starts_at_off' => 120, 'ends_at' => $now + self::HOUR, 'ends_at_off' => 120]);

		$subjects = array_column($this->notifications(self::OWNER), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Ben took NF-DE 100 until ', $subjects[0]);
	}

	/**
	 * Only the ticked events reach the list. A new purpose alone is no change; a new end is, and so
	 * are a cancel and a return.
	 */
	public function testOnlyTickedEventsAreTold(): void {
		$vehicle = $this->vehicle(['changed', 'cancelled', 'returned']);
		$later = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->assertSame([], $this->notifications(self::OWNER));

		$renamed = $this->bookings->change(self::BEN, $vehicle->getUuid(), $later['uuid'], $later['updated_at'], $this->span() + ['purpose' => 'Fair']);
		$this->assertSame([], $this->notifications(self::OWNER));
		$moved = $this->bookings->change(self::BEN, $vehicle->getUuid(), $later['uuid'], $renamed['updated_at'], ['ends_at' => $this->span()['ends_at'] + self::HOUR] + $this->span());
		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $later['uuid'], $moved['updated_at']);
		$now = time();
		$taken = $this->bookings->book(self::BEN, $vehicle->getUuid(), ['starts_at' => $now, 'starts_at_off' => 120, 'ends_at' => $now + self::HOUR, 'ends_at_off' => 120]);
		$this->bookings->checkOut(self::BEN, $vehicle->getUuid(), $taken['uuid'], ['odo' => 1000, 'at_off' => 120]);
		$this->bookings->checkIn(self::BEN, $vehicle->getUuid(), $taken['uuid'], ['odo' => 1042, 'level' => 40, 'at_off' => 120]);

		$subjects = array_column($this->notifications(self::OWNER), 'subject');
		sort($subjects);
		$this->assertCount(3, $subjects);
		$this->assertSame('Ben returned NF-DE 100 at 1042 km, tank or battery at 40%', $subjects[0]);
		$this->assertStringStartsWith('The booking of NF-DE 100 by Ben from ', $subjects[1]);
		$this->assertStringEndsWith(' was cancelled', $subjects[1]);
		$this->assertStringStartsWith('The booking of NF-DE 100 by Ben now runs from ', $subjects[2]);
	}

	/**
	 * A list that names anybody no longer means the owner. A group is its members, and only those
	 * who see the car hear of it: being on the list grants nothing.
	 */
	public function testTheListIsItsPeopleAndTheMembersWhoSeeTheCar(): void {
		$vehicle = $this->vehicle();
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CREW, 'group');

		$this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertCount(1, $this->notifications(self::CLEO));
		$this->assertSame([], $this->notifications(self::DORA));
	}

	/** The owner writes the list; a manager reads it and is refused a change. */
	public function testTheOwnerWritesTheListAndAManagerReadsIt(): void {
		$vehicle = $this->vehicle(manager: self::CLEO);

		$listed = $this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::BEN, 'user');
		$this->assertSame([['recipient' => self::BEN, 'recipient_type' => 'user', 'display_name' => 'Ben']], $listed);
		$this->assertSame($listed, $this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::BEN, 'user'));
		$this->assertSame($listed, $this->recipients->listBooking(self::CLEO, $vehicle->getUuid()));

		try {
			$this->recipients->addBooking(self::CLEO, $vehicle->getUuid(), self::CLEO, 'user');
			$this->fail('a manager changed the list');
		} catch (AccessDeniedException) {
		}
		$this->assertSame([], $this->recipients->removeBooking(self::OWNER, $vehicle->getUuid(), 'user', self::BEN));
	}

	public function testAGroupGoneLeavesTheList(): void {
		$vehicle = $this->vehicle();
		$groups = \OCP\Server::get(IGroupManager::class);
		$groups->createGroup(self::CREW . '-gone');
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CREW . '-gone', 'group');

		$groups->get(self::CREW . '-gone')?->delete();

		$this->assertSame([], $this->recipients->listBooking(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * A request goes to whoever answers it, the owner and the managers, whatever the list hears;
	 * once answered it is no longer news to them.
	 */
	public function testARequestGoesToTheOwnerAndTheManagersUntilItIsAnswered(): void {
		$vehicle = $this->vehicle([], self::CLEO, approval: true);

		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$owner = array_column($this->notifications(self::OWNER), 'subject');
		$this->assertCount(1, $owner);
		$this->assertStringStartsWith('Ben asks to book NF-DE 100 from ', $owner[0]);
		$this->assertCount(1, $this->notifications(self::CLEO));
		$this->assertSame([], $this->notifications(self::BEN));
		$this->assertSame([], $this->notifications(self::DORA));

		$this->bookings->answer(self::CLEO, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);

		$this->assertSame([], $this->notifications(self::OWNER));
		$this->assertSame([], $this->notifications(self::CLEO));
	}

	/** A request the booker cancelled is nothing left to answer. */
	public function testACancelledRequestIsNoLongerAsked(): void {
		$vehicle = $this->vehicle([], approval: true);
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->assertCount(1, $this->notifications(self::OWNER));

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at']);

		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Every answer reaches the booker, named by who gave it; a later one replaces the earlier. */
	public function testTheBookerHearsEachAnswer(): void {
		$vehicle = $this->vehicle([], approval: true);
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$back = $this->bookings->answer(self::OWNER, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::ASKED, ['comment' => 'Den Transporter?']);
		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Anna hat eine Frage zu deiner Buchung von NF-DE 100 ab ', $subjects[0]);

		$this->bookings->answer(self::OWNER, $vehicle->getUuid(), $asked['uuid'], $back['updated_at'], Booking::DECLINED, []);
		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Anna hat deine Buchung von NF-DE 100 ab ', $subjects[0]);
		$this->assertStringEndsWith(' abgelehnt', $subjects[0]);
	}

	public function testAnApprovalReachesTheBooker(): void {
		$vehicle = $this->vehicle([], approval: true);
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->answer(self::OWNER, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);

		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringEndsWith(' genehmigt', $subjects[0]);
	}

	/**
	 * With approval on, the list hears of a booking once it is approved: booked the first time,
	 * changed when a new span is approved again, and cancelled once it heard of it.
	 */
	public function testTheListHearsOfABookingOnceItIsApproved(): void {
		$vehicle = $this->vehicle(['booked', 'changed', 'cancelled'], approval: true);
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user');
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->assertSame([], $this->notifications(self::CLEO));

		$approved = $this->bookings->answer(self::OWNER, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], Booking::APPROVED, []);
		$this->assertStringStartsWith('Ben booked NF-DE 100 from ', $this->notifications(self::CLEO)[0]['subject'] ?? '');
		$moved = $this->bookings->change(self::BEN, $vehicle->getUuid(), $asked['uuid'], $approved['updated_at'], ['ends_at' => $this->span()['ends_at'] + self::HOUR] + $this->span());
		$this->assertCount(1, $this->notifications(self::CLEO));
		$again = $this->bookings->answer(self::OWNER, $vehicle->getUuid(), $asked['uuid'], $moved['updated_at'], Booking::APPROVED, []);
		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $asked['uuid'], $again['updated_at']);

		$subjects = array_column($this->notifications(self::CLEO), 'subject');
		sort($subjects);
		$this->assertCount(3, $subjects);
		$this->assertStringStartsWith('Ben booked NF-DE 100 from ', $subjects[0]);
		$this->assertStringEndsWith(' was cancelled', $subjects[1]);
		$this->assertStringStartsWith('The booking of NF-DE 100 by Ben now runs from ', $subjects[2]);
	}

	/** A request declined or withdrawn was never news to the list. */
	public function testARequestNotApprovedNeverReachesTheList(): void {
		$vehicle = $this->vehicle(['booked', 'changed', 'cancelled'], approval: true);
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user');
		$declined = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->bookings->answer(self::OWNER, $vehicle->getUuid(), $declined['uuid'], $declined['updated_at'], Booking::DECLINED, []);
		$withdrawn = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$moved = $this->bookings->change(self::BEN, $vehicle->getUuid(), $withdrawn['uuid'], $withdrawn['updated_at'], ['ends_at' => $this->span()['ends_at'] + self::HOUR] + $this->span());

		$this->bookings->cancel(self::BEN, $vehicle->getUuid(), $withdrawn['uuid'], $moved['updated_at']);

		$this->assertSame([], $this->notifications(self::CLEO));
	}

	/** A request moved before its answer is asked again with the new span, and only that. */
	public function testAMovedRequestIsAskedAgainWithItsNewSpan(): void {
		$vehicle = $this->vehicle([], approval: true);
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());

		$this->bookings->change(self::BEN, $vehicle->getUuid(), $asked['uuid'], $asked['updated_at'], ['ends_at' => $this->span()['ends_at'] + self::HOUR] + $this->span());

		$this->assertSame([$this->span()['ends_at'] + self::HOUR], array_column($this->parameters($asked['uuid'], BookingNotices::APPROVAL), 'ends_at'));
		$this->assertCount(1, $this->notifications(self::OWNER));
	}

	/**
	 * Declined at its end, a request names nobody who declined it; the list never heard of it. An
	 * asked-back one is declined the same way, so the words do not say nobody answered.
	 */
	public function testTheBookerHearsTheRequestWasNotApprovedInTime(): void {
		$vehicle = $this->vehicle(['booked', 'cancelled'], approval: true);
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user');
		$asked = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$mapper = \OCP\Server::get(BookingMapper::class);
		$row = $mapper->findOnVehicle((int)$vehicle->getId(), $asked['uuid']);
		$row->setEndsAt(time() - 60);
		$row->setStartsAt(time() - 3 * self::HOUR);
		$mapper->updateChecked($row, $row->getUpdatedAt());

		$this->bookings->declineUnanswered();

		$subjects = array_column($this->notifications(self::BEN), 'subject');
		$this->assertCount(1, $subjects);
		$this->assertStringStartsWith('Deine Buchung von NF-DE 100 ab ', $subjects[0]);
		$this->assertStringEndsWith(' wurde bis zu ihrem Ende nicht genehmigt und ist damit abgelehnt', $subjects[0]);
		$this->assertSame([], $this->notifications(self::CLEO));
		$this->assertSame([], $this->notifications(self::OWNER));
	}

	/** Turning approval off is the owner's yes: the booker and the list hear of it as of an answer. */
	public function testTurningApprovalOffTellsAsAnApprovalDoes(): void {
		$vehicle = $this->vehicle(['booked'], approval: true);
		$this->recipients->addBooking(self::OWNER, $vehicle->getUuid(), self::CLEO, 'user');
		$this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$read = $this->vehicles->find(self::OWNER, $vehicle->getUuid());

		$this->vehicles->update(self::OWNER, $vehicle->getUuid(), $read->getUpdatedAt(), ['booking_approval' => false]);

		$this->assertStringEndsWith(' genehmigt', $this->notifications(self::BEN)[0]['subject'] ?? '');
		$this->assertStringStartsWith('Ben booked NF-DE 100 from ', $this->notifications(self::CLEO)[0]['subject'] ?? '');
	}

	/** Deleting the car takes back what the list heard, as it does the booker's cancel notice. */
	public function testDeletingTheVehicleTakesTheListsNoticesBack(): void {
		$vehicle = $this->vehicle();
		$booking = $this->bookings->book(self::BEN, $vehicle->getUuid(), $this->span());
		$this->assertCount(1, $this->notifications(self::OWNER));
		$vehicle = $this->vehicles->find(self::OWNER, $vehicle->getUuid());

		$this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$manager = \OCP\Server::get(IManager::class);
		$this->assertSame(0, $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)->setObject(BookingNotices::EVENT, $booking['uuid'])));
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
	private function parameters(string $bookingUuid, string $type = BookingNotices::OBJECT): array {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('subject_parameters')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($bookingUuid)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		return array_map(static fn (string $json): array => json_decode($json, true), $rows);
	}

	/**
	 * The owner's car, with Ben on it as a driver and Cleo as a viewer, or as a manager. No grant
	 * notice: the grant rows are written directly.
	 *
	 * @param list<string>|null $events what the list hears, the default when null
	 * @param bool $approval whether its bookings wait for an answer
	 */
	private function vehicle(?array $events = null, string $manager = '', bool $approval = false): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-DE 100', 'booking_approval' => $approval] + ($events === null ? [] : ['booking_notices' => $events]));
		foreach ([self::BEN => 'driver', self::CLEO => $manager === self::CLEO ? 'manager' : 'viewer'] as $uid => $role) {
			$grant = new Access();
			$grant->setVehicleId((int)$vehicle->getId());
			$grant->setGrantee($uid);
			$grant->setGranteeType(Access::USER);
			$grant->setRole($role);
			$grant->setCreatedBy(self::OWNER);
			\OCP\Server::get(AccessMapper::class)->insert($grant);
		}

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
