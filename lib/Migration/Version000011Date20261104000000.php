<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use OCA\NextFleet\Db\Vehicle;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A vehicle's bookings wait for an answer always, never, or as its owner's default says
 * (docs/architecture.md#booking-approval). `booking_approval` keeps true for always and false for
 * never; null, now its default, follows the owner. A vehicle that asked keeps asking. One that did
 * not now follows its owner, whose default starts off, so nobody sees a change.
 *
 * A grant's `take_now` says whether a driver's Take it now skips approval
 * (docs/architecture.md#booking-approval). Every grant allows it.
 *
 * Each booking recipient hears its own events (docs/architecture.md#booking-notices). A row starts
 * with the events its vehicle's list heard, so nobody hears more or less than before.
 *
 * A booking's `seen_at` says when its booker confirmed a booking that an approver made for them
 * (docs/architecture.md#booking-for-a-driver). No booking made before has one to confirm.
 */
class Version000011Date20261104000000 extends SimpleMigrationStep {
	/**
	 * Whether this run moved the default, and so meets rows written under the old one. A re-install
	 * meets a column already moved, whose false is somebody's "never".
	 */
	private bool $backfill = false;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$approval = $schema->getTable('fleet_vehicles')->getColumn('booking_approval');
		if ($approval->getDefault() !== null) {
			$approval->setDefault(null);
			$this->backfill = true;
		}
		$access = $schema->getTable('fleet_access');
		if (!$access->hasColumn('take_now')) {
			$access->addColumn('take_now', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
		}
		// A JSON column takes no default; null reads as the vehicle's events (BookingRecipient::events()).
		$recipients = $schema->getTable('fleet_booking_recipients');
		if (!$recipients->hasColumn('events')) {
			$recipients->addColumn('events', Types::JSON, ['notnull' => false]);
		}
		$bookings = $schema->getTable('fleet_bookings');
		if (!$bookings->hasColumn('seen_at')) {
			$bookings->addColumn('seen_at', Types::BIGINT, ['notnull' => false]);
		}

		return $schema;
	}

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->backfill) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('fleet_vehicles')
				->set('booking_approval', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->eq('booking_approval', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
			$qb->executeStatement();
		}
		$this->copyEvents();
	}

	/**
	 * Copied rather than left null: the vehicle's events stay those of the empty list, which the
	 * owner changes without changing any row. Row by row, as a JSON column is a CLOB on Oracle.
	 * Every run copies, as a run that died after its schema change left rows without events; a
	 * row written since holds its own, so the null guard is enough.
	 */
	private function copyEvents(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'v.booking_notices')
			->from('fleet_booking_recipients', 'r')
			->innerJoin('r', 'fleet_vehicles', 'v', $qb->expr()->eq('v.id', 'r.vehicle_id'))
			->where($qb->expr()->isNull('r.events'));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$update = $this->db->getQueryBuilder();
			$update->update('fleet_booking_recipients')
				->set('events', $update->createNamedParameter($row['booking_notices'] ?? json_encode(Vehicle::DEFAULT_NOTICES)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
	}
}
