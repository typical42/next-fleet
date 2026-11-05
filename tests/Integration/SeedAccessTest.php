<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\SeedCommand;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\MileageClaimExport;
use OCA\NextFleet\Service\RecipientService;
use OCA\NextFleet\Service\VehicleService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:seed <owner> --grant-to <uid> --grant-to <uid>`, through the real services: the
 * first account drives the Passat, and one trip and one booking on it are theirs; both share the
 * Volvo as a pool car.
 */
class SeedAccessTest extends TestCase {
	use Accounts;
	use SharingRules;

	/** Accounts all, since the papers go into the owner's Files and a grantee has to exist. */
	private const OWNER = 'nextfleet-test-seed-owner';
	private const DRIVER = 'nextfleet-test-seed-ben';
	private const SECOND = 'nextfleet-test-seed-cara';
	/** Seeds under "members only", apart from OWNER so the fleet the other tests read stays. */
	private const LONER = 'nextfleet-test-seed-loner';
	private const PASSAT = 'NF-DE 100';
	private const VOLVO = 'NF-PH 200';

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::OWNER, self::DRIVER, self::SECOND, self::LONER]);
		$users = \OCP\Server::get(IUserManager::class);
		foreach ([self::OWNER, self::DRIVER, self::SECOND, self::LONER] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}

		self::seed(['user' => self::OWNER, '--grant-to' => [self::DRIVER, self::SECOND]]);
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::OWNER, self::DRIVER, self::SECOND, self::LONER]);
	}

	/**
	 * Under "share only with group members", with no group in common, the grant is refused as the
	 * screen refuses it: the fleet is written, the account reaches none of it, and the run fails
	 * in words rather than with GrantService's exception.
	 */
	public function testAGrantTheSharingSettingsRefuseFailsInWords(): void {
		$tester = new CommandTester(\OCP\Server::get(SeedCommand::class));

		$this->underSharingRules(['shareapi_only_share_with_group_members' => 'yes'], function () use ($tester): void {
			$this->assertSame(1, $tester->execute(['user' => self::LONER, '--grant-to' => [self::DRIVER]]));
		});

		$this->assertStringContainsString('sharing settings', $tester->getDisplay());
		$plates = static fn (string $userId): array => array_map(
			static fn (Vehicle $vehicle): string => $vehicle->getUserId() . ' ' . $vehicle->getPlate(),
			\OCP\Server::get(VehicleService::class)->list($userId),
		);
		$this->assertContains(self::LONER . ' ' . self::PASSAT, $plates(self::LONER));
		$this->assertNotContains(self::LONER . ' ' . self::PASSAT, $plates(self::DRIVER));
	}

	/** @param array<string, string|list<string>> $input */
	private static function seed(array $input): void {
		$tester = new CommandTester(\OCP\Server::get(SeedCommand::class));
		self::assertSame(0, $tester->execute($input), $tester->getDisplay());
	}

	public function testTheAccountDrivesTheOwnersPassat(): void {
		$passat = $this->passatOf(self::DRIVER);

		$this->assertSame(['view', 'log', 'costs', 'take_now'], $passat->getMay());
		$this->assertNotNull($passat->getOwnedBy());
	}

	/**
	 * The Volvo is theirs to drive too, without what it cost, and their booking of it waits for the
	 * owner's answer. They are the driver whose Take it now waits as well.
	 */
	public function testTheAccountDrivesTheVolvoWithoutItsCostsAndAsksForEachUse(): void {
		$volvo = $this->volvoOf(self::DRIVER);

		$this->assertSame(['view', 'log'], $volvo->getMay());
		$this->assertNull($volvo->jsonSerialize()['purchase_price']);
		$bookings = \OCP\Server::get(BookingService::class)->list(self::OWNER, $volvo->getUuid(), []);
		$theirs = array_values(array_filter($bookings, static fn (array $one): bool => $one['user_id'] === self::DRIVER));
		$this->assertSame([Booking::REQUESTED], array_column($theirs, 'approval'));
	}

	/**
	 * The Volvo is the pool car: it follows the owner's default for approval, which the seed turns
	 * on, and the second account may take it at once.
	 */
	public function testTheVolvoFollowsTheOwnersDefaultWhichAsksForApproval(): void {
		$volvo = $this->volvoOf(self::OWNER);

		$this->assertSame(Vehicle::APPROVAL_DEFAULT, $volvo->approvalRule());
		$this->assertTrue($volvo->needsApproval());
		$this->assertSame(['view', 'log', 'take_now'], $this->volvoOf(self::SECOND)->getMay());
		// The Passat, shared too, says Never, so its booking needs nobody's answer.
		$this->assertFalse($this->passatOf(self::OWNER)->needsApproval());
	}

	/** And the owner booked it for the second account, who has not pressed Seen yet. */
	public function testTheOwnerBookedTheVolvoForTheSecondAccount(): void {
		$bookings = \OCP\Server::get(BookingService::class)->list(self::OWNER, $this->volvoOf(self::OWNER)->getUuid(), []);
		$made = array_values(array_filter($bookings, static fn (array $one): bool => $one['user_id'] === self::SECOND));

		$this->assertCount(1, $made);
		$this->assertSame(self::OWNER, $made[0]['created_by']);
		$this->assertSame(Booking::APPROVED, $made[0]['approval']);
		$this->assertNull($made[0]['seen_at']);
	}

	/** The owner hears of everything; each driver only that the car was taken. */
	public function testEachOnTheNoticeListHearsItsOwnEvents(): void {
		$list = \OCP\Server::get(RecipientService::class)->listBooking(self::OWNER, $this->volvoOf(self::OWNER)->getUuid());
		$heard = [];
		foreach ($list as $row) {
			$heard[$row['recipient']] = $row['events'];
		}
		ksort($heard);

		$this->assertSame([
			self::DRIVER => [Vehicle::NOTICE_TAKEN],
			self::SECOND => [Vehicle::NOTICE_TAKEN],
			self::OWNER => Vehicle::NOTICES,
		], $heard);
	}

	private function volvoOf(string $userId): Vehicle {
		foreach (\OCP\Server::get(VehicleService::class)->list($userId) as $vehicle) {
			if ($vehicle->getPlate() === self::VOLVO && $vehicle->getUserId() === self::OWNER) {
				return $vehicle;
			}
		}
		$this->fail($userId . ' reaches no Volvo of ' . self::OWNER);
	}

	/** One trip each way round, and each reader's mileage claim holds only their own. */
	public function testOneTripOnThePassatIsTheAccountsOwn(): void {
		$trips = \OCP\Server::get(TripMapper::class)->findAnyStartedBetween((int)$this->passatOf(self::OWNER)->getId(), 0, PHP_INT_MAX);
		$theirs = array_values(array_filter($trips, static fn (Trip $trip): bool => $trip->getCreatedBy() === self::DRIVER));
		$this->assertCount(1, $theirs);

		$year = (int)(new \DateTimeImmutable('@' . ($theirs[0]->getStartedAt() + 60 * $theirs[0]->getStartedAtOff())))->format('Y');
		$claims = \OCP\Server::get(MileageClaimExport::class);
		$uuid = $this->passatOf(self::OWNER)->getUuid();
		$this->assertStringContainsString('Lieferung', (string)$claims->year(self::DRIVER, $uuid, $year));
		$this->assertStringNotContainsString('Lieferung', (string)$claims->year(self::OWNER, $uuid, $year));
	}

	/** And one booking of theirs, tomorrow 09:00 to 12:00 by Berlin's clock, which the owner sees. */
	public function testTheAccountHasBookedThePassatTomorrowMorning(): void {
		$bookings = \OCP\Server::get(BookingService::class)
			->list(self::OWNER, $this->passatOf(self::OWNER)->getUuid(), []);

		$this->assertSame([self::DRIVER], array_column($bookings, 'user_id'));
		$booking = $bookings[0];
		$this->assertSame(Booking::BOOKED, $booking['state']);
		$berlin = new \DateTimeZone('Europe/Berlin');
		$tomorrow = (new \DateTimeImmutable('tomorrow', $berlin))->format('Y-m-d');
		$this->assertSame($tomorrow . ' 09:00', (new \DateTimeImmutable('@' . (int)$booking['starts_at']))->setTimezone($berlin)->format('Y-m-d H:i'));
		$this->assertSame($tomorrow . ' 12:00', (new \DateTimeImmutable('@' . (int)$booking['ends_at']))->setTimezone($berlin)->format('Y-m-d H:i'));
	}

	/**
	 * Seeding the account it was granted to retires that account's own demo fleet and nobody
	 * else's: the owner's Passat, which it reaches, stays.
	 */
	public function testSeedingTheGranteeLeavesTheOwnersPassatStanding(): void {
		self::seed(['user' => self::DRIVER]);

		$this->assertSame(self::OWNER, $this->passatOf(self::OWNER)->getUserId());
	}

	private function passatOf(string $userId): Vehicle {
		foreach (\OCP\Server::get(VehicleService::class)->list($userId) as $vehicle) {
			if ($vehicle->getPlate() === self::PASSAT && $vehicle->getUserId() === self::OWNER) {
				return $vehicle;
			}
		}
		$this->fail($userId . ' reaches no Passat of ' . self::OWNER);
	}
}
