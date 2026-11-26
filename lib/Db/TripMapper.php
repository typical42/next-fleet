<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends BaseMapper<Trip>
 */
class TripMapper extends BaseMapper {
	public function __construct(IDBConnection $db, ITimeFactory $time, ISecureRandom $random) {
		parent::__construct($db, $time, $random, 'fleet_trips', Trip::class);
	}

	/**
	 * Every read names the partner record, so every answer carries `partner_uuid`.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	protected function findEntities(IQueryBuilder $query): array {
		$trips = parent::findEntities($query);
		$this->namePartners($trips);

		return $trips;
	}

	/**
	 * @return Trip
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	protected function findEntity(IQueryBuilder $query): Entity {
		$trip = parent::findEntity($query);
		$this->namePartners([$trip]);

		return $trip;
	}

	/**
	 * Hangs on each trip the uuid of the record it links, one query per InList chunk. A live record
	 * only, as Trip::linkPartner() names one.
	 *
	 * @param list<Trip> $trips
	 * @throws \OCP\DB\Exception
	 */
	private function namePartners(array $trips): void {
		$byPartner = [];
		foreach ($trips as $trip) {
			if ($trip->getPartnerId() !== null) {
				$byPartner[$trip->getPartnerId()][] = $trip;
			}
		}

		foreach (InList::chunks(array_keys($byPartner)) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'uuid')
				->from('fleet_partners')
				->where(InList::in($qb, 'id', $chunk, IQueryBuilder::PARAM_INT_ARRAY))
				->andWhere($qb->expr()->isNull('deleted_at'));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				foreach ($byPartner[(int)$row['id']] as $trip) {
					$trip->setPartnerUuid((string)$row['uuid']);
				}
			}
			$result->closeCursor();
		}
	}

	/**
	 * One vehicle's trips in the order they happened, never the order they were entered, with
	 * `id` breaking a tie - the rule the readings follow
	 * (docs/architecture.md#odometer-rules), and what the index is on.
	 *
	 * A voided trip is soft-deleted, so not in it: the export reads findAnyStartedBetween().
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAllForVehicle(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('started_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One vehicle's trips that name a place, a purpose or a partner, the latest first - the history
	 * the sheet's trip fields complete from (docs/ui.md). Bounded for the reason
	 * EnergyMapper::findLatestAtStations() gives. Voided trips are not in it: a word on a trip
	 * nobody claims is not one to offer again.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findLatestDescribed(int $vehicleId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNotNull('from_label'),
				$qb->expr()->isNotNull('to_label'),
				$qb->expr()->isNotNull('purpose'),
				$qb->expr()->isNotNull('partner'),
			))
			->orderBy('started_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * One vehicle's live trip that set off last, whoever entered it: where the next one sets off.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findLatest(int $vehicleId): ?Trip {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('started_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * The live trip one person entered last on one vehicle - entered, not dated: the category they
	 * chose most recently. A Reconciliation Trip is not theirs to have chosen, so it is passed over.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function findLatestEnteredBy(int $vehicleId, string $userId): ?Trip {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('reconciled'),
				$qb->expr()->eq('reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
			))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * One vehicle's live trips that set off in `[from, to)`, oldest first: a period's driving.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findBetween(int $vehicleId, int $from, int $to): array {
		return $this->findEntities($this->between('started_at', $vehicleId, $from, $to));
	}

	/**
	 * @param list<int> $vehicleIds
	 * @throws \OCP\DB\Exception
	 */
	public function firstAmong(array $vehicleIds): ?int {
		return $this->earliest('started_at', $vehicleIds);
	}

	/**
	 * The trips of one vehicle that set off in `[from, to)`, oldest first as findAllForVehicle()
	 * orders them - voided ones included. This is the Fahrtenbuch export's own question
	 * (docs/architecture.md#logbook-mode): a voided trip is listed there, as voided.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyStartedBetween(int $vehicleId, int $from, int $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('started_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One vehicle's live trips whose span meets `(from, to)`: they set off before `to` and arrive
	 * after `from`. Ends that only touch do not meet - one trip arriving where the next sets off is
	 * how a vehicle is driven.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findOverlapping(int $vehicleId, int $from, int $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->lt('started_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('ended_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * One page of the timeline's trips, newest first (docs/architecture.md#the-timeline). A trip is
	 * dated by when it set off, which is the index the table carries and the date the row shows.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findBefore(int $vehicleId, int $at, int $id, int $limit): array {
		return $this->findEntities($this->pageBefore('started_at', $vehicleId, $at, $id, $limit));
	}

	/**
	 * Every trip that links the record, live and voided, on whichever vehicle, by vehicle and id:
	 * the order a merge or a delete holds the vehicles in.
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyLinking(int $partnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('partner_id', $qb->createNamedParameter($partnerId, IQueryBuilder::PARAM_INT)))
			->orderBy('vehicle_id')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Every trip of the vehicle that links a record in this column, live and voided, by id.
	 *
	 * @param 'partner_id'|'cost_centre_id' $column
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyLinkingOn(int $vehicleId, string $column = 'partner_id'): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull($column))
			->orderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * The live trips on these vehicles that link the record and set off in `[from, to)`, in the
	 * order they happened.
	 *
	 * @param list<int> $vehicleIds
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findLinkingStartedBetween(array $vehicleIds, int $partnerId, int $from, int $to): array {
		if ($vehicleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('partner_id', $qb->createNamedParameter($partnerId, IQueryBuilder::PARAM_INT)))
			->andWhere(InList::in($qb, 'vehicle_id', $vehicleIds, IQueryBuilder::PARAM_INT_ARRAY))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('started_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at')
			->addOrderBy('id');

		return $this->findEntities($qb);
	}

	/**
	 * Writes one of the trip's links and moves its token, in any state: a voided trip keeps its links
	 * too. Unchecked, as the caller read the row under the vehicle's hold (TripService::relink()).
	 * The other link is left as the row has it, so a write the caller did not see is not undone.
	 *
	 * @param 'partnerId'|'costCentreId' $link
	 * @throws \OCP\DB\Exception
	 */
	public function relink(Trip $trip, string $link): Trip {
		$trip->setUpdatedAt(max($this->time->getTime(), $trip->getUpdatedAt() + 1));
		$this->writeOnly($trip, [$link, 'updatedAt'])->executeStatement();
		$trip->resetUpdatedFields();

		return $trip;
	}

	/**
	 * One vehicle's trips, live and voided, whose partner text links no record (ADR 0013).
	 *
	 * @return list<Trip>
	 * @throws \OCP\DB\Exception
	 */
	public function findAnyUnlinkedPartners(int $vehicleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('vehicle_id', $qb->createNamedParameter($vehicleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('partner'))
			->andWhere($qb->expr()->isNull('partner_id'))
			->orderBy('id');

		// A text of blanks from before the service trimmed names nobody.
		return array_values(array_filter($this->findEntities($qb), static fn (Trip $trip): bool => Partner::nameOf((string)$trip->getPartner()) !== null));
	}

	/**
	 * The vehicles, in any state, with a trip findAnyUnlinkedPartners() may list. One read, so a
	 * repair with nothing to link costs no query per vehicle.
	 *
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	public function findVehiclesWithUnlinkedPartners(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('vehicle_id')
			->from($this->tableName)
			->where($qb->expr()->isNotNull('partner'))
			->andWhere($qb->expr()->isNull('partner_id'))
			->orderBy('vehicle_id');
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		return $ids;
	}
}
