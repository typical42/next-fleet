<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\GrantService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OdometerService;
use OCA\NextFleet\Service\ReminderService;
use OCA\NextFleet\Service\SyncService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * What a client's sync hands it, against the real database, real accounts and real groups
 * (docs/api.md#sync). Rows are aged by hand where a case needs one older than the settle window:
 * the clock is the server's.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class SyncTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-sync-owner';
	private const DRIVER = 'nextfleet-test-sync-driver';
	private const MEMBER = 'nextfleet-test-sync-member';
	private const ACCOUNTS = [self::OWNER, self::DRIVER, self::MEMBER];
	private const GROUP = 'nextfleet-test-sync-crew';
	/** Made and deleted by the deleted-group case alone. */
	private const GONE = 'nextfleet-test-sync-gone';
	/** Made and deleted by the erasure case alone. */
	private const ERASED = 'nextfleet-test-sync-erased';

	/** Every table that hangs a row off a vehicle. */
	private const TABLES = ['fleet_odo_readings', 'fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_reminders', 'fleet_reminder_recipients', 'fleet_documents', 'fleet_bookings', 'fleet_access'];

	private SyncService $sync;
	private VehicleService $vehicles;
	private ExpenseService $expenses;
	/** @var list<int> */
	private array $vehicleIds = [];

	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
		$crew = \OCP\Server::get(IGroupManager::class)->createGroup(self::GROUP);
		$crew?->addUser($users->get(self::MEMBER) ?? throw new \RuntimeException('no ' . self::MEMBER));
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		self::deleteAccounts([...self::ACCOUNTS, self::ERASED]);
		foreach ([self::GROUP, self::GONE] as $gid) {
			\OCP\Server::get(IGroupManager::class)->get($gid)?->delete();
		}
	}

	protected function setUp(): void {
		$this->sync = \OCP\Server::get(SyncService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->expenses = \OCP\Server::get(ExpenseService::class);
	}

	/** The rows this suite invents, gone for real - a soft delete would outlive the run. */
	protected function tearDown(): void {
		if ($this->vehicleIds === []) {
			return;
		}
		$db = \OCP\Server::get(IDBConnection::class);
		foreach ([...self::TABLES, 'fleet_vehicles'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in($table === 'fleet_vehicles' ? 'id' : 'vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		// The trail names its row by id alone, and only the owner writes here.
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_audit')->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
		$this->vehicleIds = [];
	}

	public function testAFirstSyncHandsOverEveryReachableVehicleAndItsLiveRows(): void {
		$vehicle = $this->vehicle();
		$trip = $this->trip($vehicle);
		$expense = $this->expense($vehicle, 6400);

		$answer = $this->sync(self::OWNER);

		$this->assertContains($vehicle->getUuid(), array_column($answer['vehicles'], 'uuid'));
		$this->assertFalse($answer['reset']);
		$this->assertFalse($answer['more']);
		$this->assertSame([], $answer['unreachable']);
		$this->assertSame($trip->getUuid(), $this->item($answer, 'trips', $trip->getUuid())['row']['uuid'] ?? null);
		$synced = $this->item($answer, 'expenses', $expense);
		$this->assertSame($vehicle->getUuid(), $synced['vehicle_uuid']);
		$this->assertSame(6400, $synced['row']['amount'] ?? null);
		// The trip wrote its Reading, which comes as well.
		$this->assertCount(1, $answer['changes']['readings']);
	}

	/** Sync answers a row as its own list route does: a Reading names its Entry, a reminder its estimate. */
	public function testARowComesAsItsListRouteAnswersIt(): void {
		$vehicle = $this->vehicle();
		$trip = $this->trip($vehicle);
		$entry = \OCP\Server::get(OdometerService::class)->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750100000, 'read_at_off' => 120, 'value' => 12500]);
		$reminder = \OCP\Server::get(ReminderService::class)->create(self::OWNER, $vehicle->getUuid(), ['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 135000]);

		$answer = $this->sync(self::OWNER);

		$readings = array_column(array_column($answer['changes']['readings'], 'row'), 'source_uuid', 'value');
		$this->assertSame([12000 => $trip->getUuid(), 12500 => null], $readings);
		$listed = \OCP\Server::get(ReminderService::class)->list(self::OWNER, $vehicle->getUuid());
		$this->assertArrayHasKey('estimate', $listed[0]);
		$synced = $this->item($answer, 'reminders', $reminder['uuid'])['row'];
		$this->assertArrayHasKey('estimate', $synced);
		$this->assertSame($listed[0]['estimate'], $synced['estimate']);
		$this->assertSame($listed[0]['state'], $synced['state']);
	}

	/** The tables whose wire form looks further than the row: what a record closes, who booked, the file. */
	public function testEveryTableComesInTheFormItsWriteAnswers(): void {
		$vehicle = $this->vehicle();
		$uuid = $vehicle->getUuid();
		$fillUp = \OCP\Server::get(EnergyService::class)->record(self::OWNER, $uuid, ['filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000, 'total' => 7350, 'full_tank' => true]);
		$oil = \OCP\Server::get(ReminderService::class)->create(self::OWNER, $uuid, ['template_key' => 'oil_change', 'due_date' => '2027-03-31', 'due_odo' => 135000]);
		$work = \OCP\Server::get(MaintenanceService::class)->record(self::OWNER, $uuid, ['done_at' => 1750000000, 'done_at_off' => 120, 'odo' => 120450, 'title' => 'Oil change', 'cost' => 18990, 'closes' => $oil['uuid']]);
		$tomorrow = (intdiv(time(), 3600) + 24) * 3600;
		$booking = \OCP\Server::get(BookingService::class)->book(self::OWNER, $uuid, ['starts_at' => $tomorrow, 'starts_at_off' => 120, 'ends_at' => $tomorrow + 3600, 'ends_at_off' => 120]);
		$file = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->newFile('sync-' . bin2hex(random_bytes(4)) . '.pdf', '%PDF-1.4 test');
		[$paper] = \OCP\Server::get(DocumentService::class)->attach(self::OWNER, $uuid, ['file_id' => $file->getId(), 'kind' => 'registration']);

		$answer = $this->sync(self::OWNER);

		$this->assertSame($fillUp, $this->item($answer, 'energy', $fillUp['uuid'])['row']);
		$this->assertSame($oil['uuid'], $work['closes']);
		$this->assertSame($work, $this->item($answer, 'maintenance', $work['uuid'])['row']);
		$this->assertSame($booking, $this->item($answer, 'bookings', $booking['uuid'])['row']);
		$this->assertSame($paper, $this->item($answer, 'documents', $paper['uuid'])['row']);
	}

	public function testAnEditReachesTheNextSync(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$this->expenses->update(self::OWNER, $vehicle->getUuid(), $expense, $this->tokenOf('fleet_expenses', $expense), ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => 7100]);

		$this->assertSame(7100, $this->item($this->sync(self::OWNER, $cursor), 'expenses', $expense)['row']['amount'] ?? null);
	}

	/** A deleted row comes as its tombstone: the uuid, the vehicle and when. */
	public function testADeleteReachesTheNextSyncAsATombstone(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$deleted = $this->expenses->delete(self::OWNER, $vehicle->getUuid(), $expense, $this->tokenOf('fleet_expenses', $expense));

		$this->assertSame([
			'vehicle_uuid' => $vehicle->getUuid(),
			'uuid' => $expense,
			'updated_at' => $deleted['updated_at'],
			'deleted_at' => $deleted['deleted_at'],
			'row' => null,
		], $this->item($this->sync(self::OWNER, $cursor), 'expenses', $expense));
	}

	public function testARestoreReachesTheNextSyncAsTheLiveRow(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$deleted = $this->expenses->delete(self::OWNER, $vehicle->getUuid(), $expense, $this->tokenOf('fleet_expenses', $expense));
		$this->age($vehicle);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$this->expenses->restore(self::OWNER, $vehicle->getUuid(), $expense, $deleted['updated_at'] - 1000);

		$item = $this->item($this->sync(self::OWNER, $cursor), 'expenses', $expense);
		$this->assertNull($item['deleted_at']);
		$this->assertSame(6400, $item['row']['amount'] ?? null);
	}

	/** Rows older than the settle window that nothing touched are not sent again. */
	public function testASecondSyncLeavesOutWhatHasNotChanged(): void {
		$vehicle = $this->vehicle();
		$this->expense($vehicle, 6400);
		$this->trip($vehicle);
		$this->age($vehicle);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$answer = $this->sync(self::OWNER, $cursor);

		$this->assertContains($vehicle->getUuid(), array_column($answer['vehicles'], 'uuid'), 'a vehicle comes on every call');
		$this->assertSame([], array_merge(...array_values($answer['changes'])));
	}

	/**
	 * A row stamped less than 120 seconds before a run began may have committed after it read, so
	 * the next run sends it again; one stamped before that does not come twice.
	 */
	public function testARowStampedInsideTheSettleWindowComesAgain(): void {
		$vehicle = $this->vehicle();
		$young = $this->expense($vehicle, 6400);
		$old = $this->expense($vehicle, 100);
		$this->stamp('fleet_expenses', $young, time() - 90);
		$this->stamp('fleet_expenses', $old, time() - 150);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$this->assertSame([$young], array_column($this->sync(self::OWNER, $cursor)['changes']['expenses'], 'uuid'));
	}

	/**
	 * A Reading entered late flags the one after it, which moves that one's token: the next sync
	 * brings the two, and not the rest of the chain.
	 */
	public function testAChangedReadingBringsTheReadingsWhoseFlagItChanged(): void {
		$vehicle = $this->vehicle();
		$odometer = \OCP\Server::get(OdometerService::class);
		$at = ['read_at_off' => 120];
		$odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750000000, 'value' => 1000] + $at);
		$later = $odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750200000, 'value' => 1200] + $at);
		$odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750400000, 'value' => 1500] + $at);
		$this->expense($vehicle, 6400);
		$this->age($vehicle);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$late = $odometer->record(self::OWNER, $vehicle->getUuid(), ['read_at' => 1750100000, 'value' => 1300] + $at);

		$answer = $this->sync(self::OWNER, $cursor);
		$this->assertEqualsCanonicalizing([$late->getUuid(), $later->getUuid()], array_column($answer['changes']['readings'], 'uuid'));
		$this->assertTrue($this->item($answer, 'readings', $later->getUuid())['row']['flagged']);
		$this->assertSame([], $answer['changes']['expenses']);
	}

	/** Listed as unreachable, and nothing of it comes along. */
	public function testARevokedDriverIsToldTheVehicleIsUnreachable(): void {
		$vehicle = $this->vehicle();
		$this->expense($vehicle, 6400);
		$grant = $this->grant($vehicle, self::DRIVER, 'user');
		$cursor = $this->sync(self::DRIVER)['cursor'];

		\OCP\Server::get(GrantService::class)->revoke(self::OWNER, $vehicle->getUuid(), $grant);

		$answer = $this->sync(self::DRIVER, $cursor);
		$this->assertSame([$vehicle->getUuid()], $answer['unreachable']);
		$this->assertNotContains($vehicle->getUuid(), array_column($answer['vehicles'], 'uuid'));
		$this->assertSame([], array_merge(...array_values($answer['changes'])));
		// Said once: the cursor it answered no longer lists it.
		$this->assertSame([], $this->sync(self::DRIVER, $answer['cursor'])['unreachable']);
	}

	/**
	 * A deleted group takes its grants with it: its former member is told the vehicle is out of
	 * reach, and the owner is handed the grant's tombstone.
	 */
	public function testADeletedGroupsMemberIsToldTheVehicleIsUnreachable(): void {
		$vehicle = $this->vehicle();
		$groups = \OCP\Server::get(IGroupManager::class);
		$groups->createGroup(self::GONE)?->addUser(\OCP\Server::get(IUserManager::class)->get(self::MEMBER) ?? throw new \RuntimeException('no ' . self::MEMBER));
		$grant = $this->grant($vehicle, self::GONE, 'group');
		$member = $this->sync(self::MEMBER)['cursor'];
		$owner = $this->sync(self::OWNER)['cursor'];

		$groups->get(self::GONE)?->delete();

		$this->assertSame([$vehicle->getUuid()], $this->sync(self::MEMBER, $member)['unreachable']);
		$this->assertNotNull($this->item($this->sync(self::OWNER, $owner), 'grants', $grant)['deleted_at']);
	}

	public function testARevokedGroupsMemberIsToldTheVehicleIsUnreachable(): void {
		$vehicle = $this->vehicle();
		$grant = $this->grant($vehicle, self::GROUP, 'group');
		$cursor = $this->sync(self::MEMBER)['cursor'];

		\OCP\Server::get(GrantService::class)->revoke(self::OWNER, $vehicle->getUuid(), $grant);

		$this->assertSame([$vehicle->getUuid()], $this->sync(self::MEMBER, $cursor)['unreachable']);
	}

	/**
	 * A vehicle the client did not hold, granted again or for the first time, comes whole: its
	 * rows kept their `updated_at` while the client could not see them.
	 */
	public function testAVehicleGrantedAgainComesWithEveryLiveRow(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$gone = $this->expense($vehicle, 100);
		$this->expenses->delete(self::OWNER, $vehicle->getUuid(), $gone, $this->tokenOf('fleet_expenses', $gone));
		$this->age($vehicle);
		$cursor = $this->sync(self::DRIVER)['cursor'];

		$this->grant($vehicle, self::DRIVER, 'user');

		$answer = $this->sync(self::DRIVER, $cursor);
		$this->assertContains($vehicle->getUuid(), array_column($answer['vehicles'], 'uuid'));
		$this->assertSame([$expense], array_column($answer['changes']['expenses'], 'uuid'));
		// Only the owner keeps the grants.
		$this->assertSame([], $answer['changes']['grants']);
	}

	/**
	 * A vehicle revoked mid-run is told unreachable, and the client drops it. Granted again before
	 * the run ends, it is not held at its end, so the next run brings it whole.
	 */
	public function testAVehicleRevokedAndGrantedAgainMidRunComesWholeNextRun(): void {
		$regranted = $this->vehicle();
		$kept = $this->vehicle();
		$expenses = [$this->expense($regranted, 100), $this->expense($regranted, 200)];
		foreach ([300, 400, 500] as $amount) {
			$this->expense($kept, $amount);
		}
		$grant = $this->grant($regranted, self::DRIVER, 'user');
		$this->grant($kept, self::DRIVER, 'user');
		$this->age($regranted);
		$this->age($kept);
		$first = $this->sync(self::DRIVER, '', 1);
		$this->assertTrue($first['more']);

		\OCP\Server::get(GrantService::class)->revoke(self::OWNER, $regranted->getUuid(), $grant);
		$revoked = $this->sync(self::DRIVER, $first['cursor'], 1);
		$this->assertSame([$regranted->getUuid()], $revoked['unreachable']);
		$this->assertTrue($revoked['more']);

		$this->grant($regranted, self::DRIVER, 'user');
		$cursor = $revoked['cursor'];
		$pages = 0;
		do {
			$answer = $this->sync(self::DRIVER, $cursor, 1);
			$cursor = $answer['cursor'];
		} while ($answer['more'] && ++$pages < 10);
		$this->assertFalse($answer['more']);

		$this->assertEqualsCanonicalizing($expenses, array_column($this->sync(self::DRIVER, $cursor)['changes']['expenses'], 'uuid'));
	}

	/** A vehicle in the trash is out of reach like a revoked one, and back whole once restored. */
	public function testADeletedVehicleIsUnreachableUntilItsRestoreBringsItBackWhole(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$this->age($vehicle);
		$cursor = $this->sync(self::OWNER)['cursor'];
		$deleted = $this->vehicles->delete(self::OWNER, $vehicle->getUuid(), $vehicle->getUpdatedAt());

		$answer = $this->sync(self::OWNER, $cursor);
		$this->assertSame([$vehicle->getUuid()], $answer['unreachable']);

		$this->vehicles->restore(self::OWNER, $vehicle->getUuid(), $deleted->getUpdatedAt());

		$back = $this->sync(self::OWNER, $answer['cursor']);
		$this->assertContains($vehicle->getUuid(), array_column($back['vehicles'], 'uuid'));
		$this->assertSame([$expense], array_column($back['changes']['expenses'], 'uuid'));
	}

	/** The owner's sync carries who else may use the vehicle, and a revoke as a tombstone. */
	public function testTheOwnerIsHandedTheGrantsAndTheirRevokes(): void {
		$vehicle = $this->vehicle();
		$grant = $this->grant($vehicle, self::DRIVER, 'user');
		$cursor = $this->sync(self::OWNER)['cursor'];
		$this->assertSame(self::DRIVER, $this->item($this->sync(self::OWNER), 'grants', $grant)['row']['grantee'] ?? null);

		\OCP\Server::get(GrantService::class)->revoke(self::OWNER, $vehicle->getUuid(), $grant);

		$this->assertNotNull($this->item($this->sync(self::OWNER, $cursor), 'grants', $grant)['deleted_at']);
	}

	/** Pseudonymised rows keep their `updated_at`, so an erasure resets every client. */
	public function testAnErasureResetsEveryCursorHandedOutBeforeIt(): void {
		$vehicle = $this->vehicle();
		$expense = $this->expense($vehicle, 6400);
		$users = \OCP\Server::get(IUserManager::class);
		$users->createUser(self::ERASED, bin2hex(random_bytes(16)));
		$this->grant($vehicle, self::ERASED, 'user');
		$this->age($vehicle);
		$cursor = $this->sync(self::OWNER)['cursor'];

		$users->get(self::ERASED)?->delete();

		$answer = $this->sync(self::OWNER, $cursor);
		$this->assertTrue($answer['reset']);
		$this->assertSame([$expense], array_column($answer['changes']['expenses'], 'uuid'));
		$this->assertFalse($this->sync(self::OWNER, $answer['cursor'])['reset']);
	}

	/** An account that named no row changes nothing a client holds, so no cursor starts over. */
	public function testAnErasureThatChangedNothingLeavesEveryCursorStanding(): void {
		$this->expense($this->vehicle(), 6400);
		$cursor = $this->sync(self::OWNER)['cursor'];
		$users = \OCP\Server::get(IUserManager::class);
		$users->createUser(self::ERASED, bin2hex(random_bytes(16)));

		$users->get(self::ERASED)?->delete();

		$this->assertFalse($this->sync(self::OWNER, $cursor)['reset']);
	}

	/** Paging hands every row over once in a run, whatever the page size. */
	public function testPagesTogetherHandOverEveryRow(): void {
		$vehicle = $this->vehicle();
		$written = [];
		for ($i = 1; $i <= 5; $i++) {
			$written[] = $this->expense($vehicle, $i * 100);
		}
		$this->trip($vehicle);

		$seen = [];
		$pages = 0;
		$cursor = '';
		do {
			$answer = $this->sync(self::OWNER, $cursor, 2);
			$this->assertLessThanOrEqual(2, count(array_merge(...array_values($answer['changes']))));
			$seen = [...$seen, ...array_column($answer['changes']['expenses'], 'uuid')];
			$cursor = $answer['cursor'];
			$pages++;
		} while ($answer['more'] && $pages < 10);

		$this->assertFalse($answer['more']);
		$this->assertSame(4, $pages, 'five expenses, a trip and its reading, two at a time');
		$this->assertSame($written, $seen);
	}

	/** @return array<string, mixed> the answer */
	private function sync(string $userId, string $cursor = '', int $limit = 500): array {
		return $this->sync->sync($userId, $cursor, $limit);
	}

	/**
	 * @param array<string, mixed> $answer
	 * @return array<string, mixed>
	 */
	private function item(array $answer, string $table, string $uuid): array {
		foreach ($answer['changes'][$table] as $item) {
			if ($item['uuid'] === $uuid) {
				return $item;
			}
		}
		$this->fail("$uuid is not among the $table");
	}

	private function vehicle(): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'S-YN 1']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function trip(Vehicle $vehicle): Trip {
		return \OCP\Server::get(TripService::class)->record(self::OWNER, $vehicle->getUuid(), [
			'started_at' => 1750000000,
			'started_at_off' => 120,
			'ended_at' => 1750005400,
			'ended_at_off' => 120,
			'end_odo' => 12000,
			'category' => Trip::BUSINESS,
		]);
	}

	/** @return string its uuid */
	private function expense(Vehicle $vehicle, int $amount): string {
		return (string)$this->expenses->record(self::OWNER, $vehicle->getUuid(), ['spent_at' => 1750000000, 'spent_at_off' => 120, 'amount' => $amount])['uuid'];
	}

	/** @return string the grant's uuid */
	private function grant(Vehicle $vehicle, string $grantee, string $type): string {
		$list = \OCP\Server::get(GrantService::class)->grant(self::OWNER, $vehicle->getUuid(), ['grantee' => $grantee, 'grantee_type' => $type, 'role' => 'driver']);

		return (string)$list[array_search($grantee, array_column($list, 'grantee'), true)]['uuid'];
	}

	private function tokenOf(string $table, string $uuid): int {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('updated_at')->from($table)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	private function stamp(string $table, string $uuid, int $updatedAt): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update($table)
			->set('updated_at', $qb->createNamedParameter($updatedAt, $qb::PARAM_INT))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		$qb->executeStatement();
	}

	/** Every row of the vehicle a thousand seconds older, well past the settle window. */
	private function age(Vehicle $vehicle): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach (self::TABLES as $table) {
			$qb = $db->getQueryBuilder();
			$qb->update($table)
				// Quoted: Oracle folds a bare name to upper case.
				->set('updated_at', $qb->createFunction($qb->getColumnName('updated_at') . ' - 1000'))
				->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicle->getId(), $qb::PARAM_INT)));
			$qb->executeStatement();
		}
	}
}
