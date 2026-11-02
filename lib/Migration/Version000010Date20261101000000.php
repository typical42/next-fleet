<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
// Docblock types only, for the reason Version000001Date20260101000000 gives.
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Whether drivers and viewers see what a vehicle costs (docs/architecture.md#access), who hears of
 * its bookings (docs/architecture.md#booking-notices), and whether a booking waits for an answer
 * (docs/architecture.md#booking-approval). Each defaults to what a vehicle did before it, so the
 * rows already there need no backfill.
 */
class Version000010Date20261101000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$vehicles = $schema->getTable('fleet_vehicles');

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover columns. Nullable, as Nextcloud requires of a boolean.
		if (!$vehicles->hasColumn('costs_shared')) {
			$vehicles->addColumn('costs_shared', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
		}
		// The events the list hears; null reads as Vehicle::DEFAULT_NOTICES.
		if (!$vehicles->hasColumn('booking_notices')) {
			$vehicles->addColumn('booking_notices', Types::JSON, ['notnull' => false]);
		}
		if (!$vehicles->hasColumn('booking_approval')) {
			$vehicles->addColumn('booking_approval', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		}
		$bookings = $schema->getTable('fleet_bookings');
		// Where an answer stands (Booking::REQUESTED and the rest): ASCII the code writes.
		if (!$bookings->hasColumn('approval')) {
			$bookings->addColumn('approval', Types::STRING, ['notnull' => false, 'length' => 16]);
		}
		// TEXT, as the handover's notes are, so Oracle needs no typed-text width.
		if (!$bookings->hasColumn('approval_comment')) {
			$bookings->addColumn('approval_comment', Types::TEXT, ['notnull' => false]);
		}
		if (!$bookings->hasColumn('approval_by')) {
			$bookings->addColumn('approval_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		// Booking::heard(): a booking from before reads as heard of by its null approval.
		if (!$bookings->hasColumn('announced')) {
			$bookings->addColumn('announced', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		}
		// Not `fleet_booking_notice_recipients`: Nextcloud refuses a table name over 27 characters.
		if (!$schema->hasTable('fleet_booking_recipients')) {
			$this->bookingRecipients($schema->createTable('fleet_booking_recipients'));
		}

		return $schema;
	}

	/**
	 * One user or one group per row, the other column null. No unique index: a null is distinct
	 * on some databases and not on others, so RecipientService::addBooking() keeps out doubles
	 * under the vehicle's hold.
	 *
	 * @param Table $table
	 */
	private function bookingRecipients($table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('group_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		// Named for the reason Version000004Date20260922000000::common() gives.
		$table->setPrimaryKey(['id'], 'fleet_brc_pk');
		$table->addUniqueIndex(['uuid'], 'fleet_brc_uuid_uniq');
		$table->addIndex(['vehicle_id'], 'fleet_brc_veh_idx');
	}
}
