<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<ReminderReceipt>
 */
class ReminderReceiptMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_reminder_receipts', ReminderReceipt::class);
	}

	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/**
	 * Writes the receipt unless there is one: whether this point of this occurrence is still to be
	 * sent to that user on that channel. The caller holds the vehicle, so two overlapping runs
	 * cannot both find none; the unique index is the backstop.
	 *
	 * @return bool true when the receipt is new, so the message is the caller's to send
	 * @throws \OCP\DB\Exception
	 */
	public function claim(Reminder $reminder, string $point, string $channel, string $userId, int $sentAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->eq('reminder_id', $qb->createNamedParameter($reminder->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('occurrence', $qb->createNamedParameter($reminder->getOccurrence(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('point', $qb->createNamedParameter($point)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		if ($found) {
			return false;
		}

		$receipt = new ReminderReceipt();
		$receipt->setReminderId((int)$reminder->getId());
		$receipt->setOccurrence($reminder->getOccurrence());
		$receipt->setPoint($point);
		$receipt->setChannel($channel);
		$receipt->setUserId($userId);
		$receipt->setSentAt($sentAt);
		// The recipient, so an erasure of the account finds what was sent to it.
		$receipt->setCreatedBy($userId);
		$this->insert($receipt);

		return true;
	}

	/**
	 * When the user was last sent anything on that channel, up to now: the digest's once a day. A
	 * receipt dated later came from a clock set ahead, and would stop the mail until that day.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function lastSent(string $userId, string $channel, int $now): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sent_at'))
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->lte('sent_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$last = $result->fetchOne();
		$result->closeCursor();

		return $last === null || $last === false ? null : (int)$last;
	}
}
