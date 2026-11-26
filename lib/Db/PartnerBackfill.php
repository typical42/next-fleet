<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCA\NextFleet\Service\SyncEpoch;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Links every trip whose partner text has no record yet: one record per owner and name
 * (Partner::nameOf()), live and voided trips alike (ADR 0013).
 *
 * The trip's text and `updated_at` stay: LogbookExport reads a token newer than the trip's last
 * audit row as a change nobody recorded. So no cursor sees the new links, and the epoch starts
 * every client's sync over instead.
 *
 * Written with the query builder, not the entities: the step that runs it must still run after a
 * later one has added columns to the entity.
 */
class PartnerBackfill {
	public function __construct(
		private IDBConnection $db,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private SyncEpoch $epoch,
	) {
	}

	/**
	 * A run that died half-way left records without their trips; the next one finds them by name.
	 * A run with nothing left to link writes nothing. The epoch moves before the first link, so a
	 * run that dies after its last one has moved it already.
	 *
	 * @return int the trips it linked
	 * @throws \OCP\DB\Exception
	 */
	public function run(): int {
		$unlinked = $this->unlinked();
		if ($unlinked === []) {
			return 0;
		}
		$this->epoch->reset();

		$linked = 0;
		$records = [];
		foreach ($unlinked as ['owner' => $owner, 'name' => $name, 'trips' => $tripIds]) {
			$records[$owner] ??= $this->records($owner);
			$id = $records[$owner][$name] ?? $this->create($owner, $name);
			foreach (InList::chunks($tripIds) as $chunk) {
				$linked += $this->link($id, $chunk);
			}
		}

		return $linked;
	}

	/**
	 * The trips to link, by the owner of their vehicle and the name their text stands for. A trip
	 * of a vehicle that is gone has no owner to file it under; `nextfleet:check` reports the row.
	 *
	 * @return list<array{owner: string, name: string, trips: non-empty-list<int>}>
	 * @throws \OCP\DB\Exception
	 */
	private function unlinked(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.partner', 'v.user_id')
			->from('fleet_trips', 't')
			->innerJoin('t', 'fleet_vehicles', 'v', $qb->expr()->eq('v.id', 't.vehicle_id'))
			->where($qb->expr()->isNotNull('t.partner'))
			->andWhere($qb->expr()->isNull('t.partner_id'))
			->orderBy('t.id');
		$result = $qb->executeQuery();
		$groups = [];
		while (($row = $result->fetch()) !== false) {
			$owner = (string)$row['user_id'];
			$name = Partner::nameOf((string)$row['partner']);
			if ($name !== null) {
				// A key PHP cannot take for a number, as it would `4711`.
				$groups[$owner . "\0" . $name] ??= ['owner' => $owner, 'name' => $name, 'trips' => []];
				$groups[$owner . "\0" . $name]['trips'][] = (int)$row['id'];
			}
		}
		$result->closeCursor();

		return array_values($groups);
	}

	/**
	 * The owner's live records by name.
	 *
	 * @return array<string, int>
	 * @throws \OCP\DB\Exception
	 */
	private function records(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('fleet_partners')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id');
		$result = $qb->executeQuery();
		$records = [];
		while (($row = $result->fetch()) !== false) {
			$records[(string)$row['name']] ??= (int)$row['id'];
		}
		$result->closeCursor();

		return $records;
	}

	/**
	 * A record in the owner's list, written by them: the list is theirs, whoever typed the text.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function create(string $owner, string $name): int {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('fleet_partners')
			->values([
				'uuid' => $qb->createNamedParameter(BaseMapper::newUuid($this->random)),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'created_by' => $qb->createNamedParameter($owner),
				'user_id' => $qb->createNamedParameter($owner),
				'name' => $qb->createNamedParameter($name),
			]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * @param non-empty-list<int> $tripIds
	 * @throws \OCP\DB\Exception
	 */
	private function link(int $partnerId, array $tripIds): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('fleet_trips')
			->set('partner_id', $qb->createNamedParameter($partnerId, IQueryBuilder::PARAM_INT))
			->where(InList::in($qb, 'id', $tripIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->isNull('partner_id'));

		return $qb->executeStatement();
	}
}
