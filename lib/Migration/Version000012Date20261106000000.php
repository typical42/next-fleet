<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What a maintenance record's close of a reminder replaced and set, so taking the close back
 * returns the reminder to its planned due, and the due each reminder receipt was sent for
 * (docs/architecture.md#reminder-engine). No backfill: what an older record closed, or which due
 * an older receipt was sent for, is not known. So their columns stay null: a null occurrence sends
 * a record to the fallback of its own day and km, and a receipt without a due counts for any due.
 */
class Version000012Date20261106000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$maintenance = $schema->getTable('fleet_maintenance');

		// Guarded for the reason the first migration is guarded: a re-install meets its own
		// leftover columns. Each kept value, and each due on the receipts, is typed as the
		// reminder's column it copies.
		if (!$maintenance->hasColumn('closed_due_date')) {
			$maintenance->addColumn('closed_due_date', Types::DATE, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('closed_due_odo')) {
			$maintenance->addColumn('closed_due_odo', Types::BIGINT, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('closed_snoozed_until')) {
			$maintenance->addColumn('closed_snoozed_until', Types::DATE, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('closed_occurrence')) {
			$maintenance->addColumn('closed_occurrence', Types::INTEGER, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('next_snoozed_until')) {
			$maintenance->addColumn('next_snoozed_until', Types::DATE, ['notnull' => false]);
		}
		// An id, as `reminder_id` is.
		if (!$maintenance->hasColumn('handed_to')) {
			$maintenance->addColumn('handed_to', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		if (!$maintenance->hasColumn('kept_occurrence')) {
			$maintenance->addColumn('kept_occurrence', Types::INTEGER, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('next_due_date')) {
			$maintenance->addColumn('next_due_date', Types::DATE, ['notnull' => false]);
		}
		if (!$maintenance->hasColumn('next_due_odo')) {
			$maintenance->addColumn('next_due_odo', Types::BIGINT, ['notnull' => false]);
		}
		// Nullable, as Nextcloud requires of a boolean.
		if (!$maintenance->hasColumn('took_back')) {
			$maintenance->addColumn('took_back', Types::BOOLEAN, ['notnull' => false]);
		}

		$receipts = $schema->getTable('fleet_reminder_receipts');
		if (!$receipts->hasColumn('due_date')) {
			$receipts->addColumn('due_date', Types::DATE, ['notnull' => false]);
		}
		if (!$receipts->hasColumn('due_odo')) {
			$receipts->addColumn('due_odo', Types::BIGINT, ['notnull' => false]);
		}

		return $schema;
	}
}
