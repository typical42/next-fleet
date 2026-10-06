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
 * @template-extends BaseMapper<Audit>
 */
class AuditMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_audit', Audit::class);
	}

	/**
	 * The trail is append-only (docs/features.md#logbook-mode), so the three writes the base
	 * mapper offers every other table are shut here. A row that can be corrected afterwards
	 * records nothing an auditor can use.
	 *
	 * @param Audit $entity
	 * @return Audit
	 */
	public function updateChecked(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		throw new \BadMethodCallException(static::class . '::updateChecked() would edit the audit trail');
	}

	/**
	 * @param Audit $entity
	 * @return Audit
	 */
	public function softDelete(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		throw new \BadMethodCallException(static::class . '::softDelete() would hide an audit row');
	}

	/**
	 * @param Audit $entity
	 * @return Audit
	 */
	public function restoreChecked(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		throw new \BadMethodCallException(static::class . '::restoreChecked() undoes a delete nothing may do');
	}

	/**
	 * A transfer's diff names the owners it moved the vehicle between, so the uid goes there
	 * too. Rewriting a row of an append-only trail is what an erasure is; nothing else does it.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function pseudonymise(string $uid, string $pseudonym): int {
		$changed = parent::pseudonymise($uid, $pseudonym);

		$transfers = $this->transfersNaming($uid);
		foreach ($transfers as $row) {
			$diff = $row->getDiffJson();
			/** @var list<mixed> $owners transfersNaming() saw the list */
			$owners = $diff['fields']['user_id'];
			$diff['fields']['user_id'] = array_map(static fn (mixed $owner): mixed => $owner === $uid ? $pseudonym : $owner, $owners);
			$update = $this->db->getQueryBuilder();
			$update->update($this->tableName)
				->set('diff_json', $update->createNamedParameter($diff, IQueryBuilder::PARAM_JSON))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row->getId(), IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}

		return $changed + count($transfers);
	}

	/**
	 * A transfer that moved a vehicle to or from the account as well, for the reason
	 * pseudonymise() gives. That reads every transfer row, which an admin writes rarely.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function names(string $uid): bool {
		return parent::names($uid) || $this->transfersNaming($uid) !== [];
	}

	/**
	 * A transfer that moved a vehicle to or from the account as well, for the reason
	 * pseudonymise() gives.
	 *
	 * @return list<Audit>
	 * @throws \OCP\DB\Exception
	 */
	public function findNaming(string $uid): array {
		$rows = [...parent::findNaming($uid), ...$this->transfersNaming($uid)];
		usort($rows, static fn (Audit $a, Audit $b): int => $a->getId() <=> $b->getId());

		return $rows;
	}

	/**
	 * @return list<Audit>
	 * @throws \OCP\DB\Exception
	 */
	private function transfersNaming(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('entity', $qb->createNamedParameter(Audit::VEHICLE)))
			->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter(Audit::TRANSFERRED_BY)));

		return array_values(array_filter($this->findEntities($qb), static function (Audit $row) use ($uid): bool {
			$owners = $row->getDiffJson()['fields']['user_id'] ?? null;

			return is_array($owners) && in_array($uid, $owners, true);
		}));
	}

	/**
	 * The trail of one row, oldest first. `id` alone orders it: the rows are only ever inserted,
	 * so the key is the order they were written in, and two changes in the same second still
	 * come back the way they happened.
	 *
	 * `deleted_at` is not filtered: nothing may set it, and the column is there only because every
	 * table has it.
	 *
	 * @return list<Audit>
	 * @throws \OCP\DB\Exception
	 */
	public function findForEntity(string $entity, int $entityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One vehicle's whole trail, its own rows and its trips', oldest first by findForEntity()'s
	 * rule (`occ nextfleet:audit`). A trip is never hard-deleted, so the join finds every one; a
	 * voided trip's rows are in it.
	 *
	 * @param int $since only rows written at or after this instant
	 * @return list<Audit>
	 * @throws \OCP\DB\Exception
	 */
	public function findForVehicle(int $vehicleId, int $since = 0): array {
		// Two queries, not one OR across a join: each half has its index, the OR would scan the table.
		$own = $this->db->getQueryBuilder();
		$own->select('*')
			->from($this->tableName)
			->where($own->expr()->eq('entity', $own->createNamedParameter(Audit::VEHICLE)))
			->andWhere($own->expr()->eq('entity_id', $own->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($own->expr()->gte('created_at', $own->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

		$trips = $this->db->getQueryBuilder();
		$trips->select('a.*')
			->from('fleet_trips', 't')
			->innerJoin('t', $this->tableName, 'a', $trips->expr()->andX(
				$trips->expr()->eq('a.entity', $trips->createNamedParameter(Audit::TRIP)),
				$trips->expr()->eq('a.entity_id', 't.id'),
			))
			->where($trips->expr()->eq('t.vehicle_id', $trips->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($trips->expr()->gte('a.created_at', $trips->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

		$rows = [...$this->findEntities($own), ...$this->findEntities($trips)];
		usort($rows, static fn (Audit $a, Audit $b): int => $a->getId() <=> $b->getId());

		return $rows;
	}

	/**
	 * The trails of many rows of one table, each oldest first, so a year's export asks once per
	 * InList chunk of trips rather than once per trip.
	 *
	 * @param list<int> $entityIds
	 * @return list<Audit>
	 * @throws \OCP\DB\Exception
	 */
	public function findForEntities(string $entity, array $entityIds): array {
		$rows = [];
		foreach (InList::chunks($entityIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->tableName)
				->where($qb->expr()->eq('entity', $qb->createNamedParameter($entity)))
				->andWhere(InList::in($qb, 'entity_id', $chunk, IQueryBuilder::PARAM_INT_ARRAY))
				->orderBy('id', 'ASC');
			array_push($rows, ...$this->findEntities($qb));
		}

		return $rows;
	}
}
