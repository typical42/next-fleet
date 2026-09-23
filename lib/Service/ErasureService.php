<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\AuditMapper;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * What a deleted account leaves behind (docs/adr/0008-erasing-a-driver-pseudonymises.md): its
 * uid replaced on every row and no row deleted, except the reminder lists it was on.
 */
class ErasureService {
	use TTransactional;

	/** @var list<BaseMapper> */
	private array $tables;

	public function __construct(
		private IDBConnection $db,
		private ISecureRandom $random,
		private ReminderRecipientMapper $recipients,
		VehicleMapper $vehicles,
		AccessMapper $access,
		OdoReadingMapper $readings,
		TripMapper $trips,
		EnergyMapper $energy,
		MaintenanceMapper $maintenance,
		ExpenseMapper $expenses,
		ReminderMapper $reminders,
		ReminderReceiptMapper $receipts,
		DocumentMapper $documents,
		AuditMapper $audit,
	) {
		$this->tables = [$vehicles, $access, $readings, $trips, $energy, $maintenance, $expenses, $reminders, $receipts, $recipients, $documents, $audit];
	}

	/**
	 * One pseudonym per erasure, so the owner can still tell one former driver's trips from
	 * another's. Random rather than a hash of the uid: a uid is guessable, and a hash of it is
	 * the uid again to anyone who tries. No vehicle is held: the uid is on no row a hold orders.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function erase(string $uid): void {
		$pseudonym = 'erased-' . $this->random->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

		$this->atomic(function () use ($uid, $pseudonym): void {
			$this->recipients->deleteAccount($uid);
			foreach ($this->tables as $table) {
				$table->pseudonymise($uid, $pseudonym);
			}
		}, $this->db);
	}
}
