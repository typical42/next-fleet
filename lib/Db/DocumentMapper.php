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
 * @template-extends BaseMapper<Document>
 */
class DocumentMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_documents', Document::class);
	}

	/**
	 * One vehicle's papers, in the order they were attached.
	 *
	 * @return list<Document>
	 * @throws \OCP\DB\Exception
	 */
	public function findByVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * The papers one entry carries. The vehicle is asked for too, so a link can never reach
	 * across to another vehicle's documents.
	 *
	 * @return list<Document>
	 * @throws \OCP\DB\Exception
	 */
	public function findLinked(int $vehicleId, string $linkedType, int $linkedId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('linked_type', $qb->createNamedParameter($linkedType)))
			->andWhere($qb->expr()->eq('linked_id', $qb->createNamedParameter($linkedId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');

		return $this->findEntities($qb);
	}
}
