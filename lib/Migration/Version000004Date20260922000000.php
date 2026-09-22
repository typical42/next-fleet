<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\ReminderRecipientMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What M4 ships: reminders, the receipts of what they sent, and who they send to
 * (docs/architecture.md#data-model). The milestone's only migration, for the reason M2's is.
 */
class Version000004Date20260922000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private ReminderRecipientMapper $recipients,
	) {
	}

	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover tables and columns.
		if (!$schema->hasTable('fleet_reminders')) {
			$this->reminders($this->common($schema->createTable('fleet_reminders'), null));
		}
		// Not `fleet_reminder_notifications`: Nextcloud refuses a table name over 27 characters.
		if (!$schema->hasTable('fleet_reminder_receipts')) {
			$this->receipts($this->common($schema->createTable('fleet_reminder_receipts'), 'fleet_rrc_pk'));
		}
		if (!$schema->hasTable('fleet_reminder_recipients')) {
			$this->recipients($this->common($schema->createTable('fleet_reminder_recipients'), 'fleet_rcp_pk'));
		}

		$maintenance = $schema->getTable('fleet_maintenance');
		if (!$maintenance->hasColumn('reminder_id')) {
			$maintenance->addColumn('reminder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// Editing or deleting a record has to find the reminder it closed, and back.
			$maintenance->addIndex(['reminder_id'], 'fleet_mnt_reminder_idx');
		}

		$vehicles = $schema->getTable('fleet_vehicles');
		if (!$vehicles->hasColumn('reminder_mail')) {
			// Not null with a default, so the vehicles already there take it without a backfill.
			$vehicles->addColumn('reminder_mail', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'weekly']);
		}

		return $schema;
	}

	/**
	 * Every vehicle without a recipient gets its owner, the list's starting point. Skipping the
	 * ones that have any keeps a re-run from adding a second row.
	 *
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.id', 'v.user_id')
			->from('fleet_vehicles', 'v')
			->leftJoin('v', 'fleet_reminder_recipients', 'r', $qb->expr()->eq('r.vehicle_id', 'v.id'))
			->where($qb->expr()->isNull('r.id'));
		$result = $qb->executeQuery();

		while (($row = $result->fetch()) !== false) {
			$owner = new ReminderRecipient();
			$owner->setVehicleId((int)$row['id']);
			$owner->setUserId((string)$row['user_id']);
			$owner->setCreatedBy((string)$row['user_id']);
			$this->recipients->insert($owner);
		}
		$result->closeCursor();
	}

	/**
	 * One thing a vehicle is due for, by date, by the main counter, or whichever comes first.
	 * A recurrence advances the same row and counts up `occurrence`.
	 */
	private function reminders(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		// Set for a template, whose title translates at read time; `title` holds a user's own.
		$table->addColumn('template_key', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 8]);
		// A plain calendar date, so no `_off`.
		$table->addColumn('due_date', Types::DATE, ['notnull' => false]);
		$table->addColumn('due_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('lead_odo', Types::BIGINT, ['notnull' => false]);
		// The warning points of a date reminder, nullable for the reason every boolean here is.
		$table->addColumn('warn_month_before', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
		$table->addColumn('warn_month_start', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('warn_due_date', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
		$table->addColumn('recur_months', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('recur_odo', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('snoozed_until', Types::DATE, ['notnull' => false]);
		$table->addColumn('occurrence', Types::INTEGER, ['notnull' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_rem_uuid_uniq');
		$table->addIndex(['vehicle_id'], 'fleet_rem_veh_idx');
	}

	/**
	 * What was sent, when, to whom and on which channel - `app` or `mail` - so one channel
	 * failing leaves the other's record alone.
	 */
	private function receipts(Table $table): void {
		$table->addColumn('reminder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('occurrence', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('point', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 8]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('sent_at', Types::BIGINT, ['notnull' => true]);

		$table->addUniqueIndex(['uuid'], 'fleet_rrc_uuid_uniq');
		// "Each point sends once", stated by the database: two overlapping runs of the job cannot
		// both write the receipt.
		$table->addUniqueIndex(['reminder_id', 'occurrence', 'point', 'channel', 'user_id'], 'fleet_rrc_once_uniq');
		// The digest asks when a recipient was last mailed.
		$table->addIndex(['user_id', 'channel', 'sent_at'], 'fleet_rrc_user_sent_idx');
	}

	/**
	 * Who a vehicle's reminders go to. The recipient service revives a removed row rather than
	 * inserting a second one, which the unique index would refuse.
	 */
	private function recipients(Table $table): void {
		$table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);

		$table->addUniqueIndex(['uuid'], 'fleet_rcp_uuid_uniq');
		$table->addUniqueIndex(['vehicle_id', 'user_id'], 'fleet_rcp_veh_user_uniq');
	}

	/**
	 * The row every table has. Copied, not shared, for the reason M2's copy gives. A table name
	 * of 23 characters or more needs its key named: PostgreSQL derives the default from the
	 * table, and Nextcloud refuses what would come out too long.
	 */
	private function common(Table $table, ?string $primaryKey): Table {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->setPrimaryKey(['id'], $primaryKey ?? false);

		return $table;
	}
}
