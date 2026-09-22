<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceipt;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Migration\Version000004Date20260922000000;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * M4's migration beyond its schema: the vehicles already there get their owner as the one
 * recipient, and M4's rows go through their mappers and back.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class ReminderMigrationTest extends TestCase {
	private const OWNER = 'nextfleet-test-reminders';

	private VehicleService $vehicles;
	private ReminderRecipientMapper $recipients;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->vehicles = $container->get(VehicleService::class);
		$this->recipients = $container->get(ReminderRecipientMapper::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		foreach ([
			'fleet_vehicles' => 'user_id', 'fleet_reminder_recipients' => 'user_id',
			'fleet_reminders' => 'created_by', 'fleet_reminder_receipts' => 'created_by',
		] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OWNER)));
			$qb->executeStatement();
		}
	}

	private function migrate(): void {
		(new Application())->getContainer()->get(Version000004Date20260922000000::class)
			->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	/** @return list<string> */
	private function recipientsOf(int $vehicleId): array {
		return array_map(
			static fn (ReminderRecipient $recipient): string => $recipient->getUserId(),
			$this->recipients->findByVehicle($vehicleId),
		);
	}

	public function testAVehicleAlreadyThereGetsItsOwnerAsTheOneRecipient(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 1']);

		$this->migrate();

		$this->assertSame([self::OWNER], $this->recipientsOf($vehicle->getId()));
	}

	/** A re-install runs the step again, and must not hand the owner a second row. */
	public function testRunningTheStepAgainAddsNoSecondRecipient(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 2']);

		$this->migrate();
		$this->migrate();

		$this->assertSame([self::OWNER], $this->recipientsOf($vehicle->getId()));
	}

	public function testTheDatabaseRefusesTheSameRecipientTwice(): void {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'B-RM 3']);
		$this->migrate();

		$again = new ReminderRecipient();
		$again->setVehicleId($vehicle->getId());
		$again->setUserId(self::OWNER);
		$again->setCreatedBy(self::OWNER);

		$this->expectException(Exception::class);
		$this->recipients->insert($again);
	}

	public function testAReminderRoundTrips(): void {
		$reminder = new Reminder();
		$reminder->setVehicleId(1);
		$reminder->setTemplateKey('oil_change');
		$reminder->setMode(Reminder::EITHER);
		$reminder->setDueDate('2027-03-31');
		$reminder->setDueOdo(65000);
		$reminder->setLeadOdo(1000);
		$reminder->setWarnMonthStart(true);
		$reminder->setWarnDueDate(false);
		$reminder->setRecurMonths(12);
		$reminder->setRecurOdo(15000);
		$reminder->setState(Reminder::PLANNED);
		$reminder->setCreatedBy(self::OWNER);

		$mapper = (new Application())->getContainer()->get(ReminderMapper::class);
		$written = $mapper->insert($reminder);
		$read = $mapper->findByUuid($written->getUuid());

		$this->assertSame($written->jsonSerialize(), $read->jsonSerialize());
		$this->assertTrue($read->getWarnMonthBefore());
		$this->assertFalse($read->getWarnDueDate());
		$this->assertSame(1, $read->getOccurrence());
	}

	/** One receipt per point, occurrence, channel and recipient, however often the job runs. */
	public function testTheDatabaseRefusesASecondReceiptForTheSameSend(): void {
		$mapper = (new Application())->getContainer()->get(ReminderReceiptMapper::class);
		$receipt = function (string $channel): ReminderReceipt {
			$receipt = new ReminderReceipt();
			$receipt->setReminderId(1);
			$receipt->setOccurrence(1);
			$receipt->setPoint('overdue');
			$receipt->setChannel($channel);
			$receipt->setUserId(self::OWNER);
			$receipt->setSentAt(1758240000);
			$receipt->setCreatedBy(self::OWNER);

			return $receipt;
		};

		$mapper->insert($receipt(ReminderReceipt::APP));
		$mapper->insert($receipt(ReminderReceipt::MAIL));

		$this->expectException(Exception::class);
		$mapper->insert($receipt(ReminderReceipt::APP));
	}
}
