<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderReceipt;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\TestCase;

/**
 * What M4's columns mean when they come back from the database, before any service reads them.
 */
class M4EntitiesTest extends TestCase {
	public function testANewReminderWarnsAMonthBeforeAndOnTheDueDate(): void {
		$reminder = new Reminder();

		$this->assertTrue($reminder->getWarnMonthBefore());
		$this->assertFalse($reminder->getWarnMonthStart());
		$this->assertTrue($reminder->getWarnDueDate());
		$this->assertSame(1, $reminder->getOccurrence());
	}

	public function testAnUnansweredWarningPointReadsAsItsDefault(): void {
		$reminder = Reminder::fromRow([
			'warn_month_before' => null, 'warn_month_start' => null, 'warn_due_date' => null,
		]);

		$this->assertTrue($reminder->getWarnMonthBefore());
		$this->assertFalse($reminder->getWarnMonthStart());
		$this->assertTrue($reminder->getWarnDueDate());
	}

	public function testAnUntickedWarningPointStaysUnticked(): void {
		$reminder = Reminder::fromRow(['warn_month_before' => '0', 'warn_due_date' => '0', 'warn_month_start' => '1']);

		$this->assertFalse($reminder->getWarnMonthBefore());
		$this->assertTrue($reminder->getWarnMonthStart());
		$this->assertFalse($reminder->getWarnDueDate());
	}

	public function testAReminderSerializesItsColumns(): void {
		$reminder = Reminder::fromRow([
			'uuid' => 'r', 'template_key' => 'oil_change', 'title' => null, 'mode' => Reminder::EITHER,
			'due_date' => '2027-03-31', 'due_odo' => '65000', 'lead_odo' => '1000',
			'warn_month_before' => '1', 'warn_month_start' => '0', 'warn_due_date' => '1',
			'recur_months' => '12', 'recur_odo' => '15000', 'state' => Reminder::PLANNED,
			'snoozed_until' => null, 'occurrence' => '3',
		]);

		$json = $reminder->jsonSerialize();
		$this->assertSame('oil_change', $json['template_key']);
		$this->assertNull($json['title']);
		$this->assertSame('either', $json['mode']);
		// A plain calendar date goes out as one, not as an instant.
		$this->assertSame('2027-03-31', $json['due_date']);
		$this->assertSame(65000, $json['due_odo']);
		$this->assertSame(1000, $json['lead_odo']);
		$this->assertTrue($json['warn_month_before']);
		$this->assertFalse($json['warn_month_start']);
		$this->assertSame(12, $json['recur_months']);
		$this->assertSame(15000, $json['recur_odo']);
		$this->assertSame('planned', $json['state']);
		$this->assertNull($json['snoozed_until']);
		$this->assertSame(3, $json['occurrence']);
	}

	public function testAReceiptReadsItsIntegersAsIntegers(): void {
		$receipt = ReminderReceipt::fromRow([
			'reminder_id' => '7', 'occurrence' => '2', 'point' => 'overdue', 'channel' => ReminderReceipt::MAIL,
			'user_id' => 'alice', 'sent_at' => '1758240000',
		]);

		$this->assertSame(7, $receipt->getReminderId());
		$this->assertSame(2, $receipt->getOccurrence());
		$this->assertSame('mail', $receipt->getChannel());
		$this->assertSame(1758240000, $receipt->getSentAt());
	}

	public function testARecipientIsAUserOnAVehicle(): void {
		$recipient = ReminderRecipient::fromRow(['vehicle_id' => '4', 'user_id' => 'alice']);

		$this->assertSame(4, $recipient->getVehicleId());
		$this->assertSame('alice', $recipient->getUserId());
	}

	public function testAVehicleMailsWeeklyUntilToldOtherwise(): void {
		$this->assertSame(Vehicle::MAIL_WEEKLY, (new Vehicle())->getReminderMail());
		$this->assertSame('monthly', Vehicle::fromRow(['reminder_mail' => 'monthly'])->jsonSerialize()['reminder_mail']);
	}

	public function testAMaintenanceRecordNamesTheReminderItClosed(): void {
		$closing = Maintenance::fromRow(['reminder_id' => '9']);

		$this->assertSame(9, $closing->getReminderId());
		$this->assertNull((new Maintenance())->getReminderId());
		// The wire form names the reminder by uuid once a service knows it; the id stays inside.
		$this->assertArrayNotHasKey('reminder_id', $closing->jsonSerialize());
	}
}
