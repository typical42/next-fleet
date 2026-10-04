<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

/**
 * Every table that names an account. An erasure rewrites them and the personal data export reads
 * them, so a table added here reaches both.
 */
class AccountTables {
	/** @var list<BaseMapper> */
	private array $all;

	public function __construct(
		VehicleMapper $vehicles,
		AccessMapper $access,
		OdoReadingMapper $readings,
		TripMapper $trips,
		EnergyMapper $energy,
		MaintenanceMapper $maintenance,
		ExpenseMapper $expenses,
		ReminderMapper $reminders,
		ReminderReceiptMapper $receipts,
		ReminderRecipientMapper $recipients,
		DocumentMapper $documents,
		AuditMapper $audit,
		BookingMapper $bookings,
	) {
		$this->all = [$vehicles, $access, $readings, $trips, $energy, $maintenance, $expenses, $reminders, $receipts, $recipients, $documents, $audit, $bookings];
	}

	/** @return list<BaseMapper> */
	public function all(): array {
		return $this->all;
	}
}
