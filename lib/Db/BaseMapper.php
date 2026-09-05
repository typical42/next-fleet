<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCA\NextFleet\Exception\StaleUpdateException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Everything the five common columns imply, in one place: identity, the server's clock, soft
 * delete and optimistic concurrency.
 *
 * @template T of BaseEntity
 * @template-extends QBMapper<T>
 */
abstract class BaseMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private ITimeFactory $time,
		private ISecureRandom $random,
		string $tableName,
		?string $entityClass = null,
	) {
		parent::__construct($db, $tableName, $entityClass);
	}

	/**
	 * Stamps the row's identity and its dating. A uuid the entity already carries is kept - an
	 * import brings its own - but `created_by` has to be there, and `created_at` is the
	 * server's whatever the caller put in it.
	 *
	 * @param T $entity
	 * @return T
	 * @throws \InvalidArgumentException if the row has no author
	 * @throws \OCP\DB\Exception
	 */
	public function insert(Entity $entity): Entity {
		if ($entity->getCreatedBy() === '') {
			// The mapper has no session to ask, and a row with no author is one an audit or
			// an erasure can no longer place.
			throw new \InvalidArgumentException($this->tableName . ' row has no created_by');
		}

		if ($entity->getUuid() === '') {
			$entity->setUuid($this->newUuid());
		}

		$now = $this->time->getTime();
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);

		return parent::insert($entity);
	}

	/**
	 * Writes the entity back to the row the client read, and only to it: the statement carries
	 * the `updated_at` that came with the request, so a write that lost the race matches nothing
	 * (docs/architecture.md#concurrency).
	 *
	 * Identity and provenance - `uuid`, `created_at`, `created_by` - are not writable here,
	 * however dirty the entity is.
	 *
	 * @param T $entity
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return T
	 * @throws StaleUpdateException if the row has changed, or has been soft-deleted, since
	 * @throws \OCP\DB\Exception
	 */
	public function updateChecked(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException('Entity which should be updated has no id');
		}

		// The token has to move even when both writes land in the same second, or the second
		// one is a lost update wearing a token that still looks fresh.
		$now = max($this->time->getTime(), $expectedUpdatedAt + 1);
		$entity->setUpdatedAt($now);

		$properties = $entity->getUpdatedFields();
		unset($properties['id'], $properties['uuid'], $properties['createdAt'], $properties['createdBy'], $properties['updatedAt']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName);
		foreach (array_keys($properties) as $property) {
			$getter = 'get' . ucfirst($property);
			$qb->set(
				$entity->propertyToColumn($property),
				$qb->createNamedParameter($entity->$getter(), $this->getParameterTypeForProperty($entity, $property)),
			);
		}
		// Written unconditionally: the statement is the check, and one with nothing to set is
		// not a statement at all.
		$qb->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($qb->expr()->eq(
			'updated_at',
			$qb->createNamedParameter($expectedUpdatedAt, IQueryBuilder::PARAM_INT),
		));
		$qb->andWhere($qb->expr()->isNull('deleted_at'));

		if ($qb->executeStatement() === 0) {
			throw new StaleUpdateException(
				$this->tableName . ' row ' . $id . ' has changed since it was read',
			);
		}

		$entity->resetUpdatedFields();

		return $entity;
	}

	/**
	 * QBMapper's update writes on `id` alone and leaves `updated_at` where it was, which
	 * hands the next stale write a token that still passes. Use updateChecked().
	 *
	 * @param T $entity
	 * @return T
	 */
	public function update(Entity $entity): Entity {
		throw new \BadMethodCallException(static::class . '::update() is unguarded, use updateChecked()');
	}

	/**
	 * @param T $entity
	 * @return T
	 */
	public function insertOrUpdate(Entity $entity): Entity {
		throw new \BadMethodCallException(static::class . '::insertOrUpdate() is unguarded, use insert() or updateChecked()');
	}

	/**
	 * Rows are never removed: the trash and a GDPR erasure both need to find them.
	 *
	 * @param T $entity
	 * @return T
	 */
	public function delete(Entity $entity): Entity {
		throw new \BadMethodCallException(static::class . '::delete() removes the row, use softDelete()');
	}

	/**
	 * The uuid is the identity; a soft-deleted row is out of reach of a read.
	 *
	 * @return T
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function findByUuid(string $uuid): BaseEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntity($qb);
	}

	/**
	 * Deleting is stamping `deleted_at`: the row stays for the trash and for an erasure to
	 * purge, and the write is checked like any other.
	 *
	 * @param T $entity
	 * @param int $expectedUpdatedAt the `updated_at` the client read
	 * @return T
	 * @throws StaleUpdateException if the row has changed, or has been soft-deleted, since
	 * @throws \OCP\DB\Exception
	 */
	public function softDelete(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		$entity->setDeletedAt($this->time->getTime());

		return $this->updateChecked($entity, $expectedUpdatedAt);
	}

	/**
	 * A uuid the database never sees twice, from the server's CSPRNG.
	 */
	private function newUuid(): string {
		$hex = $this->random->generate(32, '0123456789abcdef');

		// RFC 4122 version 4: the version nibble is 4, the variant nibble one of 8-b.
		$hex[12] = '4';
		$hex[16] = '89ab'[hexdec($hex[16]) % 4];

		return implode('-', [
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		]);
	}
}
