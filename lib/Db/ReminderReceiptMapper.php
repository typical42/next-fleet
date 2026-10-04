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
	 * @return ?ReminderReceipt the new receipt, so the message is the caller's to send, and to
	 *                          forget() if the send fails; null when there was one
	 * @throws \OCP\DB\Exception
	 */
	public function claim(Reminder $reminder, string $point, string $channel, string $userId, int $sentAt): ?ReminderReceipt {
		$qb = $this->ofOccurrence('id', $reminder);
		$qb->andWhere($qb->expr()->eq('point', $qb->createNamedParameter($point)))
			->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		if ($found) {
			return null;
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

		return $this->insert($receipt);
	}

	/**
	 * What the reminder's current occurrence has sent, on every channel, oldest first.
	 *
	 * @return list<ReminderReceipt>
	 * @throws \OCP\DB\Exception
	 */
	public function findByOccurrence(Reminder $reminder): array {
		$qb = $this->ofOccurrence('*', $reminder);
		$qb->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Forgets these receipts, so their points are claimed again. A receipt is a fact about sending,
	 * not a record a user wrote: it is removed, not stamped deleted.
	 *
	 * @param list<ReminderReceipt> $receipts
	 * @throws \OCP\DB\Exception
	 */
	public function forget(array $receipts): void {
		if ($receipts === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(InList::in($qb, 'id', array_map(static fn (ReminderReceipt $r): int => (int)$r->getId(), $receipts), IQueryBuilder::PARAM_INT_ARRAY));
		$qb->executeStatement();
	}

	/**
	 * Makes the receipt's point claimable again and keeps its `sent_at`: a mail that went out still
	 * counts for the day (lastSent()). The caller forgets an older retired receipt of the same point
	 * first, or the unique index refuses.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function retire(ReminderReceipt $receipt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('point', $qb->createNamedParameter(ReminderReceipt::MOVED . $receipt->getPoint()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($receipt->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function ofOccurrence(string $columns, Reminder $reminder): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select($columns)
			->from($this->tableName)
			->where($qb->expr()->eq('reminder_id', $qb->createNamedParameter($reminder->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('occurrence', $qb->createNamedParameter($reminder->getOccurrence(), IQueryBuilder::PARAM_INT)));

		return $qb;
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
