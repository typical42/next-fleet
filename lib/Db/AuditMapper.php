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
	 * The trail of one row, oldest first. `id` alone orders it: the rows are only ever inserted,
	 * so the key is the order they were written in, and two changes in the same second still
	 * come back the way they happened.
	 *
	 * A soft-deleted audit row is not filtered out, because nothing may write one. The column is
	 * there because every table has it.
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
}
