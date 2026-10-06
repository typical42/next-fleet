<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\UserMigration;

use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\UserMigration\FleetMigrator;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * One migrator exports every account `occ user:export` names in a process, so what one account
 * may see must not decide another's export (Art. 15(4) GDPR).
 */
class FleetMigratorTest extends TestCase {
	public function testEachAccountGetsItsOwnAnswerToWhetherItSeesTheVehicle(): void {
		$migrator = $this->migrator(sees: ['ann']);

		$this->assertSame('Oil', $this->export($migrator, 'ann')['title'] ?? null);
		$this->assertArrayHasKey('withheld', $this->export($migrator, 'bea'));
	}

	public function testAnAccountThatStillSeesTheVehicleGetsItWholeAfterOneThatLostIt(): void {
		$migrator = $this->migrator(sees: ['ann']);

		$this->assertArrayHasKey('withheld', $this->export($migrator, 'bea'));
		$this->assertSame('Oil', $this->export($migrator, 'ann')['title'] ?? null);
	}

	/**
	 * Every account wrote one reminder on vehicle 7.
	 *
	 * @param list<string> $sees the accounts that may still see vehicle 7
	 */
	private function migrator(array $sees): FleetMigrator {
		$reminders = $this->createMock(ReminderMapper::class);
		$reminders->method('getTableName')->willReturn('fleet_reminders');
		$reminders->method('findNaming')->willReturnCallback(static fn (string $uid): array => [
			Reminder::fromRow(['uuid' => 'r-' . $uid, 'vehicle_id' => 7, 'created_by' => $uid, 'title' => 'Oil']),
		]);
		$tables = $this->createMock(AccountTables::class);
		$tables->method('all')->willReturn([$reminders]);
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findAnyById')->willReturn(Vehicle::fromRow(['id' => 7, 'uuid' => 'v-7', 'user_id' => 'owner']));
		$access = $this->createMock(VehicleAccess::class);
		$access->method('may')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $sees, true));

		return new FleetMigrator($tables, $vehicles, $this->createMock(TripMapper::class), $access, $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class));
	}

	/** @return array<string, mixed> the one reminder row in the account's export */
	private function export(FleetMigrator $migrator, string $uid): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$files = [];
		$destination = $this->createMock(IExportDestination::class);
		$destination->method('addFileContents')->willReturnCallback(static function (string $path, string $content) use (&$files): void {
			$files[$path] = $content;
		});

		$migrator->export($user, $destination, new NullOutput());

		return json_decode($files['nextfleet/fleet_reminders.json'], true, flags: JSON_THROW_ON_ERROR)[0];
	}
}
