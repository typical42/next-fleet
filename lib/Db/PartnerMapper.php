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
 * @template-extends BaseMapper<Partner>
 */
class PartnerMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_partners', Partner::class);
	}

	/** `user_id` is the owner whose list it is. */
	protected function accountColumns(): array {
		return ['user_id', 'created_by'];
	}

	/** How many trips findLinkedByText() reads at once: it stops at the first that matches. */
	private const PAGE = 100;

	/**
	 * The live record of this name in the owner's list. The name and the owner match byte for
	 * byte, as the backfill of step 13 groups names: a column of a case- or accent-blind collation
	 * hands back every spelling it counts as one, and a variant spelling is its own record.
	 *
	 * @param string $name as Partner::nameOf() gives it
	 * @throws \OCP\DB\Exception
	 */
	public function findByName(string $userId, string $name): ?Partner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		foreach ($this->findEntities($qb) as $partner) {
			if ($partner->getName() === $name && $partner->getUserId() === $userId) {
				return $partner;
			}
		}

		return null;
	}

	/**
	 * Deletes the owner's whole list as PartnerService::delete() takes one record: the name, the
	 * address and the note go, and the rows stay as tombstones that each trip keeps as its mark
	 * (Trip::linkPartner()). The trips themselves are not touched, as an erasure moves no token
	 * (BaseMapper::pseudonymise()). The records' tokens need not pass the old ones either: the
	 * erasure renames these rows, so every sync starts over (ErasureService::erase()).
	 *
	 * @return int how many live records it deleted
	 * @throws \OCP\DB\Exception
	 */
	public function eraseListOf(string $userId): int {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$deleted = $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('name', $qb->createNamedParameter(Partner::ERASED))
			->set('address', $qb->createNamedParameter(null))
			->set('note', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		return $deleted;
	}

	/**
	 * Records by id, deleted ones too, keyed by id. One query per InList chunk: a vehicle's trips
	 * can name any number.
	 *
	 * @param list<int> $ids
	 * @return array<int, Partner>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyOf(array $ids): array {
		$byId = [];
		foreach (InList::chunks($ids) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->tableName)
				->where(InList::in($qb, 'id', $chunk, IQueryBuilder::PARAM_INT_ARRAY));
			foreach ($this->findEntities($qb) as $partner) {
				$byId[(int)$partner->getId()] = $partner;
			}
		}

		return $byId;
	}

	/**
	 * The owner's live list, by name.
	 *
	 * @return list<Partner>
	 * @throws \OCP\DB\Exception
	 */
	public function findOf(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('name')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * What a sync hands over of these owners' lists (docs/api.md#sync): every live record, or with
	 * `$since`, every record changed after it, a deleted one included. Oldest change first.
	 *
	 * @param list<string> $userIds
	 * @return list<Partner>
	 * @throws \OCP\DB\Exception
	 */
	public function findChangedOf(array $userIds, ?int $since): array {
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(InList::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($since === null
				? $qb->expr()->isNull('deleted_at')
				: $qb->expr()->gt('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * The names of the owner's live list, by name. Names only: whoever logs on one of the owner's
	 * vehicles may read them, and the address and the note are the owner's alone.
	 *
	 * @return array<int, string> by record id
	 * @throws \OCP\DB\Exception
	 */
	public function findNamesOf(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from($this->tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('name')
			->addOrderBy('id');
		$result = $qb->executeQuery();
		$names = [];
		while (($row = $result->fetch()) !== false) {
			$names[(int)$row['id']] = (string)$row['name'];
		}
		$result->closeCursor();

		return $names;
	}

	/**
	 * The live record in the owner's list that the latest live trip with one of these texts links:
	 * a spelling the record lost to a rename or a merge still finds it. The text and the owner
	 * match byte for byte, for the reason findByName() gives. The trips come in pages: the driver
	 * buffers a whole result, and a renamed record may keep thousands of them.
	 *
	 * @param non-empty-list<string> $texts
	 * @throws \OCP\DB\Exception
	 */
	public function findLinkedByText(string $userId, array $texts): ?Partner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.*')
			->selectAlias('t.partner', 'trip_partner')
			->from($this->tableName, 'p')
			->innerJoin('p', 'fleet_trips', 't', $qb->expr()->eq('t.partner_id', 'p.id'))
			->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('p.deleted_at'))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->andWhere(InList::in($qb, 't.partner', $texts, IQueryBuilder::PARAM_STR_ARRAY))
			->orderBy('t.started_at', 'DESC')
			->addOrderBy('t.id', 'DESC')
			->setMaxResults(self::PAGE);

		for ($offset = 0; ; $offset += self::PAGE) {
			$result = $qb->setFirstResult($offset)->executeQuery();
			$read = 0;
			try {
				while (($row = $result->fetch()) !== false) {
					$read++;
					if (in_array($row['trip_partner'], $texts, true) && $row['user_id'] === $userId) {
						unset($row['trip_partner']);

						return $this->mapRowToEntity($row);
					}
				}
			} finally {
				$result->closeCursor();
			}
			if ($read < self::PAGE) {
				return null;
			}
		}
	}
}
