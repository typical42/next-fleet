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
	/** Read once per process, on Oracle only (tablePrefix()): one installation, one prefix. */
	private static ?string $tablePrefix = null;

	public function __construct(
		IDBConnection $db,
		protected ITimeFactory $time,
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
		$entity->markEveryColumnWritten();
		if ($entity->getId() === null) {
			$this->takeOracleId($entity);
		}

		return parent::insert($entity);
	}

	/**
	 * Doctrine names an Oracle table's id sequence `<table>_SEQ`, the table cut so the whole fits
	 * 30 characters. Core's lastInsertId() asks for the uncut name, which then does not exist
	 * (`fleet_reminder_recipients`). Such a table takes the id from the sequence before the
	 * insert, so nobody asks after it; the trigger keeps an id it is given. The naming rule is
	 * written out because the platform that knows it is behind getDatabasePlatform(), deprecated
	 * since NC 30; the Oracle stack's SchemaTest fails if the two part.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function takeOracleId(Entity $entity): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_ORACLE) {
			return;
		}
		$table = $this->tablePrefix() . $this->tableName;
		if (strlen($table . '_SEQ') <= 30) {
			return;
		}
		$result = $this->db->executeQuery('SELECT "' . substr($table, 0, 26) . '_SEQ".NEXTVAL FROM DUAL');
		$entity->setId((int)$result->fetchOne());
		$result->closeCursor();
	}

	/**
	 * The prefix itself, which prefixTableName() leaves as `*PREFIX*`. Core swaps that anywhere
	 * in a statement, a literal too; asking keeps IConfig out of every mapper's constructor.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function tablePrefix(): string {
		if (self::$tablePrefix === null) {
			$result = $this->db->executeQuery("SELECT '*PREFIX*' FROM DUAL");
			self::$tablePrefix = (string)$result->fetchOne();
			$result->closeCursor();
		}

		return self::$tablePrefix;
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
		$qb = $this->byUuid($uuid);
		$qb->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntity($qb);
	}

	/**
	 * The same row whatever state it is in. A restore is the one caller that wants it: the row it
	 * is after is precisely the one findByUuid() passes over, and a restore of a row somebody
	 * already brought back has to say "not as you read it" rather than "no such thing".
	 *
	 * @return T
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyByUuid(string $uuid): BaseEntity {
		return $this->findEntity($this->byUuid($uuid));
	}

	/**
	 * findByUuid(), and only where the row hangs off the vehicle the route named. A uuid alone
	 * would be a second way in: one vehicle of their own is all somebody would need to reach a row
	 * on anybody else's. For the tables with a `vehicle_id`, which is every one but the vehicles'.
	 *
	 * @return T
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function findOnVehicle(int $vehicleId, string $uuid): BaseEntity {
		$qb = $this->byUuid($uuid);
		$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($qb->expr()->isNull('deleted_at'));

		return $this->findEntity($qb);
	}

	/**
	 * findAnyByUuid() under findOnVehicle()'s condition, for a restore.
	 *
	 * @return T
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyOnVehicle(int $vehicleId, string $uuid): BaseEntity {
		$qb = $this->byUuid($uuid);
		$qb->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * One page of one vehicle's rows, newest first: the live ones strictly before `($at, $id)` in
	 * `($instant, id)` order, which is the order the timeline reads in reverse
	 * (docs/architecture.md#the-timeline). The tie-break is `id` because two rows can carry the
	 * same instant and a page boundary that is not a total order drops or repeats one.
	 *
	 * The query rather than the rows: which of a table's rows are timeline rows at all is the
	 * table's own business, and it is one `andWhere` away.
	 */
	protected function pageBefore(string $instant, int $vehicleId, int $at, int $id, int $limit): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt($instant, $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq($instant, $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy($instant, 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		return $qb;
	}

	/**
	 * One vehicle's live rows whose `$instant` falls in `[from, to)`, oldest first - a period a
	 * figure is computed over (docs/architecture.md#numbers-consumption-cost-emissions).
	 */
	protected function between(string $instant, int $vehicleId, int $from, int $to): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte($instant, $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt($instant, $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->orderBy($instant, 'ASC')
			->addOrderBy('id', 'ASC');

		return $qb;
	}

	/**
	 * One page of what a sync hands over from this table (docs/api.md#sync), oldest change first:
	 * every live row of a vehicle the client does not hold yet, and every row of one it holds that
	 * changed after `$since`, a deleted one included. Only rows past `($afterAt, $afterId)` in
	 * (`updated_at`, `id`) order, none at `$afterAt` when `$afterId` is null.
	 *
	 * @param list<int> $whole vehicles whose live rows all come
	 * @param list<int> $held vehicles whose rows come when changed after `$since`
	 * @return list<T>
	 * @throws \OCP\DB\Exception
	 */
	public function findChanged(array $whole, array $held, int $since, int $afterAt, ?int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		// An empty IN () is not a predicate any of the three databases accepts.
		$wanted = [];
		if ($whole !== []) {
			$wanted[] = $qb->expr()->andX(
				InList::in($qb, 'vehicle_id', $whole, IQueryBuilder::PARAM_INT_ARRAY),
				$qb->expr()->isNull('deleted_at'),
			);
		}
		if ($held !== []) {
			$wanted[] = $qb->expr()->andX(
				InList::in($qb, 'vehicle_id', $held, IQueryBuilder::PARAM_INT_ARRAY),
				$qb->expr()->gt('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
			);
		}
		if ($wanted === []) {
			return [];
		}

		$after = $qb->expr()->gt('updated_at', $qb->createNamedParameter($afterAt, IQueryBuilder::PARAM_INT));
		if ($afterId !== null) {
			$after = $qb->expr()->orX($after, $qb->expr()->andX(
				$qb->expr()->eq('updated_at', $qb->createNamedParameter($afterAt, IQueryBuilder::PARAM_INT)),
				$qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)),
			));
		}

		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->orX(...$wanted))
			->andWhere($after)
			->orderBy('updated_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Moves the token of every row of the vehicle stamped in `[$since, $now)` to `$now`: what a
	 * long transaction wrote, re-stamped just before it commits, so a sync that ran meanwhile and
	 * passed those stamps by more than SyncService::SETTLE still finds them changed. The caller
	 * holds the vehicle since `$since`, so the rows are its own - but for one committed in that
	 * same second before the hold, whose next save is then refused as stale.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function restamp(int $vehicleId, int $since, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * The columns that name an account, which an erasure rewrites. Every table has `created_by`;
	 * a table with an account column of its own adds it.
	 *
	 * @return list<string>
	 */
	protected function accountColumns(): array {
		return ['created_by'];
	}

	/**
	 * Replaces an account's uid with a pseudonym wherever this table names it, deleted rows
	 * included (docs/adr/0008-erasing-a-driver-pseudonymises.md). `updated_at` stays: nobody
	 * edited the row, and a moved token would refuse every open client's next save.
	 *
	 * @return int how many rows it rewrote
	 * @throws \OCP\DB\Exception
	 */
	public function pseudonymise(string $uid, string $pseudonym): int {
		$changed = 0;
		foreach ($this->accountColumns() as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->tableName)
				->set($column, $qb->createNamedParameter($pseudonym))
				->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
			$changed += $qb->executeStatement();
		}

		return $changed;
	}

	/**
	 * Whether any row names the account, deleted ones included: one row fetched at most, so an
	 * erasure skips a table that has nothing to rewrite. Every large table indexes `created_by`;
	 * the other account columns sit on tables that stay small (vehicles, grants, recipients,
	 * receipts, bookings).
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function names(string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->orX(...$this->naming($qb, $uid)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Every row that names the account, deleted ones included: the rows pseudonymise() would
	 * rewrite, for the personal data export.
	 *
	 * @return list<T>
	 * @throws \OCP\DB\Exception
	 */
	public function findNaming(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->orX(...$this->naming($qb, $uid)))
			->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * findNaming()'s conditions, one per way a row names an account.
	 *
	 * @return list<string|\OCP\DB\QueryBuilder\ICompositeExpression>
	 */
	protected function naming(IQueryBuilder $qb, string $uid): array {
		return array_map(
			static fn (string $column): string => $qb->expr()->eq($column, $qb->createNamedParameter($uid)),
			$this->accountColumns(),
		);
	}

	/**
	 * Every account this table names that starts with `$prefix`, deleted rows included: what an
	 * old erasure left, for the upgrade to rename (ErasureService::renameOld()).
	 *
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	public function accountsStartingWith(string $prefix): array {
		$found = [];
		foreach ($this->accountColumns() as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct($column)
				->from($this->tableName)
				->where($qb->expr()->like($column, $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
			$found = [...$found, ...$this->accounts($qb)];
		}

		return array_values(array_unique($found));
	}

	/**
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	protected function accounts(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$accounts = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $accounts;
	}

	/**
	 * Some of one vehicle's rows by id, deleted ones too, keyed by id: a row that points at another
	 * by id names it on the wire by uuid whatever became of it, and an undo brings a deleted one
	 * back.
	 *
	 * @param list<int> $ids
	 * @return array<int, T>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyByIds(int $vehicleId, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere(InList::in($qb, 'id', $ids, IQueryBuilder::PARAM_INT_ARRAY));

		$byId = [];
		foreach ($this->findEntities($qb) as $row) {
			$byId[(int)$row->getId()] = $row;
		}

		return $byId;
	}

	/**
	 * How many of one vehicle's rows named by uuid are live.
	 *
	 * @param list<string> $uuids
	 * @throws \OCP\DB\Exception
	 */
	public function countLive(int $vehicleId, array $uuids): int {
		if ($uuids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere(InList::in($qb, 'uuid', $uuids, IQueryBuilder::PARAM_STR_ARRAY));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Whether any of one vehicle's rows, deleted ones too, has one of `$columns` set - one row
	 * fetched at most. A deleted row counts: an undo brings it back as it was.
	 *
	 * @param non-empty-list<string> $columns
	 * @throws \OCP\DB\Exception
	 */
	protected function anySet(int $vehicleId, array $columns): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...array_map(static fn (string $column) => $qb->expr()->isNotNull($column), $columns)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		try {
			return $result->fetchOne() !== false;
		} finally {
			$result->closeCursor();
		}
	}

	private function byUuid(string $uuid): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $qb;
	}

	/**
	 * Deleting is stamping `deleted_at`: the row stays for the trash and for a retention period
	 * to purge, and the write is checked like any other.
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
	 * Undo: the stamp taken off again, on the row the client read and only while it is still
	 * stamped. A statement that matched a live row would undo a delete nobody did.
	 *
	 * The token moves as on any write: a client that asks what changed since by `updated_at`
	 * would never see a restore that left it where the delete did. The entity carries the new
	 * one, and the restore's answer hands it on.
	 *
	 * @param T $entity
	 * @param int $expectedUpdatedAt the `updated_at` the delete answered with
	 * @return T
	 * @throws StaleUpdateException if the row has changed since, or was never deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restoreChecked(BaseEntity $entity, int $expectedUpdatedAt): BaseEntity {
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException('Entity which should be restored has no id');
		}

		$now = max($this->time->getTime(), $expectedUpdatedAt + 1);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq(
				'updated_at',
				$qb->createNamedParameter($expectedUpdatedAt, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->isNotNull('deleted_at'));

		if ($qb->executeStatement() === 0) {
			throw new StaleUpdateException(
				$this->tableName . ' row ' . $id . ' is not the deleted row that was read',
			);
		}

		$entity->setDeletedAt(null);
		$entity->setUpdatedAt($now);
		$entity->resetUpdatedFields();

		return $entity;
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
